<?php

namespace App\Console\Commands;

use App\Legal\CatalogImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ImportLegalCatalog extends Command
{
    protected $signature = 'legal:import
        {path? : catalog.json exported by services/legal-catalog (defaults to config legal.catalog_path)}
        {--publish : make this version the one the API answers from}';

    protected $description = 'Import a versioned legal catalog into the database';

    public function handle(CatalogImporter $importer): int
    {
        $path = $this->argument('path') ?? config('legal.catalog_path');

        try {
            $result = $importer->import($path, (bool) $this->option('publish'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            $result['created'] ? 'Imported catalog version %d (sha256 %s)%s.' : 'Catalog already imported as version %d (sha256 %s)%s.',
            $result['version_id'],
            substr($result['checksum_sha256'], 0, 12),
            $result['published'] ? ', published' : '',
        ));

        return self::SUCCESS;
    }
}
