<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Rules that apply to the species of one official list, wherever they occur
 * (e.g. the national list of threatened aquatic species).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_rules', function (Blueprint $table) {
            $table->string('species_list')->nullable();
        });
        DB::statement('ALTER TABLE legal_rules DROP CONSTRAINT legal_rules_species_check');
        DB::statement(<<<'SQL'
            ALTER TABLE legal_rules
            ADD CONSTRAINT legal_rules_species_check CHECK (
                (species_scope IN ('native', 'non_native', 'all') AND species_list IS NULL)
                OR (species_scope = 'threatened' AND species_list IS NOT NULL)
            )
            SQL);
    }

    public function down(): void
    {
        DB::table('legal_rules')->where('species_scope', 'threatened')->delete();
        DB::statement('ALTER TABLE legal_rules DROP CONSTRAINT legal_rules_species_check');
        DB::statement("ALTER TABLE legal_rules ADD CONSTRAINT legal_rules_species_check CHECK (species_scope IN ('native', 'non_native', 'all'))");
        Schema::table('legal_rules', function (Blueprint $table) {
            $table->dropColumn('species_list');
        });
    }
};
