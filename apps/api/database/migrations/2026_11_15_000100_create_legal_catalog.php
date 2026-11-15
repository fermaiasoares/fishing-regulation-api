<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Legal catalog imported from catalog/legal/<jurisdiction>/catalog.json.
 *
 * Every import is an immutable version identified by the SHA-256 of the file.
 * The version with the latest published_at is the one the API answers from.
 * Rules only restrict: the database refuses any effect other than
 * "prohibited" or "conditional".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_catalog_versions', function (Blueprint $table) {
            $table->id();
            $table->string('jurisdiction', 32);
            $table->string('format');
            $table->char('checksum_sha256', 64)->unique();
            $table->unsignedInteger('act_count');
            $table->unsignedInteger('rule_count');
            $table->unsignedInteger('verified_rule_count');
            $table->timestampTz('imported_at');
            $table->timestampTz('published_at')->nullable();
            $table->index(['jurisdiction', 'published_at']);
        });

        Schema::create('legal_acts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_version_id')->constrained('legal_catalog_versions')->cascadeOnDelete();
            $table->string('code');
            $table->text('title');
            $table->string('issuer');
            $table->string('jurisdiction', 16);
            $table->string('kind', 16)->default('norm');
            $table->string('status', 16)->default('in_force');
            $table->date('published_on')->nullable();
            $table->text('official_url')->nullable();
            $table->text('read_from')->nullable();
            $table->string('text_status', 32);
            $table->text('in_force_note')->nullable();
            $table->jsonb('source')->nullable();
            $table->unique(['catalog_version_id', 'code']);
        });

        Schema::create('legal_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_version_id')->constrained('legal_catalog_versions')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('parent_code')->nullable();
            $table->text('definition');
            $table->string('geo_mapping', 16);
            $table->unique(['catalog_version_id', 'code']);
        });

        Schema::create('legal_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_version_id')->constrained('legal_catalog_versions')->cascadeOnDelete();
            $table->foreignId('legal_act_id')->constrained('legal_acts')->cascadeOnDelete();
            $table->string('code');
            $table->text('articles');
            $table->string('period_kind', 16);
            $table->char('period_start', 5)->nullable();
            $table->char('period_end', 5)->nullable();
            $table->string('species_scope', 16);
            $table->string('scope', 16);
            $table->string('effect', 16);
            $table->text('summary');
            $table->jsonb('conditions')->default('[]');
            $table->jsonb('stretches')->default('[]');
            $table->string('geo_mapping', 16)->nullable();
            $table->boolean('verified');
            $table->unique(['catalog_version_id', 'code']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE legal_rules
            ADD CONSTRAINT legal_rules_effect_check CHECK (effect IN ('prohibited', 'conditional')),
            ADD CONSTRAINT legal_rules_species_check CHECK (species_scope IN ('native', 'non_native', 'all')),
            ADD CONSTRAINT legal_rules_scope_check CHECK (scope IN ('basin', 'buffer', 'stretch')),
            ADD CONSTRAINT legal_rules_period_check CHECK (
                (period_kind = 'permanent' AND period_start IS NULL AND period_end IS NULL)
                OR (period_kind = 'annual' AND period_start ~ '^\d{2}-\d{2}$' AND period_end ~ '^\d{2}-\d{2}$')
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE legal_catalog_versions
            ADD CONSTRAINT legal_catalog_versions_sha256_check CHECK (checksum_sha256 ~ '^[0-9a-f]{64}$')
            SQL);

        Schema::create('legal_rule_areas', function (Blueprint $table) {
            $table->foreignId('legal_rule_id')->constrained('legal_rules')->cascadeOnDelete();
            $table->foreignId('legal_area_id')->constrained('legal_areas')->cascadeOnDelete();
            $table->primary(['legal_rule_id', 'legal_area_id']);
        });

        Schema::create('legal_rule_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('legal_rule_id')->constrained('legal_rules')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('segment');
            $table->text('quote');
            $table->unique(['legal_rule_id', 'position']);
        });

        Schema::create('legal_species_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_version_id')->constrained('legal_catalog_versions')->cascadeOnDelete();
            $table->foreignId('legal_act_id')->constrained('legal_acts')->cascadeOnDelete();
            $table->string('code');
            $table->jsonb('payload');
            $table->unique(['catalog_version_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_species_lists');
        Schema::dropIfExists('legal_rule_evidence');
        Schema::dropIfExists('legal_rule_areas');
        Schema::dropIfExists('legal_rules');
        Schema::dropIfExists('legal_areas');
        Schema::dropIfExists('legal_acts');
        Schema::dropIfExists('legal_catalog_versions');
    }
};
