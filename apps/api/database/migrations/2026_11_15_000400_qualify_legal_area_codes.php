<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Several jurisdictions side by side (docs/adr/0001-jurisdicoes.md).
 *
 * Area codes become global: "mg:doce", "br:sao_francisco", and the root of each
 * catalog is the jurisdiction itself ("mg", "br"). An area may also lie within
 * areas of another jurisdiction (within_codes), which is how federal basin rules
 * reach a point mapped by a state.
 *
 * Footprints loaded before this change belong to Minas Gerais, the only
 * jurisdiction mapped so far, and are renamed accordingly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_areas', function (Blueprint $table) {
            $table->jsonb('within_codes')->default('[]');
        });
        DB::statement(<<<'SQL'
            UPDATE legal_area_geometries
            SET legal_area_code = 'mg:' || legal_area_code
            WHERE position(':' in legal_area_code) = 0 AND legal_area_code <> 'mg'
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE legal_area_geometries
            ADD CONSTRAINT legal_area_geometries_code_check
            CHECK (legal_area_code ~ '^[a-z]{2}(:[a-z0-9_]+)?$')
            SQL);
        DB::statement('CREATE INDEX legal_area_geometries_code_index ON legal_area_geometries (legal_area_code)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS legal_area_geometries_code_index');
        DB::statement('ALTER TABLE legal_area_geometries DROP CONSTRAINT IF EXISTS legal_area_geometries_code_check');
        DB::statement("UPDATE legal_area_geometries SET legal_area_code = substr(legal_area_code, 4) WHERE legal_area_code LIKE 'mg:%'");
        Schema::table('legal_areas', function (Blueprint $table) {
            $table->dropColumn('within_codes');
        });
    }
};
