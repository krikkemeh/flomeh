<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddWatchingNowToItemsTable extends Migration
{
    public function up()
    {
        if( ! Schema::hasColumn('items', 'watching_now')) {
            Schema::table('items', function (Blueprint $table) {
                $table->boolean('watching_now')->default(false)->after('watchlist');
            });
        }

        DB::statement("
            UPDATE items
            SET watching_now = 1
            WHERE watchlist = 0
              AND (
                (
                  media_type = 'movie'
                  AND rating IS NOT NULL
                  AND rating <> 0
                )
                OR (
                  media_type = 'tv'
                  AND rating IS NOT NULL
                  AND tmdb_id IS NOT NULL
                  AND NOT EXISTS (
                    SELECT 1
                    FROM episodes
                    WHERE episodes.tmdb_id = items.tmdb_id
                      AND episodes.seen = 0
                      AND NOT EXISTS (
                        SELECT 1
                        FROM episodes AS seen_episodes
                        WHERE seen_episodes.tmdb_id = episodes.tmdb_id
                          AND seen_episodes.seen = 1
                          AND (
                            seen_episodes.season_number > episodes.season_number
                            OR (
                              seen_episodes.season_number = episodes.season_number
                              AND seen_episodes.episode_number > episodes.episode_number
                            )
                          )
                      )
                  )
                )
              )
        ");
    }

    public function down()
    {
        if(Schema::hasColumn('items', 'watching_now')) {
            Schema::table('items', function (Blueprint $table) {
                $table->dropColumn('watching_now');
            });
        }
    }
}
