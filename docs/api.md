# API pública

## `POST /api/fishing/evaluate`

Avalia se a pesca de uma espécie é proibida ou condicionada num ponto de Minas Gerais numa data, usando apenas a versão publicada do catálogo jurídico (`php artisan legal:import --publish`).

A API **nunca responde `allowed`**: o catálogo só registra restrições. Sem restrição aplicável, ou com informação insuficiente, a resposta é `undetermined` com a lista de lacunas.

### Requisição

```json
{ "latitude": -19.79, "longitude": -42.14, "species": "tilápia", "date": "2026-12-15" }
```

| Campo | Tipo | Regra |
| --- | --- | --- |
| `latitude` | número | -90 a 90 (WGS 84) |
| `longitude` | número | -180 a 180 (WGS 84) |
| `species` | texto | nome comum ou científico, 2 a 120 caracteres |
| `date` | `AAAA-MM-DD` | opcional; padrão: hoje em America/Sao_Paulo |

Limite: 60 requisições por minuto por cliente.

### Resposta `200`

| Campo | Conteúdo |
| --- | --- |
| `status` | `prohibited`, `conditional` ou `undetermined` |
| `location.areas` | áreas legais do ponto, da mais específica à mais ampla (ex.: `doce`, `leste`, `mg`) |
| `species.classification` | `native`, `non_native`, `excluded` (excluída de uma permissão), `conflicting` ou `unknown`, com as entradas das listas oficiais que casaram |
| `rules` | regras que decidiram o status: ato, artigos, período, `verified` e `evidence` (segmento + trecho literal da norma) |
| `conditions` | condições a cumprir quando `conditional` |
| `location_restrictions` | faixas, lagoas, trechos e UCs ativos na data, ainda não verificados geograficamente |
| `gaps` | o que impede uma resposta mais firme |
| `catalog` | versão, SHA-256 e data de publicação do catálogo usado |
| `disclaimer` | aviso de que a resposta não substitui a norma oficial |

### Regras de decisão

1. Áreas: o ponto é localizado em `legal_area_geometries` e recebe as áreas-mãe (`doce` → `leste` → `mg`). Sem área mapeada: `undetermined`.
2. Espécie: classificada pelas listas oficiais das áreas do ponto. Nome fora das listas é `unknown`, nunca presumido não nativo. `Gênero spp.` casa com qualquer espécie do gênero; `sp.` não.
3. Regras de bacia ativas na data: qualquer proibição aplicável → `prohibited`; senão, condição aplicável → `conditional` (condições somadas de todas as normas); senão `undetermined`.
4. Espécie `unknown` ou `conflicting`: só há resposta se nativa e não nativa levarem ao mesmo status.
5. Espécie `excluded` (ex.: piauçu na Portaria IEF 156/2011): `prohibited` enquanto a permissão de que foi excluída for a única via.
6. Períodos que terminam em 28/02 incluem 29/02 em anos bissextos (decisão a aprovar).

### Outros códigos

- `422`: entrada inválida, com `errors` por campo.
- `503`: nenhum catálogo publicado; a API não avalia.

### Limitações conhecidas

- As geometrias das áreas legais dependem da ingestão da Sprint 2 (BHB250/BHO). Até lá, toda consulta real retorna `undetermined` por falta de área mapeada.
- Não há lista oficial de nativas do São Francisco, Grande, Paranaíba e Leste no catálogo; nativas dessas bacias ficam `unknown` e, na piracema, `undetermined`.
- O nome científico do tucunaré na Portaria IEF 154/2011 está grafado "Cicla spp."; no São Francisco ele só casa pelo nome comum.
