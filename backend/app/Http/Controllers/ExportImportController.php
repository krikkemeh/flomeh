<?php

  namespace App\Http\Controllers;

  use App\Jobs\ImportItem;
  use App\Jobs\ImportEpisode;
  use App\AlternativeTitle;
  use App\Episode;
  use App\Item;
  use App\Services\ImportAiFormatter;
  use App\Services\Storage;
  use App\Setting;
  use Illuminate\Support\Facades\DB;
  use Illuminate\Support\Facades\Request;
  use Symfony\Component\HttpFoundation\Response;

  class ExportImportController {

    private $item;
    private $episodes;
    private $storage;
    private $alternativeTitles;
    private $settings;
    private $importAiFormatter;

    public function __construct(Item $item, Episode $episodes, AlternativeTitle $alternativeTitles, Storage $storage, Setting $settings, ImportAiFormatter $importAiFormatter)
    {
      $this->item = $item;
      $this->episodes = $episodes;
      $this->alternativeTitles = $alternativeTitles;
      $this->storage = $storage;
      $this->settings = $settings;
      $this->importAiFormatter = $importAiFormatter;
    }

    /**
     * Save all movies and series as json file and return a download response.
     *
     * @return mixed
     */
    public function export()
    {
      $data['items'] = $this->item->all();
      $data['episodes'] = $this->episodes->all();
      $data['alternative_titles'] = $this->alternativeTitles->all();
      $data['settings'] = $this->settings->all();

      $filename = $this->storage->createExportFilename();
      $json = json_encode($data);
      $path = base_path('../public/exports/' . $filename);

      if( ! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
      }

      file_put_contents($path, $json);

      return response($json, Response::HTTP_OK, [
        'Content-Type' => 'application/json',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      ]);
    }

    /**
     * Export only watched progress as CSV.
     *
     * TV shows export the latest watched episode.
     * Movies export only the title.
     *
     * @return Response
     */
    public function exportProgressCsv()
    {
      $rows = [
        ['nome serie/film', 'stagione', 'episodio'],
      ];

      $this->item->orderBy('title')->get()->each(function($item) use (&$rows) {
        if($item->media_type == 'tv') {
          $episode = $this->episodes
            ->where('tmdb_id', $item->tmdb_id)
            ->where('seen', true)
            ->orderBy('season_number', 'desc')
            ->orderBy('episode_number', 'desc')
            ->first();

          if($episode) {
            $rows[] = [
              $item->title,
              $episode->season_number,
              $episode->episode_number,
            ];
          }

          return;
        }

        if($item->media_type == 'movie' && ! $item->watchlist && $item->watched) {
          $rows[] = [
            $item->title,
            '',
            '',
          ];
        }
      });

      $handle = fopen('php://temp', 'r+');

      foreach($rows as $row) {
        fputcsv($handle, $row, ';');
      }

      rewind($handle);
      $csv = stream_get_contents($handle);
      fclose($handle);

      $filename = 'flox-progress--' . date('Y-m-d---H-i') . '.csv';
      $path = base_path('../public/exports/' . $filename);

      if( ! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
      }

      file_put_contents($path, $csv);

      return response($csv, Response::HTTP_OK, [
        'Content-Type' => 'text/csv; charset=UTF-8',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
      ]);
    }

    /**
     * Reset item table and restore backup.
     * Downloads every poster image new.
     *
     * @return Response
     */
    public function import()
    {
      return $this->importFile(true);
    }

    public function importAdd()
    {
      return $this->importFile(false);
    }

    public function importAiFormat()
    {
      if (isDemo()) {
        return response()->json(['json' => '[]']);
      }

      $text = trim((string) Request::input('text'));

      if($text === '') {
        return response('Missing text to format.', Response::HTTP_UNPROCESSABLE_ENTITY);
      }

      try {
        $decoded = $this->importAiFormatter->format($text);
      } catch(\Exception $e) {
        return response('AI request failed: ' . $e->getMessage(), Response::HTTP_BAD_GATEWAY);
      }

      $normalized = $this->normalizeImportData($decoded);

      return response()->json([
        'json' => json_encode($normalized->items ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        'items_count' => isset($normalized->items) ? count($normalized->items) : 0,
      ]);
    }

    public function pendingImportJobs()
    {
      $status = $this->readImportStatus();
      $logDate = $status['log_date'] ?? date('Y-m-d');
      $logUrl = rtrim(config('app.url'), '/') . '/api/import-jobs/log/' . $logDate;
      $pendingJobs = DB::table('jobs')
        ->where(function($query) {
          $query
            ->where('payload', 'like', '%ImportItem%')
            ->orWhere('payload', 'like', '%ImportEpisode%');
        })
        ->count();

      if(($status['running'] ?? false) && $pendingJobs == 0 && $this->isImportStatusStale($status)) {
        $this->writeImportStatus(false, 'Import failed: no running job found after PHP time limit.', [
          'failed' => true,
          'finished_at' => now()->toDateTimeString(),
        ]);

        $status = $this->readImportStatus();
      }

      if(($status['running'] ?? false) || ($status['failed'] ?? false)) {
        $pendingJobs = max(1, $pendingJobs);
      }

      return response()->json([
        'pending' => $pendingJobs,
        'status' => $status,
        'message' => $status['message'] ?? null,
        'log_url' => $logUrl,
      ]);
    }

    public function importLog($date = null)
    {
      $date = $date ?: date('Y-m-d');

      if( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return response('Invalid log date.', Response::HTTP_BAD_REQUEST);
      }

      $path = storage_path('logs/laravel-' . $date . '.log');

      if( ! file_exists($path)) {
        return response('Log not found.', Response::HTTP_NOT_FOUND);
      }

      return response(file_get_contents($path), Response::HTTP_OK, [
        'Content-Type' => 'text/plain; charset=UTF-8',
      ]);
    }

    public function clearImportError()
    {
      $this->replaceImportStatus([
        'running' => false,
        'failed' => false,
        'message' => null,
        'cleared_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
      ]);

      return response([], Response::HTTP_OK);
    }

    private function importFile($replaceExisting)
    {
      if (isDemo()) {
        return response('Success', Response::HTTP_OK);
      }

      increaseTimeLimit();

      $file = Request::file('import');

      $extension = $file->getClientOriginalExtension();

      if($extension !== 'json') {
        return response('This is not a flox backup file.', Response::HTTP_UNPROCESSABLE_ENTITY);
      }

      $data = json_decode(file_get_contents($file));

      if(json_last_error() !== JSON_ERROR_NONE) {
        return response('Invalid JSON file.', Response::HTTP_UNPROCESSABLE_ENTITY);
      }

      $data = $this->normalizeImportData($data);

      $this->writeImportStatus(true, 'Import starting...', [
        'failed' => false,
        'replace_existing' => $replaceExisting,
      ]);

      register_shutdown_function(function() {
        $error = error_get_last();

        if($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
          $this->writeImportStatus(false, $error['message'], [
            'failed' => true,
            'finished_at' => now()->toDateTimeString(),
          ]);
        }
      });

      try {
        $this->importItems($data, $replaceExisting);
        $this->importEpisodes($data, $replaceExisting);
        $this->importAlternativeTitles($data, $replaceExisting);
        $this->importSettings($data, $replaceExisting);

        $this->writeImportStatus(false, 'Import completed.', [
          'failed' => false,
          'finished_at' => now()->toDateTimeString(),
        ]);
      } catch(\Exception $e) {
        $this->writeImportStatus(false, 'Import failed: ' . $e->getMessage(), [
          'failed' => true,
          'finished_at' => now()->toDateTimeString(),
        ]);

        throw $e;
      }
    }

    private function normalizeImportData($data)
    {
      if(is_array($data)) {
        return (object) [
          'items' => array_map([$this, 'normalizeMinimalItem'], $data),
          'simplified_import' => true,
        ];
      }

      if(isset($data->title) || isset($data->titolo) || isset($data->tmdb_id)) {
        return (object) [
          'items' => [$this->normalizeMinimalItem($data)],
          'simplified_import' => true,
        ];
      }

      if(isset($data->items)) {
        $hasSimplifiedItems = false;

        $data->items = array_map(function($item) use (&$hasSimplifiedItems) {
          if(isset($item->stagione) || isset($item->season) || isset($item->episodio) || isset($item->episode) || isset($item->titolo)) {
            $hasSimplifiedItems = true;
            return $this->normalizeMinimalItem($item);
          }

          return $item;
        }, $data->items);

        if($hasSimplifiedItems) {
          $data->simplified_import = true;
        }
      }

      return $data;
    }

    private function normalizeMinimalItem($item)
    {
      $item = (object) $item;

      $season = $item->stagione ?? $item->season ?? $item->season_number ?? null;
      $episode = $item->episodio ?? $item->episode ?? $item->episode_number ?? null;
      $title = $item->title ?? $item->titolo ?? '';
      $markSeenUntil = $this->shouldMarkSeenUntil($item);

      $item->title = $title;
      $item->original_title = $item->original_title ?? $title;
      $item->media_type = $item->media_type ?? (($season || $episode) ? 'tv' : 'movie');

      if($markSeenUntil && $season && $episode) {
        $item->import_seen_season = (int) $season;
        $item->import_seen_episode = (int) $episode;
      }

      unset(
        $item->titolo,
        $item->stagione,
        $item->season,
        $item->season_number,
        $item->episodio,
        $item->episode,
        $item->episode_number,
        $item->seen_until,
        $item->mark_seen_until,
        $item->visto_fino_a,
        $item->visti_fino_a
      );

      return $item;
    }

    private function shouldMarkSeenUntil($item)
    {
      $value = $item->seen_until
        ?? $item->mark_seen_until
        ?? $item->visto_fino_a
        ?? $item->visti_fino_a
        ?? false;

      if(is_bool($value)) {
        return $value;
      }

      if(is_numeric($value)) {
        return (int) $value === 1;
      }

      if(is_string($value)) {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'si', 'sì'], true);
      }

      return false;
    }

    private function importItems($data, $replaceExisting)
    {
      logInfo("Import Movies");

      if(isset($data->items)) {
        if($replaceExisting) {
          DB::table('items')->delete();
        }

        if($replaceExisting && isset($data->simplified_import)) {
          $this->episodes->truncate();
        }

        $total = count($data->items);

        foreach($data->items as $index => $item) {
          $current = $index + 1;
          $title = $item->title ?? $item->tmdb_id ?? 'unknown item';
          $message = "Importing {$current}/{$total}: {$title}";

          logInfo($message);
          $this->writeImportStatus(true, $message, [
            'failed' => false,
            'current' => $current,
            'total' => $total,
            'current_title' => $title,
          ]);

          ImportItem::dispatch(json_encode($item));
        }
      }

      logInfo("Import Movies done.");
    }

    private function importEpisodes($data, $replaceExisting)
    {
      logInfo("Import Tv Shows");

      if(isset($data->episodes)) {
        if($replaceExisting) {
          $this->episodes->truncate();
        }

        foreach(array_chunk($data->episodes, 50) as $chunk) {
          ImportEpisode::dispatch(json_encode($chunk));
        }
      }

      logInfo("Import Tv Shows done.");
    }

    private function importStatusPath()
    {
      return storage_path('app/import-status.json');
    }

    private function readImportStatus()
    {
      $path = $this->importStatusPath();

      if( ! file_exists($path)) {
        return [
          'running' => false,
          'failed' => false,
          'message' => null,
        ];
      }

      $status = json_decode(file_get_contents($path), true);

      if( ! is_array($status)) {
        return [
          'running' => false,
          'failed' => true,
          'message' => 'Import status file is invalid.',
        ];
      }

      return $status;
    }

    private function isImportStatusStale(array $status)
    {
      if(empty($status['updated_at'])) {
        return false;
      }

      try {
        $updatedAt = \Carbon\Carbon::parse($status['updated_at']);
      } catch(\Exception $e) {
        return false;
      }

      $timeLimit = (int) env('PHP_TIME_LIMIT', 600);

      return $updatedAt->diffInSeconds(now()) > ($timeLimit + 60);
    }

    private function writeImportStatus($running, $message, array $data = [])
    {
      $path = $this->importStatusPath();
      $previous = $this->readImportStatus();

      $status = array_merge($previous, $data, [
        'running' => $running,
        'message' => $message,
        'log_date' => date('Y-m-d'),
        'updated_at' => now()->toDateTimeString(),
      ]);

      if($running && empty($status['started_at'])) {
        $status['started_at'] = now()->toDateTimeString();
      }

      if( ! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
      }

      file_put_contents($path, json_encode($status, JSON_PRETTY_PRINT));
    }

    private function replaceImportStatus(array $status)
    {
      $path = $this->importStatusPath();

      if( ! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
      }

      file_put_contents($path, json_encode($status, JSON_PRETTY_PRINT));
    }

    private function importAlternativeTitles($data, $replaceExisting)
    {
      if(isset($data->alternative_titles)) {
        if($replaceExisting) {
          $this->alternativeTitles->truncate();
        }

        foreach($data->alternative_titles as $title) {
          $title = collect($title)->except('id')->toArray();

          $this->alternativeTitles->firstOrCreate($title);
        }
      } elseif($replaceExisting && isset($data->simplified_import)) {
        $this->alternativeTitles->truncate();
      }
    }

    private function importSettings($data, $replaceExisting)
    {
      if($replaceExisting && isset($data->settings) && count($data->settings)) {

        $this->settings->truncate();

        foreach($data->settings as $setting) {
          $setting = collect($setting)->except('id')->toArray();

          $this->settings->create($setting);
        }
      }
    }
  }
