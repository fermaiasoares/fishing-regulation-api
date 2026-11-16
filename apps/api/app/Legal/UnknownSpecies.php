<?php

namespace App\Legal;

use RuntimeException;

/** A species id that is not a taxon of the published catalogs. */
final class UnknownSpecies extends RuntimeException
{
    public function __construct(public readonly string $speciesId)
    {
        parent::__construct("Unknown species id: {$speciesId}");
    }
}
