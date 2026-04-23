<?php

  namespace App\Services\Models;

  use App\Item as Model;
  use App\Item;
  use App\Services\IMDB;
  use App\Services\Storage;
  use App\Services\TMDB;
  use App\Jobs\UpdateItem;
  use App\Setting;
  use Carbon\Carbon;
  use Illuminate\Support\Facades\DB;
  use Symfony\Component\HttpFoundation\Response;

  class ItemService {

    private $model;
    private $tmdb;
    private $storage;
    private $alternativeTitleService;
    private $episodeService;
    private $imdb;
    private $setting;
    private $genreService;

    /**
     * @param Model $model
     * @param TMDB $tmdb
     * @param Storage $storage
     * @param AlternativeTitleService $alternativeTitleService
     * @param EpisodeService $episodeService
     * @param GenreService $genreService
     * @param IMDB $imdb
     * @param Setting $setting
     */
    public function __construct(
      Model $model,
      TMDB $tmdb,
      Storage $storage,
      AlternativeTitleService $alternativeTitleService,
      EpisodeService $episodeService,
      GenreService $genreService,
      IMDB $imdb,
      Setting $setting
    ){
      $this->model = $model;
      $this->tmdb = $tmdb;
      $this->storage = $storage;
      $this->alternativeTitleService = $alternativeTitleService;
      $this->episodeService = $episodeService;
      $this->imdb = $imdb;
      $this->setting = $setting;
      $this->genreService = $genreService;
    }

    /**
     * @param $data
     * @return Model
     */
    public function create($data)
    {
      DB::beginTransaction();

      $data = $this->makeDataComplete($data);

      $item = $this->model->store($data);

      $this->episodeService->create($item);
      $this->genreService->sync($item, $data['genre_ids'] ?? []);
      $this->alternativeTitleService->create($item);

      $this->storage->downloadImages($item->poster, $item->backdrop);

      DB::commit();

      return $item->fresh();
    }

    /**
     * Search against TMDb and IMDb for more informations.
     * We don't need to get more informations if we add the item from the subpage.
     *
     * @param $data
     * @return array
     */
    public function makeDataComplete($data)
    {
      if( ! isset($data['imdb_id'])) {
        $details = $this->tmdb->details($data['tmdb_id'], $data['media_type']);
        $title = $details->name ?? $details->title;

        $data['imdb_id'] = $data['imdb_id'] ?? $this->parseImdbId($details);
        $data['youtube_key'] = $data['youtube_key'] ?? $this->parseYoutubeKey($details, $data['media_type']);
        $data['overview'] = $data['overview'] ?? $details->overview;
        $data['tmdb_rating'] = $data['tmdb_rating'] ?? $details->vote_average;
        $data['backdrop'] = $data['backdrop'] ?? $details->backdrop_path;
        $data['slug'] = $data['slug'] ?? getSlug($title);
        $data['homepage'] = $data['homepage'] ?? $details->homepage;
      }

      $data['imdb_rating'] = $this->parseImdbRating($data);

      return $data;
    }

    /**
     * Refresh informations for all items.
     */
    public function refreshAll()
    {
      logInfo("Refresh all items");
      increaseTimeLimit();

      $this->genreService->updateGenreLists();

      $this->model->orderBy('refreshed_at')->get()->each(function($item) {
        UpdateItem::dispatch($item->id);
      });
    }

    /**
     * Refresh informations for an item.
     * Like ratings, new episodes, new poster and backdrop images.
     *
     * @param $itemId
     *
     * @return Response|false
     */
    public function refresh($itemId)
    {
      logInfo("Start refresh for item [$itemId]");

      $item = $this->model->findOrFail($itemId);

      $details = $this->tmdb->details($item->tmdb_id, $item->media_type);

      $title = $details->name ?? ($details->title ?? null);

      // If TMDb didn't find anything then title will be not set => don't update
      if( ! $title) {
        return false;
      }

      logInfo("Refresh", [$title]);

      $this->storage->removeImages($item->poster, $item->backdrop);

      $imdbId = $item->imdb_id ?? $this->parseImdbId($details);

      $item->update([
        'imdb_id' => $imdbId,
        'youtube_key' => $this->parseYoutubeKey($details, $item->media_type),
        'overview' => $details->overview,
        'tmdb_rating' => $details->vote_average,
        'imdb_rating' => $this->parseImdbRating(['imdb_id' => $imdbId]),
        'backdrop' => $details->backdrop_path,
        'poster' => $details->poster_path,
        'slug' => getSlug($title),
        'title' => $title,
        'homepage' => $details->homepage ?? null,
        'original_title' => $details->original_name ?? $details->original_title,
      ]);

      $this->episodeService->create($item);
      $this->alternativeTitleService->create($item);

      $this->genreService->sync(
        $item,
        collect($details->genres)->pluck('id')->all()
      );

      $this->storage->downloadImages($item->poster, $item->backdrop);
    }

    /**
     * Manually change the TMDb id and refresh all online metadata.
     *
     * @param $itemId
     * @param $tmdbId
     *
     * @return Item|false
     */
    public function manualTmdbUpdate($itemId, $tmdbId)
    {
      $item = $this->model->findOrFail($itemId);
      $oldTmdbId = $item->tmdb_id;
      $tmdbId = (int) $tmdbId;

      if($tmdbId <= 0) {
        return false;
      }

      $latestSeenEpisode = null;

      if($item->media_type == 'tv' && $oldTmdbId) {
        $latestSeenEpisode = \App\Episode::where('tmdb_id', $oldTmdbId)
          ->where('seen', true)
          ->orderBy('season_number', 'desc')
          ->orderBy('episode_number', 'desc')
          ->first();
      }

      $details = $this->tmdb->details($tmdbId, $item->media_type);
      $title = $details->name ?? ($details->title ?? null);

      if( ! $title) {
        return false;
      }

      try {
        DB::beginTransaction();

        $this->storage->removeImages($item->poster, $item->backdrop);

        if($oldTmdbId && $oldTmdbId != $tmdbId) {
          DB::table('episodes')->where('tmdb_id', $oldTmdbId)->update(['tmdb_id' => $tmdbId]);
          $this->alternativeTitleService->remove($oldTmdbId);
        }

        $releaseDate = $details->release_date ?? $details->first_air_date ?? Item::FALLBACK_DATE;

        try {
          $release = Carbon::createFromFormat('Y-m-d', $releaseDate ?: Item::FALLBACK_DATE);
        } catch(\Exception $e) {
          $release = Carbon::createFromFormat('Y-m-d', Item::FALLBACK_DATE);
        }

        $imdbId = $this->parseImdbId($details);

        $item->update([
          'tmdb_id' => $tmdbId,
          'imdb_id' => $imdbId,
          'youtube_key' => $this->parseYoutubeKey($details, $item->media_type),
          'overview' => $details->overview,
          'tmdb_rating' => $details->vote_average,
          'imdb_rating' => $this->parseImdbRating(['imdb_id' => $imdbId]),
          'backdrop' => $details->backdrop_path,
          'poster' => $details->poster_path,
          'slug' => getSlug($title),
          'title' => $title,
          'homepage' => $details->homepage ?? null,
          'original_title' => $details->original_name ?? $details->original_title ?? $title,
          'released' => $release->getTimestamp(),
          'released_timestamp' => $release->toDateTimeString(),
          'refreshed_at' => now(),
        ]);

        $item = $item->fresh();

        $this->episodeService->create($item);

        if($latestSeenEpisode) {
          $this->markImportedEpisodesAsSeen(
            $item->tmdb_id,
            $latestSeenEpisode->season_number,
            $latestSeenEpisode->episode_number
          );
        }

        $this->markCompletedTvAsWatchingNow($item);

        $this->alternativeTitleService->create($item);

        $this->genreService->sync(
          $item,
          isset($details->genres) ? collect($details->genres)->pluck('id')->all() : []
        );

        $this->storage->downloadImages($item->poster, $item->backdrop);

        DB::commit();

        return $item;
      } catch(\Exception $e) {
        DB::rollBack();

        throw $e;
      }
    }

    /**
     * If the user clicks to fast on adding item,
     * we need to re-fetch the rating from IMDb.
     *
     * @param $data
     *
     * @return float|null
     */
    private function parseImdbRating($data)
    {
      if( ! isset($data['imdb_rating'])) {
        $imdbId = $data['imdb_id'];

        if($imdbId) {
          return $this->imdb->parseRating($imdbId);
        }

        return null;
      }

      // Otherwise we already have the rating saved.
      return $data['imdb_rating'];
    }

    /**
     * TV shows needs an extra append for external ids.
     *
     * @param $details
     * @return mixed
     */
    public function parseImdbId($details)
    {
      return $details->external_ids->imdb_id ?? ($details->imdb_id ?? null);
    }

    /**
     * Get the key for the youtube trailer video. Fallback with english trailer.
     *
     * @param $details
     * @param $mediaType
     * @return string|null
     */
    public function parseYoutubeKey($details, $mediaType)
    {
      if(isset($details->videos->results[0])) {
        return $details->videos->results[0]->key;
      }

      // Try to fetch details again with english language as fallback.
      $videos = $this->tmdb->videos($details->id, $mediaType, 'en');

      return $videos->results[0]->key ?? null;
    }

    /**
     * @param $data
     * @param $mediaType
     * @return Model
     */
    public function createEmpty($data, $mediaType)
    {
      $mediaType = mediaType($mediaType);

      $data = [
        'name' => getFileName($data),
        'src' => $data->changed->src ?? $data->src,
        'subtitles' => $data->changed->subtitles ?? $data->subtitles,
      ];

      return $this->model->storeEmpty($data, $mediaType);
    }

    /**
     * Delete movie or tv show (with episodes and alternative titles).
     * Also remove the poster image file.
     *
     * @param $itemId
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function remove($itemId)
    {
      $item = $this->model->find($itemId);

      if( ! $item) {
        return response('Not Found', Response::HTTP_NOT_FOUND);
      }

      $tmdbId = $item->tmdb_id;

      $item->delete();

      // Delete all related episodes, alternative titles and images.
      $this->episodeService->remove($tmdbId);
      $this->alternativeTitleService->remove($tmdbId);
      $this->storage->removeImages($item->poster, $item->backdrop);
    }

    /**
     * Return all items.
     *
     * @param $type
     * @param $orderBy
     * @param $sortDirection
     * @return array
     */
    public function getWithPagination($type, $orderBy, $sortDirection, $includeNotWatching = false, $includeCompleted = false)
    {
      $items = $this->itemsQuery($type)->with('latestEpisode')->withCount('episodesWithSrc');

      $this->applyHomeVisibility($items, $type, $includeNotWatching, $includeCompleted);
      $this->applySort($items, $orderBy, $sortDirection);

      return [
        'data' => $items->get(),
        'next_page_url' => null,
        'groups' => $this->homeGroups($type),
      ];
    }

    private function itemsQuery($type)
    {
      $items = $this->model->newQuery();

      if($type == 'watchlist') {
        $items->where('watchlist', true);
      } elseif( ! $this->setting->first()->show_watchlist_everywhere) {
        $items->where('watchlist', false);
      }

      if($type == 'tv' || $type == 'movie') {
        $items->where('media_type', $type);
      }

      return $items;
    }

    private function applySort($items, $orderBy, $sortDirection)
    {
      $filter = $this->getSortFilter($orderBy);

      if($orderBy == 'own rating') {
        return $items->orderByRaw('CASE
          WHEN rating = 4 THEN 0
          WHEN rating = 1 THEN 1
          WHEN rating = 2 THEN 2
          WHEN rating = 3 THEN 3
          ELSE 4
        END ASC')->orderBy('title', 'asc');
      }

      return $items->orderBy($filter, $sortDirection);
    }

    private function applyHomeVisibility($items, $type, $includeNotWatching, $includeCompleted)
    {
      if($type != 'home') {
        return;
      }

      $items->where(function($query) use ($includeNotWatching, $includeCompleted) {
        $query->where(function($query) {
          $this->whereActive($query);
          $query->where('watching_now', true);
        });

        if($includeNotWatching) {
          $query->orWhere(function($query) {
            $this->whereActive($query);
            $query->where('watching_now', false);
          });
        }

        if($includeCompleted) {
          $query->orWhere(function($query) {
            $this->whereCompleted($query);
          });
        }
      });
    }

    private function homeGroups($type)
    {
      if($type != 'home') {
        return [
          'watching' => 0,
          'not_watching' => 0,
          'completed' => 0,
        ];
      }

      return [
        'watching' => $this->itemsQuery($type)->where(function($query) {
          $this->whereActive($query);
          $query->where('watching_now', true);
        })->count(),
        'not_watching' => $this->itemsQuery($type)->where(function($query) {
          $this->whereActive($query);
          $query->where('watching_now', false);
        })->count(),
        'completed' => $this->itemsQuery($type)->where(function($query) {
          $this->whereCompleted($query);
        })->count(),
      ];
    }

    private function whereActive($query)
    {
      return $query->where(function($query) {
        $query->where(function($query) {
          $query->where('media_type', 'movie')
            ->where(function($query) {
              $query->whereNull('rating')
                ->orWhere('rating', 0);
            });
        })->orWhere(function($query) {
          $query->where('media_type', 'tv')
            ->where(function($query) {
              $query->whereNull('rating')
                ->orWhereNull('tmdb_id')
                ->orWhereHas('latestEpisode');
            });
        })->orWhere(function($query) {
          $query->whereNotIn('media_type', ['movie', 'tv']);
        });
      });
    }

    private function whereCompleted($query)
    {
      return $query->where('watchlist', false)->where(function($query) {
        $query->where(function($query) {
          $query->where('media_type', 'movie')
            ->whereNotNull('rating')
            ->where('rating', '<>', 0);
        })->orWhere(function($query) {
          $query->where('media_type', 'tv')
            ->whereNotNull('rating')
            ->whereNotNull('tmdb_id')
            ->whereDoesntHave('latestEpisode');
        });
      });
    }

    /**
     * Update rating.
     *
     * @param $itemId
     * @param $rating
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function changeRating($itemId, $rating)
    {
      $item = $this->model->find($itemId);

      if( ! $item) {
        return response('Not Found', Response::HTTP_NOT_FOUND);
      }

      // Update the parent relation only if we change rating from neutral.
      if($item->rating == 0) {
        $this->model->updateLastSeenAt($item->tmdb_id);
      }

      $item->update([
        'rating' => $rating,
        'watchlist' => false,
      ]);
    }

    public function toggleWatchingNow($itemId)
    {
      $item = $this->model->find($itemId);

      if( ! $item) {
        return response('Not Found', Response::HTTP_NOT_FOUND);
      }

      $item->update([
        'watching_now' => ! $item->watching_now,
      ]);

      return $item->fresh();
    }

    /**
     * Search for all items by title in our database.
     *
     * @param $title
     * @return mixed
     */
    public function search($title)
    {
      return $this->model->findByTitle($title)->with('latestEpisode')->withCount('episodesWithSrc')->get();
    }

    /**
     * Create a new item from import.
     *
     * @param $item
     */
    public function import($item)
    {
      logInfo("Importing", [$item->title ?? $item->tmdb_id]);

      // Fallback if export was from an older version of flox (<= 1.2.2).
      if( ! isset($item->last_seen_at) && isset($item->created_at)) {
        $item->last_seen_at = Carbon::createFromTimestamp($item->created_at);
      }

      // New versions of flox has no genre field anymore.
      if(isset($item->genre)) {
        unset($item->genre);
      }

      $item = collect($item)->except('id', 'startDate')->toArray();
      $seenSeason = $item['import_seen_season'] ?? null;
      $seenEpisode = $item['import_seen_episode'] ?? null;

      unset($item['import_seen_season'], $item['import_seen_episode']);

      $genreIds = [];

      if( ! empty($item['tmdb_id'])) {
        try {
          $item = $this->completeImportData($item);
          $genreIds = $item['genre_ids'] ?? [];
        } catch(\Exception $e) {
          logInfo("Import enrichment failed, using minimal data", [$e->getMessage()]);
        }
      }

      $item = $this->withImportDefaults($item);
      $itemData = collect($item)->except('genre_ids', 'genre', 'episodes', 'popularity')->toArray();
      $createdItem = $this->importItemData($itemData);

      if($createdItem->tmdb_id && $createdItem->media_type == 'tv') {
        try {
          $this->episodeService->create($createdItem);
        } catch(\Exception $e) {
          logInfo("Import episode enrichment failed", [$e->getMessage()]);
        }

        if($seenSeason && $seenEpisode) {
          $this->markImportedEpisodesAsSeen($createdItem->tmdb_id, $seenSeason, $seenEpisode);
        }

        $this->markCompletedTvAsWatchingNow($createdItem);
      }

      if($createdItem->tmdb_id) {
        try {
          $this->genreService->sync($createdItem, $genreIds);
          $this->alternativeTitleService->create($createdItem);
          $this->storage->downloadImages($createdItem->poster, $createdItem->backdrop);
        } catch(\Exception $e) {
          logInfo("Import post-processing failed", [$e->getMessage()]);
        }
      }
    }

    private function importItemData($itemData)
    {
      if( ! empty($itemData['tmdb_id'])) {
        return Item::updateOrCreate(
          [
            'tmdb_id' => $itemData['tmdb_id'],
            'media_type' => $itemData['media_type'],
          ],
          $itemData
        );
      }

      return Item::create($itemData);
    }

    private function completeImportData($item)
    {
      $details = $this->tmdb->details($item['tmdb_id'], $item['media_type']);
      $title = $details->name ?? $details->title ?? ($item['title'] ?? '');
      $releaseDate = $details->release_date ?? $details->first_air_date ?? Item::FALLBACK_DATE;

      try {
        $release = Carbon::createFromFormat('Y-m-d', $releaseDate ?: Item::FALLBACK_DATE);
      } catch(\Exception $e) {
        $release = Carbon::createFromFormat('Y-m-d', Item::FALLBACK_DATE);
      }

      $imdbId = $item['imdb_id'] ?? $this->parseImdbId($details);

      $item['title'] = $title ?: ($item['title'] ?? '');
      $item['original_title'] = $item['original_title'] ?? $details->original_name ?? $details->original_title ?? $item['title'];
      $item['poster'] = ! empty($item['poster']) ? $item['poster'] : ($details->poster_path ?? '');
      $item['released'] = $item['released'] ?? $release->getTimestamp();
      $item['released_timestamp'] = $item['released_timestamp'] ?? $release->toDateTimeString();
      $item['imdb_id'] = $imdbId;
      $item['youtube_key'] = $item['youtube_key'] ?? $this->parseYoutubeKey($details, $item['media_type']);
      $item['overview'] = $item['overview'] ?? $details->overview ?? null;
      $item['tmdb_rating'] = $item['tmdb_rating'] ?? $details->vote_average ?? null;
      $item['imdb_rating'] = $item['imdb_rating'] ?? $this->parseImdbRating(['imdb_id' => $imdbId]);
      $item['backdrop'] = ! empty($item['backdrop']) ? $item['backdrop'] : ($details->backdrop_path ?? null);
      $item['slug'] = $item['slug'] ?? getSlug($item['title']);
      $item['homepage'] = $item['homepage'] ?? $details->homepage ?? null;
      $item['genre_ids'] = isset($details->genres) ? collect($details->genres)->pluck('id')->all() : [];

      return $item;
    }

    private function withImportDefaults($item)
    {
      $now = now()->toDateTimeString();

      $item['poster'] = $item['poster'] ?? '';
      $item['title'] = $item['title'] ?? $item['original_title'] ?? $item['fp_name'] ?? '';
      $item['original_title'] = $item['original_title'] ?? $item['title'];
      $item['media_type'] = $item['media_type'] ?? 'movie';
      $item['rating'] = $item['rating'] ?? ($item['media_type'] == 'movie' ? 1 : 0);
      $item['released'] = $item['released'] ?? 0;
      $item['released_timestamp'] = $item['released_timestamp'] ?? null;
      $item['watchlist'] = $item['watchlist'] ?? false;
      $item['created_at'] = $item['created_at'] ?? $now;
      $item['updated_at'] = $item['updated_at'] ?? $now;
      $item['last_seen_at'] = $item['last_seen_at'] ?? $now;

      return $item;
    }

    public function markEpisodesAsSeenUntil($tmdbId, $season, $episode)
    {
      $latestSeenEpisode = \App\Episode::where('tmdb_id', $tmdbId)
        ->where('seen', true)
        ->orderBy('season_number', 'desc')
        ->orderBy('episode_number', 'desc')
        ->first();

      if($latestSeenEpisode && $this->episodeIsAfter($latestSeenEpisode, $season, $episode)) {
        $season = $latestSeenEpisode->season_number;
        $episode = $latestSeenEpisode->episode_number;
      }

      return \App\Episode::where('tmdb_id', $tmdbId)
        ->where(function($query) use ($season, $episode) {
          $query->where('season_number', '<', $season)
            ->orWhere(function($query) use ($season, $episode) {
              $query->where('season_number', $season)
                ->where('episode_number', '<=', $episode);
            });
        })
        ->update(['seen' => true]);
    }

    public function markImportedEpisodesAsSeen($tmdbId, $season, $episode)
    {
      $updated = $this->markEpisodesAsSeenUntil($tmdbId, $season, $episode);

      if( ! $updated) {
        \App\Episode::updateOrCreate(
          [
            'tmdb_id' => $tmdbId,
            'season_number' => $season,
            'episode_number' => $episode,
          ],
          [
            'name' => 'Episode ' . $episode,
            'season_tmdb_id' => 0,
            'episode_tmdb_id' => 0,
            'seen' => true,
            'release_episode' => null,
            'release_season' => null,
          ]
        );

        return 1;
      }

      return $updated;
    }

    private function episodeIsAfter($episode, $season, $episodeNumber)
    {
      if($episode->season_number > $season) {
        return true;
      }

      return $episode->season_number == $season && $episode->episode_number > $episodeNumber;
    }

    private function markCompletedTvAsWatchingNow($item)
    {
      if( ! $item || $item->media_type != 'tv' || $item->rating === null || ! $item->tmdb_id) {
        return;
      }

      $item = Item::findByTmdbId($item->tmdb_id)->with('latestEpisode')->first();

      if($item && ! $item->latestEpisode) {
        $item->update(['watching_now' => true]);
      }
    }

    /**
     * See if we can find a item by title, fp_name, tmdb_id or src in our database.
     *
     * If we search from file-parser, we also need to filter the results by media_type.
     * If we have e.g. 'Avatar' as tv show, we don't want results like the 'Avatar' movie.
     *
     * @param $type
     * @param $value
     * @param $mediaType
     * @return mixed
     */
    public function findBy($type, $value, $mediaType = null)
    {
      if($mediaType) {
        $mediaType = mediaType($mediaType);
      }

      switch($type) {
        case 'title':
          return $this->model->findByTitle($value, $mediaType)->first();
        case 'title_strict':
          return $this->model->findByTitleStrict($value, $mediaType)->first();
        case 'fp_name':
          return $this->model->findByFPName($value, $mediaType)->first();
        case 'tmdb_id':
          return $this->model->findByTmdbId($value)->with('latestEpisode')->first();
        case 'tmdb_id_strict':
          return$this->model->findByTmdbIdStrict($value, $mediaType)->with('latestEpisode')->first();
        case 'src':
          return $this->model->findBySrc($value)->first();
      }

      return null;
    }

    /**
     * Get the correct name from the table for sort filter.
     *
     * @param $orderBy
     * @return string
     */
    private function getSortFilter($orderBy)
    {
      switch($orderBy) {
        case 'last seen':
          return 'last_seen_at';
        case 'own rating':
          return 'rating';
        case 'title':
          return 'title';
        case 'release':
          return 'released';
        case 'tmdb rating':
          return 'tmdb_rating';
        case 'imdb rating':
          return 'imdb_rating';
      }
    }
  }
