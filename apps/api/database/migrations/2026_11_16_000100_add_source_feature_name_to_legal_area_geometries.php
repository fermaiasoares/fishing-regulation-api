<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Name of the official feature (e.g. "DO5: Rio Caratinga"), so the API can tell the
 * user which hydrographic district the point is in, not only the legal area.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_area_geometries', function (Blueprint $table) {
            $table->text('source_feature_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('legal_area_geometries', function (Blueprint $table) {
            $table->dropColumn('source_feature_name');
        });
    }
};
