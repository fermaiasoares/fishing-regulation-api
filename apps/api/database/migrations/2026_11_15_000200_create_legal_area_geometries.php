<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Footprint of each legal area (e.g. "doce", "sao_francisco") on the map.
 *
 * Keyed by the stable area code rather than by catalog version, so a new catalog
 * version keeps its geography. Every footprint must come from an imported
 * geographic dataset version: nothing here is drawn by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_area_geometries', function (Blueprint $table) {
            $table->id();
            $table->string('legal_area_code');
            $table->foreignId('dataset_version_id')->constrained('geographic_dataset_versions')->restrictOnDelete();
            $table->string('source_feature_id');
            $table->timestampsTz();
            $table->unique(['legal_area_code', 'dataset_version_id', 'source_feature_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE legal_area_geometries
            ADD COLUMN geometry geometry(MultiPolygon, 4326) NOT NULL,
            ADD CONSTRAINT legal_area_geometries_geometry_check
            CHECK (
                ST_IsValid(geometry) AND NOT ST_IsEmpty(geometry)
                AND ST_XMin(geometry) >= -180 AND ST_XMax(geometry) <= 180
                AND ST_YMin(geometry) >= -90 AND ST_YMax(geometry) <= 90
            )
            SQL);
        DB::statement('CREATE INDEX legal_area_geometries_geometry_gist ON legal_area_geometries USING GIST (geometry)');
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_area_geometries');
    }
};
