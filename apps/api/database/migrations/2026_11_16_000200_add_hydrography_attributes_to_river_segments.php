<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Attributes of the ANA drainage segments (BHAE) the API needs: the Otto
 * Pfafstetter codes of the watercourse and of the contribution area, the water
 * domain (Federal Constitution, arts. 20, III and 26, I; "international" rivers are
 * federal property too) and the Strahler order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('river_segments', function (Blueprint $table) {
            $table->string('river_code', 32)->nullable();
            $table->string('basin_code', 32)->nullable();
            $table->string('domain', 16)->nullable();
            $table->smallInteger('strahler')->nullable();
        });
        DB::statement(<<<'SQL'
            ALTER TABLE river_segments
            ADD CONSTRAINT river_segments_domain_check CHECK (domain IS NULL OR domain IN ('federal', 'state', 'international', 'coastline'))
            SQL);
        DB::statement('CREATE INDEX river_segments_river_code_index ON river_segments (river_code)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS river_segments_river_code_index');
        DB::statement('ALTER TABLE river_segments DROP CONSTRAINT IF EXISTS river_segments_domain_check');
        Schema::table('river_segments', function (Blueprint $table) {
            $table->dropColumn(['river_code', 'basin_code', 'domain', 'strahler']);
        });
    }
};
