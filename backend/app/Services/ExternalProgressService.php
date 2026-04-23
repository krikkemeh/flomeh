<?php

namespace App\Services;

use App\Episode;
use App\ExternalProgressEvent;
use App\ExternalProgressOverride;
use App\Item;
use App\Services\Models\EpisodeService;
use App\Services\Models\ItemService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ExternalProgressService
{
    private $events;
    private $overrides;
    private $items;
    private $episodes;
    private $tmdb;
    private $itemService;
    private $episodeService;

    public function __construct(
      ExternalProgressEvent $events,
      ExternalProgressOverride $overrides,
      Item $items,
      Episode $episodes,
      TMDB $tmdb,
      ItemService $itemService,
      EpisodeService $episodeService
    ) {
      $this->events = $events;
      $this->overrides = $overrides;
      $this->items = $items;
      $this->episodes = $episodes;
      $this->tmdb = $tmdb;
      $this->itemService = $itemService;
      $this->episodeService = $episodeService;
    }

    public function ingest($payload)
    {
      $event = $this->createEvent($payload, 'received', 'External progress received');

      try {
        $payload = $this->applyOverride($payload);

        if(empty($payload['markSeen'])) {
          return $this->finish(
            $event,
            'ignored_by_client',
            'Ignored because the client decided not to mark this episode as seen',
            [
              'ok' => true,
              'matched' => false,
              'updatedEpisodes' => 0,
              'message' => 'Client decided not to mark this episode as seen',
            ]
          );
        }

        $match = $this->matchItem($payload);

        if($match['status'] === 'ambiguous') {
          return $this->finish(
            $event,
            'ambiguous',
            $match['message'],
            [
              'ok' => false,
              'matched' => false,
              'updatedEpisodes' => 0,
              'message' => $match['message'],
              'httpStatus' => Response::HTTP_CONFLICT,
            ]
          );
        }

        if($match['status'] === 'not_found') {
          return $this->finish(
            $event,
            'not_found',
            $match['message'],
            [
              'ok' => false,
              'matched' => false,
              'updatedEpisodes' => 0,
              'message' => $match['message'],
              'httpStatus' => Response::HTTP_NOT_FOUND,
            ]
          );
        }

        /** @var Item $item */
        $item = $match['item'];

        if($item->media_type !== 'tv') {
          return $this->finish(
            $event,
            'invalid_media_type',
            'Matched item is not a TV show',
            [
              'ok' => false,
              'matched' => false,
              'updatedEpisodes' => 0,
              'message' => 'Matched item is not a TV show',
              'httpStatus' => Response::HTTP_UNPROCESSABLE_ENTITY,
            ],
            $item
          );
        }

        $this->ensureEpisodeExists($item, $payload['season'], $payload['episode']);

        $updatedEpisodes = DB::transaction(function() use ($item, $payload) {
          $updated = $this->itemService->markImportedEpisodesAsSeen(
            $item->tmdb_id,
            $payload['season'],
            $payload['episode']
          );

          $this->items->updateLastSeenAt($item->tmdb_id);

          return $updated;
        });

        $latestSeen = $this->latestSeenEpisode($item->tmdb_id);
        $isNoop = ! $updatedEpisodes && $latestSeen
          && $this->episodeIsAtOrAfter($latestSeen, $payload['season'], $payload['episode']);

        return $this->finish(
          $event,
          $isNoop ? 'noop_already_seen' : ($match['created'] ? 'created_and_updated' : 'updated'),
          $isNoop ? 'Episode progress was already present' : 'Episode marked as seen',
          [
            'ok' => true,
            'matched' => true,
            'createdItem' => $match['created'],
            'itemId' => $item->id,
            'tmdbId' => $item->tmdb_id,
            'updatedEpisodes' => $updatedEpisodes,
            'message' => $isNoop ? 'Episode was already seen in Flomeh' : 'Episode marked as seen',
          ],
          $item
        );
      } catch(\Exception $e) {
        logInfo('External progress ingest failed', [$e->getMessage()]);

        return $this->finish(
          $event,
          'error',
          $e->getMessage(),
          [
            'ok' => false,
            'matched' => false,
            'updatedEpisodes' => 0,
            'message' => 'External progress sync failed',
            'httpStatus' => Response::HTTP_INTERNAL_SERVER_ERROR,
          ]
        );
      }
    }

    public function lookupNextEpisode($payload)
    {
      try {
        $payload = $this->applyOverride($payload);
        $match = $this->matchExistingItem($payload);

        if($match['status'] === 'ambiguous') {
          return [
            'ok' => false,
            'matched' => false,
            'message' => $match['message'],
            'httpStatus' => Response::HTTP_CONFLICT,
          ];
        }

        if($match['status'] === 'not_found') {
          return [
            'ok' => true,
            'matched' => false,
            'message' => $match['message'],
            'httpStatus' => Response::HTTP_OK,
          ];
        }

        /** @var Item $item */
        $item = $match['item'];

        if($item->media_type !== 'tv') {
          return [
            'ok' => false,
            'matched' => false,
            'message' => 'Matched item is not a TV show',
            'httpStatus' => Response::HTTP_UNPROCESSABLE_ENTITY,
          ];
        }

        $nextEpisode = $item->latestEpisode()->first();

        return [
          'ok' => true,
          'matched' => true,
          'itemId' => $item->id,
          'tmdbId' => $item->tmdb_id,
          'seriesTitle' => $item->title,
          'nextSeason' => $nextEpisode->season_number ?? null,
          'nextEpisode' => $nextEpisode->episode_number ?? null,
          'message' => $nextEpisode
            ? 'Next episode found'
            : 'All tracked episodes are already seen',
          'httpStatus' => Response::HTTP_OK,
        ];
      } catch(\Exception $e) {
        logInfo('External next episode lookup failed', [$e->getMessage()]);

        return [
          'ok' => false,
          'matched' => false,
          'message' => 'External next episode lookup failed',
          'httpStatus' => Response::HTTP_INTERNAL_SERVER_ERROR,
        ];
      }
    }

    private function matchItem($payload)
    {
      if( ! empty($payload['tmdbId'])) {
        $item = $this->items->findByTmdbIdStrict($payload['tmdbId'], 'tv')->first();

        if($item) {
          return [
            'status' => 'matched',
            'item' => $item,
            'created' => false,
            'message' => 'Matched by TMDb ID',
          ];
        }

        $createdByTmdbId = $this->findOrCreateTvItemByTmdbId($payload['tmdbId']);

        if($createdByTmdbId) {
          return [
            'status' => 'matched',
            'item' => $createdByTmdbId,
            'created' => true,
            'message' => 'Created TV show from TMDb ID',
          ];
        }
      }

      $localMatches = $this->findLocalExactMatches($payload['seriesTitle']);

      if($localMatches->count() === 1) {
        return [
          'status' => 'matched',
          'item' => $localMatches->first(),
          'created' => false,
          'message' => 'Matched by local title',
        ];
      }

      if($localMatches->count() > 1) {
        return [
          'status' => 'ambiguous',
          'message' => 'More than one local TV show matches this title',
        ];
      }

      $preferredLocalMatch = $this->findPreferredLocalMatch($payload['seriesTitle']);

      if($preferredLocalMatch) {
        return [
          'status' => 'matched',
          'item' => $preferredLocalMatch,
          'created' => false,
          'message' => 'Matched by preferred local title',
        ];
      }

      return $this->matchViaTmdb($payload);
    }

    private function matchExistingItem($payload)
    {
      if( ! empty($payload['tmdbId'])) {
        $item = $this->items->findByTmdbIdStrict($payload['tmdbId'], 'tv')->first();

        if($item) {
          return [
            'status' => 'matched',
            'item' => $item,
            'created' => false,
            'message' => 'Matched by TMDb ID',
          ];
        }
      }

      $localMatches = $this->findLocalExactMatches($payload['seriesTitle']);

      if($localMatches->count() === 1) {
        return [
          'status' => 'matched',
          'item' => $localMatches->first(),
          'created' => false,
          'message' => 'Matched by local title',
        ];
      }

      if($localMatches->count() > 1) {
        return [
          'status' => 'ambiguous',
          'message' => 'More than one local TV show matches this title',
        ];
      }

      $preferredLocalMatch = $this->findPreferredLocalMatch($payload['seriesTitle']);

      if($preferredLocalMatch) {
        return [
          'status' => 'matched',
          'item' => $preferredLocalMatch,
          'created' => false,
          'message' => 'Matched by preferred local title',
        ];
      }

      return $this->matchExistingViaTmdb($payload);
    }

    public function createOverrideForEvent($event, $data)
    {
      if(empty($event->series_title) || empty($event->source)) {
        return [
          'ok' => false,
          'message' => 'This log row does not contain enough data to create an override.',
          'httpStatus' => Response::HTTP_UNPROCESSABLE_ENTITY,
        ];
      }

      $familyTitle = trim((string) ($data['family_title'] ?? ''));
      $familyTitle = $familyTitle !== '' ? $familyTitle : $this->deriveFamilyTitle($event->series_title);
      $normalizedFamilyTitle = $this->normalizeTitle($familyTitle);

      $override = $this->overrides->updateOrCreate([
        'source' => $event->source,
        'normalized_family_series_title' => $normalizedFamilyTitle,
      ], [
        'external_series_title' => $event->series_title,
        'normalized_external_series_title' => $this->normalizeTitle($event->series_title),
        'tmdb_id' => (int) $data['tmdb_id'],
        'family_series_title' => $familyTitle,
        'normalized_family_series_title' => $normalizedFamilyTitle,
        'force_season' => isset($data['force_season']) && $data['force_season'] !== null ? (int) $data['force_season'] : null,
        'episode_shift' => (int) ($data['episode_shift'] ?? 0),
      ]);

      $payload = json_decode($event->payload, true);

      if( ! is_array($payload)) {
        return [
          'ok' => false,
          'message' => 'Override saved, but the original payload could not be reprocessed.',
          'override' => $override,
          'httpStatus' => Response::HTTP_OK,
        ];
      }

      $retry = $this->ingest($payload);
      $reapplied = $this->reapplyOverrideToMatchingEvents($override, $event->id);

      return [
        'ok' => ! empty($retry['ok']),
        'message' => ! empty($retry['ok']) ? 'Override saved and sync retried.' : 'Override saved, but retry still failed.',
        'override' => $override,
        'retry' => $retry,
        'reappliedCount' => $reapplied,
        'httpStatus' => ! empty($retry['ok']) ? Response::HTTP_OK : ($retry['httpStatus'] ?? Response::HTTP_OK),
      ];
    }

    public function findOverrideForSeries($source, $seriesTitle)
    {
      if(empty($source) || empty($seriesTitle)) {
        return null;
      }

      return $this->findOverrideByTitle($source, $seriesTitle);
    }

    public function deriveFamilyTitle($title)
    {
      $title = trim((string) $title);

      if($title === '') {
        return $title;
      }

      $familyTitle = preg_replace('/\s*[-:]\s*(parte|part|cour|stagione|season)\s+\d+\s*$/iu', '', $title);
      $familyTitle = preg_replace('/\s+(parte|part|cour|stagione|season)\s+\d+\s*$/iu', '', $familyTitle);
      $familyTitle = preg_replace('/\s+\d+\s*$/u', '', $familyTitle);
      $familyTitle = trim((string) $familyTitle, " \t\n\r\0\x0B-:");

      return $familyTitle !== '' ? $familyTitle : $title;
    }

    private function matchViaTmdb($payload)
    {
      $results = collect($this->tmdb->search($payload['seriesTitle'], 'tv'));
      $exactMatches = $results->filter(function($result) use ($payload) {
        return $this->normalizedEquals($result['title'] ?? null, $payload['seriesTitle'])
          || $this->normalizedEquals($result['original_title'] ?? null, $payload['seriesTitle']);
      })->values();

      if($exactMatches->count() > 1) {
        $existingLocalMatch = $this->findExistingLocalMatchFromTmdbResults($exactMatches);

        if($existingLocalMatch) {
          return [
            'status' => 'matched',
            'item' => $existingLocalMatch,
            'created' => false,
            'message' => 'Matched by existing local show from TMDb candidates',
          ];
        }

        return [
          'status' => 'ambiguous',
          'message' => 'TMDb returned more than one exact match for this title',
        ];
      }

      if($exactMatches->isEmpty()) {
        return [
          'status' => 'not_found',
          'message' => 'No local or TMDb TV show match found for this title',
        ];
      }

      $tmdbMatch = $exactMatches->first();
      $existing = $this->items->findByTmdbIdStrict($tmdbMatch['tmdb_id'], 'tv')->first();

      if($existing) {
        return [
          'status' => 'matched',
          'item' => $existing,
          'created' => false,
          'message' => 'Matched by TMDb search',
        ];
      }

      $created = $this->itemService->create($tmdbMatch);

      return [
        'status' => 'matched',
        'item' => $created,
        'created' => true,
        'message' => 'Created TV show from TMDb search',
      ];
    }

    private function matchExistingViaTmdb($payload)
    {
      $results = collect($this->tmdb->search($payload['seriesTitle'], 'tv'));
      $exactMatches = $results->filter(function($result) use ($payload) {
        return $this->normalizedEquals($result['title'] ?? null, $payload['seriesTitle'])
          || $this->normalizedEquals($result['original_title'] ?? null, $payload['seriesTitle']);
      })->values();

      if($exactMatches->count() > 1) {
        $existingLocalMatch = $this->findExistingLocalMatchFromTmdbResults($exactMatches);

        if($existingLocalMatch) {
          return [
            'status' => 'matched',
            'item' => $existingLocalMatch,
            'created' => false,
            'message' => 'Matched by existing local show from TMDb candidates',
          ];
        }

        return [
          'status' => 'ambiguous',
          'message' => 'TMDb returned more than one exact match for this title',
        ];
      }

      if($exactMatches->isEmpty()) {
        return [
          'status' => 'not_found',
          'message' => 'No local TV show match found for this title',
        ];
      }

      $tmdbMatch = $exactMatches->first();
      $existing = $this->items->findByTmdbIdStrict($tmdbMatch['tmdb_id'], 'tv')->first();

      if($existing) {
        return [
          'status' => 'matched',
          'item' => $existing,
          'created' => false,
          'message' => 'Matched by TMDb search',
        ];
      }

      return [
        'status' => 'not_found',
        'message' => 'No local TV show match found for this title',
      ];
    }

    private function findLocalExactMatches($title)
    {
      $normalizedTitle = $this->normalizeTitle($title);

      return $this->items->where('media_type', 'tv')
        ->where(function($query) use ($normalizedTitle) {
          $query->whereRaw('lower(trim(title)) = ?', [$normalizedTitle])
            ->orWhereRaw('lower(trim(original_title)) = ?', [$normalizedTitle])
            ->orWhereHas('alternativeTitles', function($query) use ($normalizedTitle) {
              $query->whereRaw('lower(trim(title)) = ?', [$normalizedTitle]);
            });
        })
        ->get();
    }

    private function findPreferredLocalMatch($title)
    {
      $matches = $this->items->findByTitle($title, 'tv')->get()->unique('id')->values();

      if($matches->count() === 1) {
        return $matches->first();
      }

      if($matches->count() <= 1) {
        return null;
      }

      $activeMatches = $matches->filter(function($item) {
        return $this->isTrackedLocalShow($item);
      })->values();

      if($activeMatches->count() === 1) {
        return $activeMatches->first();
      }

      return null;
    }

    private function findExistingLocalMatchFromTmdbResults($exactMatches)
    {
      $existingIds = $exactMatches
        ->pluck('tmdb_id')
        ->filter()
        ->unique()
        ->values();

      if($existingIds->isEmpty()) {
        return null;
      }

      $existingMatches = $this->items->where('media_type', 'tv')
        ->whereIn('tmdb_id', $existingIds)
        ->get()
        ->unique('id')
        ->values();

      if($existingMatches->count() === 1) {
        return $existingMatches->first();
      }

      $trackedMatches = $existingMatches->filter(function($item) {
        return $this->isTrackedLocalShow($item);
      })->values();

      if($trackedMatches->count() === 1) {
        return $trackedMatches->first();
      }

      return null;
    }

    private function isTrackedLocalShow($item)
    {
      if($item->watchlist || ! empty($item->last_seen_at)) {
        return true;
      }

      return $item->episodes()->where('seen', true)->exists();
    }

    private function ensureEpisodeExists($item, $season, $episode)
    {
      $exists = $this->episodes->findByTmdbId($item->tmdb_id)
        ->where('season_number', $season)
        ->where('episode_number', $episode)
        ->exists();

      if( ! $exists) {
        $this->episodeService->create($item);
      }
    }

    private function latestSeenEpisode($tmdbId)
    {
      return $this->episodes->findByTmdbId($tmdbId)
        ->where('seen', true)
        ->orderBy('season_number', 'desc')
        ->orderBy('episode_number', 'desc')
        ->first();
    }

    private function episodeIsAtOrAfter($latestSeen, $season, $episode)
    {
      if($latestSeen->season_number > $season) {
        return true;
      }

      return $latestSeen->season_number == $season && $latestSeen->episode_number >= $episode;
    }

    private function createEvent($payload, $status, $message)
    {
      return $this->events->create([
        'source' => $payload['source'],
        'status' => $status,
        'message' => $message,
        'tmdb_id' => $payload['tmdbId'] ?? null,
        'series_title' => $payload['seriesTitle'] ?? null,
        'season_number' => $payload['season'] ?? null,
        'episode_number' => $payload['episode'] ?? null,
        'completed' => ! empty($payload['completed']) || ((int) round($payload['progress'] ?? 0) >= 100),
        'progress' => isset($payload['progress']) ? (int) round($payload['progress']) : null,
        'playback_id' => $payload['playbackId'] ?? null,
        'episode_title' => $payload['episodeTitle'] ?? null,
        'href' => $payload['href'] ?? null,
        'payload' => json_encode($payload),
      ]);
    }

    private function finish($event, $status, $message, $response, $item = null)
    {
      $event->update([
        'status' => $status,
        'message' => $message,
        'item_id' => $item->id ?? null,
        'tmdb_id' => $item->tmdb_id ?? $event->tmdb_id,
      ]);

      return $response;
    }

    private function applyOverride($payload)
    {
      if(empty($payload['source']) || empty($payload['seriesTitle'])) {
        return $payload;
      }

      $override = $this->findOverrideByTitle($payload['source'], $payload['seriesTitle']);

      if( ! $override) {
        return $payload;
      }

      $payload['tmdbId'] = $override->tmdb_id;

      if( ! empty($override->force_season)) {
        $payload['season'] = (int) $override->force_season;
      }

      $shiftedEpisode = (int) ($payload['episode'] ?? 0) + (int) $override->episode_shift;
      $payload['episode'] = max(1, $shiftedEpisode);

      return $payload;
    }

    private function findOverrideByTitle($source, $seriesTitle)
    {
      $normalizedTitle = $this->normalizeTitle($seriesTitle);
      $normalizedFamilyTitle = $this->normalizeTitle($this->deriveFamilyTitle($seriesTitle));

      return $this->overrides
        ->where('source', $source)
        ->where(function($query) use ($normalizedTitle, $normalizedFamilyTitle) {
          $query->where('normalized_external_series_title', $normalizedTitle)
            ->orWhere('normalized_family_series_title', $normalizedFamilyTitle);
        })
        ->orderByRaw('case when normalized_external_series_title = ? then 0 else 1 end', [$normalizedTitle])
        ->orderBy('id', 'desc')
        ->first();
    }

    private function reapplyOverrideToMatchingEvents($override, $excludedEventId = null)
    {
      if( ! $override || empty($override->source) || empty($override->normalized_family_series_title)) {
        return 0;
      }

      $reapplied = 0;

      $events = $this->events
        ->where('source', $override->source)
        ->whereIn('status', ['not_found', 'ambiguous', 'invalid_media_type'])
        ->orderBy('id', 'desc')
        ->get();

      foreach($events as $event) {
        if($excludedEventId && (int) $event->id === (int) $excludedEventId) {
          continue;
        }

        if($this->normalizeTitle($this->deriveFamilyTitle($event->series_title)) !== $override->normalized_family_series_title) {
          continue;
        }

        $payload = json_decode($event->payload, true);

        if( ! is_array($payload)) {
          continue;
        }

        $this->ingest($payload);
        $reapplied++;
      }

      return $reapplied;
    }

    private function findOrCreateTvItemByTmdbId($tmdbId)
    {
      $tmdbId = (int) $tmdbId;

      if($tmdbId <= 0) {
        return null;
      }

      $existing = $this->items->findByTmdbIdStrict($tmdbId, 'tv')->first();

      if($existing) {
        return $existing;
      }

      $details = $this->tmdb->details($tmdbId, 'tv');
      $title = $details->name ?? null;

      if( ! $title) {
        return null;
      }

      $releaseDate = $details->first_air_date ?? Item::FALLBACK_DATE;

      try {
        $released = Carbon::createFromFormat('Y-m-d', $releaseDate ?: Item::FALLBACK_DATE)->getTimestamp();
      } catch(\Exception $e) {
        $released = Carbon::createFromFormat('Y-m-d', Item::FALLBACK_DATE)->getTimestamp();
      }

      return $this->itemService->create([
        'tmdb_id' => $tmdbId,
        'media_type' => 'tv',
        'title' => $title,
        'original_title' => $details->original_name ?? $title,
        'poster' => $details->poster_path ?? '',
        'released' => $released,
        'genre_ids' => isset($details->genres) ? collect($details->genres)->pluck('id')->all() : [],
      ]);
    }

    private function normalizeTitle($title)
    {
      $title = trim(Str::lower((string) $title));
      $title = preg_replace('/\s+/u', ' ', $title);

      return $title;
    }

    private function normalizedEquals($left, $right)
    {
      if($left === null || $right === null) {
        return false;
      }

      return $this->normalizeTitle($left) === $this->normalizeTitle($right);
    }
}
