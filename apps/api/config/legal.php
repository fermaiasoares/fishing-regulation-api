<?php

return [
    // Canonical catalog exported by services/legal-catalog/catalog.py --export.
    'catalog_path' => env('LEGAL_CATALOG_PATH', base_path('../../catalog/legal/mg/catalog.json')),

    // Official hydrographic districts acquired by services/geo-ingestion/legal_areas.py.
    'geo_path' => env('LEGAL_GEO_PATH', base_path('../../catalog/geo/mg')),
];
