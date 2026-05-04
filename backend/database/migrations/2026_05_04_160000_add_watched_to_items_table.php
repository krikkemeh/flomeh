<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddWatchedToItemsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('items', 'watched')) {
            Schema::table('items', function (Blueprint $table) {
                $table->boolean('watched')->default(false)->after('watching_now');
            });
        }

        DB::statement("
            UPDATE items
            SET watched = 1
            WHERE media_type = 'movie'
              AND watchlist = 0
        ");

        DB::statement("
            UPDATE items
            SET watching_now = 0
            WHERE media_type = 'movie'
        ");
    }

    public function down()
    {
        if (Schema::hasColumn('items', 'watched')) {
            Schema::table('items', function (Blueprint $table) {
                $table->dropColumn('watched');
            });
        }
    }
}
