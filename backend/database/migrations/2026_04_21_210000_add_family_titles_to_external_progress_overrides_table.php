<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddFamilyTitlesToExternalProgressOverridesTable extends Migration
{
    public function up()
    {
        if( ! Schema::hasTable('external_progress_overrides')) {
            return;
        }

        Schema::table('external_progress_overrides', function(Blueprint $table) {
            if( ! Schema::hasColumn('external_progress_overrides', 'family_series_title')) {
                $table->string('family_series_title')->nullable()->after('normalized_external_series_title');
                $table->string('normalized_family_series_title')->nullable()->after('family_series_title');
            }
        });

        DB::table('external_progress_overrides')
            ->whereNull('family_series_title')
            ->update([
                'family_series_title' => DB::raw('external_series_title'),
                'normalized_family_series_title' => DB::raw('normalized_external_series_title'),
            ]);

        Schema::table('external_progress_overrides', function(Blueprint $table) {
            if(Schema::hasColumn('external_progress_overrides', 'family_series_title')) {
                $table->index('family_series_title', 'epo_family_title_idx');
                $table->index('normalized_family_series_title', 'epo_norm_family_idx');
            }
        });
    }

    public function down()
    {
        if( ! Schema::hasTable('external_progress_overrides')) {
            return;
        }

        Schema::table('external_progress_overrides', function(Blueprint $table) {
            if(Schema::hasColumn('external_progress_overrides', 'normalized_family_series_title')) {
                $table->dropIndex('epo_family_title_idx');
                $table->dropIndex('epo_norm_family_idx');
                $table->dropColumn(['family_series_title', 'normalized_family_series_title']);
            }
        });
    }
}
