# Modelo geográfico

O banco registra a fonte e a versão de cada feição. `geographic_datasets` identifica o dataset e o publicador. `geographic_dataset_versions` registra a versão, a URL do artefato de origem e, quando disponíveis, a data de coleta, a licença, o CRS de origem e o SHA-256 do artefato. Correções ou atualizações da fonte entram como uma nova versão. O identificador da feição na fonte é único dentro da versão e da tabela. As chaves estrangeiras impedem excluir um dataset ou uma versão enquanto houver registros vinculados.

Bacias usam `MultiPolygon` e segmentos usam `MultiLineString`, ambos em WGS 84 (EPSG:4326) e com índices GiST. Uma futura ingestão deve verificar o CRS na fonte, rejeitar CRS ausente ou ambíguo, transformar a geometria com `ST_Transform` quando necessário e usar `ST_Multi` para normalizar as geometrias simples. `ST_SetSRID` apenas rotula coordenadas com um CRS já conhecido. O banco rejeita SRID ou tipo incorretos, geometrias inválidas ou vazias e coordenadas fora dos limites de WGS 84.

O serviço interno `App\Geography\HydrographicLookup::at` recebe latitude, longitude e o ID da versão. Na geometria, a ordem é longitude, latitude. A busca inclui a borda da bacia (`ST_Covers`) e a interseção exata com a linha do rio (`ST_Intersects`). Uma posição próxima de um rio pode não intersectar sua geometria. O retorno `geographic_match: false` indica ausência de feições correspondentes nessa versão e deixa a situação legal indeterminada.

O esquema foi preparado para Minas Gerais. A cobertura só será conhecida após a ingestão de fontes oficiais. Os testes usam geometrias sintéticas em transações. O rollback remove as tabelas da entrega na ordem inversa e preserva a extensão PostGIS, que pode servir a outros objetos do banco.


## Áreas legais de Minas Gerais

As áreas do catálogo jurídico (`catalog/legal/mg/basins.toml`) são ligadas ao mapa pelas Circunscrições Hidrográficas (CHs) oficiais.

| Item | Valor |
| --- | --- |
| Fonte | IDE-Sisema / Igam, camada `ide_1108_mg_circunscricoes_hidrograficas_pol` |
| Base legal | DN CERH-MG nº 66/2020, alterada pela DN CERH-MG nº 95/2024 |
| Escala / CRS de origem | 1:100.000, SIRGAS 2000 (EPSG:4674); transformada para EPSG:4326 no PostGIS |
| Arquivo | `catalog/geo/mg/circunscricoes_hidrograficas.geojson.gz` + `manifest.json` (SHA-256 do arquivo e do conteúdo) |
| Feições | 43 CHs: São Francisco 9 + Paraopeba 1, Grande 8, Paranaíba 3, Doce 6, Leste 15, Piracicaba/Jaguari 1 |

Fluxo:

1. `services/geo-ingestion/legal_areas.py` (workflow `geo-legal-areas`) baixa a camada, valida estrutura e geometria, aplica `catalog/legal/mg/area_mapping.toml` e grava o arquivo bruto. Uma CH sem regra de ligação, ou cujo nome ou atributo `bacia_fede` contradiga a ligação, faz a execução falhar.
2. O `timeStamp` que o GeoServer acrescenta a cada resposta não entra no `content_sha256`, então um novo download só gera nova versão se os dados mudarem.
3. `legal_areas.py --check` refaz a validação sem rede; o CI executa.
4. `php artisan geo:import-legal-areas` carrega as CHs em `legal_area_geometries`, corrigindo anéis inválidos com `ST_MakeValid`. Cada versão do dataset é carregada uma vez; uma versão nova substitui as geometrias da anterior na mesma transação.

A área `mg` não tem geometria própria: todo ponto dentro de uma CH herda `mg` pela hierarquia (`doce` → `leste` → `mg`).
