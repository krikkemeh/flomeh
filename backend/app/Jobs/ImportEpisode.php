<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Episode;

class ImportEpisode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $episodes;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($episodes)
    {
      $this->episodes = json_decode($episodes);
    }

  /**
   * Execute the job.
   *
   * @param Episode $episode
   * @return void
   * 
   * @throws \Exception
   */
    public function handle(Episode $episode)
    {
      foreach($this->episodes as $ep) {
        logInfo("Importing episode", [$ep->name]);
        try {
          $ep = collect($ep)->except('id')->toArray();
          
          if(isset($ep['tmdb_id'], $ep['season_number'], $ep['episode_number'])) {
            $existingEpisode = $episode
              ->where('tmdb_id', $ep['tmdb_id'])
              ->where('season_number', $ep['season_number'])
              ->where('episode_number', $ep['episode_number'])
              ->first();

            if($existingEpisode && $existingEpisode->seen && isset($ep['seen']) && ! $ep['seen']) {
              $ep['seen'] = true;
            }

            $episode->updateOrCreate(
              [
                'tmdb_id' => $ep['tmdb_id'],
                'season_number' => $ep['season_number'],
                'episode_number' => $ep['episode_number'],
              ],
              $ep
            );
          } else {
            $episode->create($ep);
          }
        } catch(\Exception $e) {
          logInfo("Failed:", [$e]);
          throw $e;
        }
      }
    }
}
