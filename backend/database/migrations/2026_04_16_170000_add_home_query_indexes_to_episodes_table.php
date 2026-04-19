<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddHomeQueryIndexesToEpisodesTable extends Migration
{
    public function up()
    {
        if( ! $this->indexExists('episodes_tmdb_seen_season_episode_idx')) {
            Schema::table('episodes', function (Blueprint $table) {
                $table->index(
                    ['tmdb_id', 'seen', 'season_number', 'episode_number'],
                    'episodes_tmdb_seen_season_episode_idx'
                );
            });
        }

        if( ! $this->indexExists('episodes_tmdb_src_idx')) {
            DB::statement('ALTER TABLE `episodes` ADD INDEX `episodes_tmdb_src_idx` (`tmdb_id`, `src`(191))');
        }
    }

    public function down()
    {
        Schema::table('episodes', function (Blueprint $table) {
            if($this->indexExists('episodes_tmdb_seen_season_episode_idx')) {
                $table->dropIndex('episodes_tmdb_seen_season_episode_idx');
            }

            if($this->indexExists('episodes_tmdb_src_idx')) {
                $table->dropIndex('episodes_tmdb_src_idx');
            }
        });
    }

    private function indexExists($indexName)
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'episodes')
            ->where('index_name', $indexName)
            ->exists();
    }
}
