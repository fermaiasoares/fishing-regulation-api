# API pública

## `POST /api/fishing/evaluate`

Avalia se a pesca de uma espécie é proibida ou condicionada num ponto numa data, combinando as normas federais e as dos estados cujas áreas contêm o ponto. Usa apenas as versões publicadas dos catálogos jurídicos (`php artisan legal:import --publish`), uma por jurisdição: `br` (federal) e uma por estado (hoje só `mg`). Ver [ADR 0001](adr/0001-jurisdicoes.md).

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
| `location.areas` | áreas legais do ponto, da mais específica à mais ampla, com `code`, `name` e `jurisdiction` (ex.: `mg:doce`, `mg:leste`, `mg`, `br:leste`, `br`) |
| `location.districts` | feições oficiais que localizaram o ponto, com `code`, `name`, `area` e `source` (em MG, a Circunscrição Hidrográfica, ex.: `DO5: Rio Caratinga`) |
| `location.states` | estados cujas áreas contêm o ponto (ex.: `["mg"]`); perto de divisas poderá ter mais de um |
| `location.jurisdictions` | jurisdições cujas normas foram avaliadas (ex.: `["br", "mg"]`) |
| `location.water_domain` | domínio do rio (`federal`, `state` ou `undetermined`), tirado de `nearest_river` quando ele está a até 500 m do ponto |
| `location.nearest_river` | trecho de rio da ANA (BHAE) mais próximo, até 2 km: `name` (pode ser nulo), `code` (Pfafstetter), `domain`, `segment`, `distance_m`, `source`; `null` se não houver |
| `species.threatened` | categoria na Lista Nacional de ameaçadas, quando houver |
| `species.classification` | `native`, `non_native`, `excluded` (excluída de uma permissão), `conflicting` ou `unknown`, com as entradas das listas oficiais que casaram |
| `rules` | regras que decidiram o status: `jurisdiction`, ato, artigos, período, `verified` e `evidence` (segmento + trecho literal da norma) |
| `rules[].citation` | referência formal para o pescador localizar a norma sem link: tipo, número e data do ato, artigos e órgão expedidor (ex.: `Portaria IEF nº 154, de 13/10/2011, art. 4º, II (IEF/MG)`) |
| `rules[].act` | `code`, `title`, `kind`, `issuer` (órgão expedidor), `published_on` e `url` (opcional: link informativo, pode não abrir; a referência que vale é `citation`) |
| `conditions` | condições a cumprir quando `conditional`, cada uma com `text`, `rule` e `jurisdiction` |
| `location_restrictions` | faixas, lagoas, trechos e UCs ativos na data, ainda não verificados geograficamente, cada um com `jurisdiction` e `citation` |
| `gaps` | o que impede uma resposta mais firme |
| `catalogs` | um item por catálogo usado: `jurisdiction`, `version_id`, `checksum_sha256`, `published_at` |
| `disclaimer` | aviso de que a resposta não substitui a norma oficial |

### Regras de decisão

1. Áreas: o ponto é localizado em `legal_area_geometries` e recebe as áreas-mãe (`mg:doce` → `mg:leste` → `mg`) e as áreas federais a que elas estão ligadas por `within` (`mg:leste` → `br:leste`, `mg` → `br`). Sem área mapeada: `undetermined`. Área de um estado sem catálogo publicado: `undetermined`, com a lacuna.
2. Espécie: classificada pelas listas oficiais das áreas do ponto. Nome fora das listas é `unknown`, nunca presumido não nativo. `Gênero spp.` casa com qualquer espécie do gênero; `sp.` não.
3. Jurisdições: valem as regras de todos os catálogos alcançados. Uma proibição de qualquer jurisdição prevalece; condições de jurisdições diferentes se somam.
4. Regras de bacia ativas na data: qualquer proibição aplicável → `prohibited`; senão, condição aplicável → `conditional` (condições somadas de todas as normas); senão `undetermined`.
5. Espécie `unknown` ou `conflicting`: só há resposta se nativa e não nativa levarem ao mesmo status.
6. Espécie ameaçada (Lista Nacional, Portaria GM/MMA 1.667/2026, categorias EW, CR, EN e VU): `prohibited` o ano todo em qualquer bacia, prevalecendo sobre permissões estaduais (ex.: tambaqui). O casamento é pelo nome científico, informado ou obtido das listas estaduais pelo nome comum. Antes de 27/10/2026, a espécie fica `undetermined`.
7. Espécie `excluded` (ex.: piauçu na Portaria IEF 156/2011): `prohibited` enquanto a permissão de que foi excluída for a única via.
8. Períodos que terminam em 28/02 incluem 29/02 em anos bissextos (decisão a aprovar).

### Outros códigos

- `422`: entrada inválida, com `errors` por campo.
- `503`: catálogo federal (`br`) não publicado; a API não avalia, porque sem ele uma espécie ameaçada poderia sair `conditional`.

### Limitações conhecidas

- As áreas legais vêm das 43 Circunscrições Hidrográficas oficiais de MG (IDE-Sisema/Igam, `catalog/geo/mg`), carregadas com `php artisan geo:import-legal-areas`. Pontos fora de MG retornam `undetermined`.
- Rios de divisa: o estado do ponto vem só das áreas legais carregadas. A malha estadual do IBGE e a faixa de divisa são as próximas etapas do [ADR 0002](adr/0002-cobertura-nacional.md). O domínio do rio já vem da ANA, mas ainda não muda nenhuma regra.
- A CH PJ1 (rios Piracicaba e Jaguari, que drenam para o Tietê) não tem regras até a decisão sobre seu enquadramento; consultas nela retornam `undetermined`.
- A escala da camada é 1:100.000: pontos a poucas centenas de metros de um divisor de águas podem cair na bacia vizinha.
- Não há lista oficial de nativas do São Francisco, Grande, Paranaíba e Leste no catálogo; nativas dessas bacias ficam `unknown` e, na piracema, `undetermined`.
- O nome científico do tucunaré na Portaria IEF 154/2011 está grafado "Cicla spp."; no São Francisco ele só casa pelo nome comum.
