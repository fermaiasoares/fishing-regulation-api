<?php

namespace App\Console\Commands;

use App\Geography\LegalAreaImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ImportLegalAreas extends Command
{
    protected $signature = 'geo:import-legal-areas
        {directory? : folder with manifest.json and the districts file (defaults to config legal.geo_path)}';

    protected $description = 'Load the official hydrographic districts of Minas Gerais as legal area footprints';

    public function handle(LegalAreaImporter $importer): int
    {
        $directory = $this->argument('directory') ?? config('legal.geo_path');

        try {
            $result = $importer->import($directory);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $result['created']) {
            $this->info("Legal areas already imported as dataset version {$result['dataset_version_id']}.");

            return self::SUCCESS;
        }

        $this->info("Imported {$result['features']} districts as dataset version {$result['dataset_version_id']}.");
        foreach ($result['by_area'] as $area => $count) {
            $this->line("  {$area}: {$count}");
        }
        if ($result['repaired'] !== []) {
            $this->warn('Geometries repaired with ST_MakeValid: '.implode(', ', $result['repaired']));
        }

        return self::SUCCESS;
    }
}
