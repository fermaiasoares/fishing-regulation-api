<?php

return [
    // One directory per jurisdiction ("br", "mg", ...), each with the catalog.json
    // exported by services/legal-catalog/catalog.py --export.
    'catalog_dir' => env('LEGAL_CATALOG_DIR', base_path('../../catalog/legal')),

    // Official hydrographic districts acquired by services/geo-ingestion/legal_areas.py.
    'geo_path' => env('LEGAL_GEO_PATH', base_path('../../catalog/geo/mg')),
];
