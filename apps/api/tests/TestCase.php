<?php

namespace Tests;

use App\Console\Commands\ImportLegalCatalog;
use App\Legal\CatalogImporter;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Path of the repository catalog of one jurisdiction ("br", "mg"). */
    protected function catalogPath(string $jurisdiction): string
    {
        return config('legal.catalog_dir')."/{$jurisdiction}/catalog.json";
    }

    /**
     * Imports every repository catalog (federal and states).
     *
     * @return array<string, array{version_id: int, jurisdiction: string, created: bool, published: bool, checksum_sha256: string}>
     */
    protected function importLegalCatalogs(bool $publish = true): array
    {
        $results = [];
        foreach (ImportLegalCatalog::catalogPaths(config('legal.catalog_dir')) as $path) {
            $result = (new CatalogImporter)->import($path, $publish);
            $results[$result['jurisdiction']] = $result;
        }

        return $results;
    }
}
