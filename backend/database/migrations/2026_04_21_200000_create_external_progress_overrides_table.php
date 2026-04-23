<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateExternalProgressOverridesTable extends Migration
{
    public function up()
    {
        if(Schema::hasTable('external_progress_overrides')) {
            return;
        }

        Schema::create('external_progress_overrides', function(Blueprint $table) {
            $table->increments('id');
            $table->string('source', 50);
            $table->string('external_series_title');
            $table->string('normalized_external_series_title');
            $table->string('family_series_title');
            $table->string('normalized_family_series_title');
            $table->integer('tmdb_id');
            $table->integer('force_season')->nullable();
            $table->integer('episode_shift')->default(0);
            $table->timestamps();

            $table->index('source', 'epo_source_idx');
            $table->index('external_series_title', 'epo_series_title_idx');
            $table->index('normalized_external_series_title', 'epo_norm_title_idx');
            $table->index('family_series_title', 'epo_family_title_idx');
            $table->index('normalized_family_series_title', 'epo_norm_family_idx');
            $table->index('tmdb_id', 'epo_tmdb_idx');
        });
    }

    public function down()
    {
        if(Schema::hasTable('external_progress_overrides')) {
            Schema::drop('external_progress_overrides');
        }
    }
}
