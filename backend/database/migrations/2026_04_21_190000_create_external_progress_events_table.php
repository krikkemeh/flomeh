<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateExternalProgressEventsTable extends Migration
{
    public function up()
    {
        if(Schema::hasTable('external_progress_events')) {
            return;
        }

        Schema::create('external_progress_events', function (Blueprint $table) {
            $table->increments('id');
            $table->string('source', 50)->index();
            $table->string('status', 50)->index();
            $table->string('message')->nullable();
            $table->integer('item_id')->nullable()->index();
            $table->integer('tmdb_id')->nullable()->index();
            $table->string('series_title')->nullable()->index();
            $table->integer('season_number')->nullable();
            $table->integer('episode_number')->nullable();
            $table->integer('progress')->nullable();
            $table->boolean('completed')->default(false);
            $table->string('playback_id')->nullable()->index();
            $table->string('episode_title')->nullable();
            $table->string('href')->nullable();
            $table->text('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        if(Schema::hasTable('external_progress_events')) {
            Schema::drop('external_progress_events');
        }
    }
}
