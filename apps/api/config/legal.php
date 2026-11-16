<?php

return [
    // One directory per jurisdiction ("br", "mg", ...), each with the catalog.json
    // exported by services/legal-catalog/catalog.py --export.
    'catalog_dir' => env('LEGAL_CATALOG_DIR', base_path('../../catalog/legal')),

    // Official hydrographic districts acquired by services/geo-ingestion/legal_areas.py.
    'geo_path' => env('LEGAL_GEO_PATH', base_path('../../catalog/geo/mg')),

    // Nearest river (ANA BHAE segments). The river is reported up to "search"
    // metres away; its domain fills water_domain only within "match" metres,
    // since a farther segment may be another river (ADR 0002).
    'river_search_meters' => (int) env('LEGAL_RIVER_SEARCH_METERS', 2000),
    'river_match_meters' => (int) env('LEGAL_RIVER_MATCH_METERS', 500),
];
