<?php

namespace App\Console\Commands;

use App\Legal\CatalogImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ImportLegalCatalog extends Command
{
    protected $signature = 'legal:import
        {path? : one catalog.json exported by services/legal-catalog (defaults to every <jurisdiction>/catalog.json under config legal.catalog_dir)}
        {--publish : make the imported versions the ones the API answers from}';

    protected $description = 'Import versioned legal catalogs into the database';

    public function handle(CatalogImporter $importer): int
    {
        $paths = $this->argument('path') !== null
            ? [$this->argument('path')]
            : self::catalogPaths(config('legal.catalog_dir'));
        if ($paths === []) {
            $this->error('No catalog.json found under '.config('legal.catalog_dir'));

            return self::FAILURE;
        }

        foreach ($paths as $path) {
            try {
                $result = $importer->import($path, (bool) $this->option('publish'));
            } catch (InvalidArgumentException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->info(sprintf(
                $result['created'] ? '[%s] Imported catalog version %d (sha256 %s)%s.' : '[%s] Catalog already imported as version %d (sha256 %s)%s.',
                $result['jurisdiction'],
                $result['version_id'],
                substr($result['checksum_sha256'], 0, 12),
                $result['published'] ? ', published' : '',
            ));
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    public static function catalogPaths(string $directory): array
    {
        $paths = glob(rtrim($directory, '/').'/*/catalog.json') ?: [];
        sort($paths);

        return $paths;
    }
}
