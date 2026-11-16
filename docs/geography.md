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

## Rio mais próximo (ANA, BHAE)

Os trechos de drenagem da Base Hidrográfica Atlas-Estudos (BHAE) da ANA preenchem `river_segments` em todo o
país ([ADR 0002](adr/0002-cobertura-nacional.md)).

| Item | Valor |
| --- | --- |
| Fonte | ANA, BHAE v1.0, camada `geoft_bhae_trecho_drenagem` ([metadados SNIRH](https://metadados.snirh.gov.br/geonetwork/srv/api/records/8ad07d33-1677-481d-bc61-ed5ca204926f)) |
| Conteúdo | Cursos d'água com 10 km² ou mais de área de drenagem e todos os de domínio da União |
| CRS de origem | SIRGAS 2000 (EPSG:4674); transformado para EPSG:4326 no PostGIS |
| Registro | `catalog/geo/br/bhae/manifest.json` (URL, SHA-256, tamanho, datas, contagens). O GeoPackage (cerca de 1 GB) fica no arquivo de originais, nunca no git |
| Campos usados | `cotrecho` (id), `noriocomp` (nome), `cocursodag` e `cobacia` (Pfafstetter), `dedominial` (domínio), `nustrahler` |
| Carga de 16/11/2026 | 482.349 trechos: 401.352 estaduais, 22.926 federais, 56.779 internacionais (bacias que seguem fora do Brasil) e 1.292 de linha de costa |

```sh
C="docker compose --env-file infrastructure/.env -f infrastructure/compose.yaml"
$C up -d --wait db && $C up -d geo-worker
$C exec geo-worker python bhae.py acquire --archive /archive --manifest-dir /catalog/geo/br/bhae
$C exec geo-worker python bhae.py load --archive /archive --manifest-dir /catalog/geo/br/bhae
```

`acquire` baixa o arquivo em `.archive/sha256/<2>/<hash>.gpkg` (o diretório `ARCHIVE_DIR`), confere camada,
CRS, campos, unicidade de `cotrecho` e os valores de `dedominial`, e grava o manifesto. Um valor de domínio
desconhecido faz a execução falhar. `check` refaz a conferência do hash sem rede. `load` copia a camada para uma
tabela temporária com `ogr2ogr`, confere a contagem e substitui os trechos da versão anterior numa transação.
Cada versão é carregada uma vez.

`App\Geography\RiverLookup::nearest` devolve o trecho mais próximo do ponto até `LEGAL_RIVER_SEARCH_METERS`
(padrão 2.000 m), com a distância geodésica, em cerca de 50 ms. O ponto vai escrito direto na consulta: por uma CTE, o PostGIS deixa de usar o índice espacial e varre a tabela (1,1 s). A API mostra esse trecho em `location.nearest_river`. Ela só usa o
domínio dele em `location.water_domain` quando a distância é de até `LEGAL_RIVER_MATCH_METERS` (padrão 500 m):
mais longe, o trecho pode ser de outro rio. Rio internacional conta como federal (CF, art. 20, III). Numa represa larga, o trecho passa pelo meio do espelho d'água, e
um ponto na margem pode ficar a mais de 500 m dele. A camada de massas d'água resolve esse caso numa etapa
seguinte.
