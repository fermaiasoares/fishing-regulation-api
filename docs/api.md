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
| `species` | texto | nome comum ou científico, 2 a 120 caracteres; obrigatório sem `species_id` |
| `species_id` | texto | id de uma espécie de `GET /api/species`; obrigatório sem `species` |
| `date` | `AAAA-MM-DD` | opcional; padrão: hoje em America/Sao_Paulo |

Limite: 60 requisições por minuto por cliente.

### Resposta `200`

| Campo | Conteúdo |
| --- | --- |
| `status` | `prohibited`, `conditional` ou `undetermined` |
| `location.areas` | áreas legais do ponto, da mais específica à mais ampla, com `code`, `name` e `jurisdiction` (ex.: `mg:doce`, `mg:leste`, `mg`, `br:leste`, `br`) |
| `location.states` | estados cujas áreas contêm o ponto (ex.: `["mg"]`); perto de divisas poderá ter mais de um |
| `location.jurisdictions` | jurisdições cujas normas foram avaliadas (ex.: `["br", "mg"]`) |
| `location.water_domain` | domínio do corpo d'água (`federal`, `state` ou `undetermined`); por ora sempre `undetermined` |
| `species.id`, `species.name`, `species.scientific_name` | quando a consulta usa `species_id` |
| `species.threatened` | categoria na Lista Nacional de ameaçadas, quando houver |
| `species.classification` | `native`, `non_native`, `excluded` (excluída de uma permissão), `conflicting` ou `unknown`, com as entradas das listas oficiais que casaram |
| `rules` | regras que decidiram o status: `jurisdiction`, ato, artigos, período, `verified` e `evidence` (segmento + trecho literal da norma) |
| `conditions` | condições a cumprir quando `conditional`, cada uma com `text`, `rule` e `jurisdiction` |
| `location_restrictions` | faixas, lagoas, trechos e UCs ativos na data, ainda não verificados geograficamente, cada um com `jurisdiction` |
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

- `422`: entrada inválida, com `errors` por campo; inclui `species_id` que não existe nos catálogos publicados.
- `503`: catálogo federal (`br`) não publicado; a API não avalia, porque sem ele uma espécie ameaçada poderia sair `conditional`.

### Limitações conhecidas

- As áreas legais vêm das 43 Circunscrições Hidrográficas oficiais de MG (IDE-Sisema/Igam, `catalog/geo/mg`), carregadas com `php artisan geo:import-legal-areas`. Pontos fora de MG retornam `undetermined`.
- Rios de divisa e de domínio federal: o estado do ponto vem só das áreas legais carregadas. A malha estadual do IBGE, a faixa de divisa e a dominialidade da ANA são as próximas etapas do [ADR 0001](adr/0001-jurisdicoes.md).
- A CH PJ1 (rios Piracicaba e Jaguari, que drenam para o Tietê) não tem regras até a decisão sobre seu enquadramento; consultas nela retornam `undetermined`.
- A escala da camada é 1:100.000: pontos a poucas centenas de metros de um divisor de águas podem cair na bacia vizinha.
- Não há lista oficial de nativas do São Francisco, Grande, Paranaíba e Leste no catálogo; nativas dessas bacias ficam `unknown` e, na piracema, `undetermined`.
- Nomes científicos grafados com erro nas portarias ("Cicla spp.") casam também pelo nome válido ("Cichla spp."), pela tabela `spelling` das listas ([catálogo jurídico](legal-catalog.md)). As correções aguardam revisão.

## `GET /api/species`

Busca espécies citadas pelos catálogos publicados, por qualquer nome: comum, científico ou a grafia da norma. Serve para o cliente escolher a espécie antes de consultar; constar aqui não diz nada sobre onde a pesca é permitida.

```
GET /api/species?q=tucunaré&limit=20
```

| Parâmetro | Regra |
| --- | --- |
| `q` | 2 a 120 caracteres, sem distinguir acentos nem maiúsculas |
| `limit` | 1 a 100; padrão 20 |

Ordem: nome igual à busca, depois nome ou palavra que começa com ela, depois qualquer trecho. Um binômio que nenhuma lista cita (`Cichla kelberi`) também traz a entrada do gênero (`Cichla spp.`). Limite: 120 requisições por minuto.

Cada item:

| Campo | Conteúdo |
| --- | --- |
| `id` | identificador estável derivado do nome científico (`cichla-spp`, `colossoma-macropomum`) ou do nome comum, quando a norma não dá o científico (`carpas`) |
| `name` | primeiro nome comum citado, ou o científico |
| `scientific_name` | nome válido, já corrigido pela tabela `spelling`; `null` quando a norma só dá nome comum |
| `rank` | `species`, `genus` (`spp.`: qualquer espécie do gênero), `unidentified` (`sp.`), `hybrid` ou `common_name` |
| `common_names` | nomes comuns citados nas listas |
| `written_as` | grafias da norma diferentes do nome válido (ex.: `Cicla spp.`) |
| `family`, `group` | da Lista Nacional de ameaçadas, quando a espécie consta dela |
| `notes` | complemento do nome na norma (ex.: `todas as espécies`) |
| `threatened` | entradas na Lista Nacional: `category`, `list`, `act`, `valid_from`, `areas` |
| `lists` | entradas nas listas das portarias: `jurisdiction`, `list`, `act`, `areas`, `classification` e o texto literal em `entry` |

As espécies ameaçadas só têm nome científico, porque o Anexo I da Portaria GM/MMA 1.667/2026 não traz nomes comuns: "surubim" não acha *Pseudoplatystoma corruscans*.

`GET /api/species/{id}` retorna um item. `404` se o id não existir; `503` sem catálogo publicado.

## `GET /api/fishing/species`

Lista as espécies citadas pelas listas oficiais que valem no ponto, cada uma com o status na data, da mais restrita à menos restrita. Responde, por exemplo, "o que é proibido pescar aqui hoje".

```
GET /api/fishing/species?latitude=-19.79&longitude=-42.14&date=2026-12-15&status=prohibited,conditional
```

| Parâmetro | Regra |
| --- | --- |
| `latitude`, `longitude`, `date` | como em `POST /api/fishing/evaluate` |
| `status` | opcional; `prohibited`, `conditional` e/ou `undetermined`, separados por vírgula |

Limite: 60 requisições por minuto.

### Resposta `200`

| Campo | Conteúdo |
| --- | --- |
| `date`, `location`, `catalogs`, `disclaimer` | como em `POST /api/fishing/evaluate` |
| `defaults` | status e regras de uma espécie `native` e de uma `non_native` que não estejam nas listas; `null` fora das áreas mapeadas |
| `summary` | contagem por status de todas as espécies do ponto, antes do filtro `status` |
| `species` | uma linha por espécie: `id`, `name`, `scientific_name`, `rank`, `common_names`, `family`, `classification`, `threatened` (`category`, `list`, `act`), `status`, `rules` (códigos) e `gaps` |
| `rules` | as regras citadas nas linhas, uma vez cada, no formato de `POST /api/fishing/evaluate` e com `conditions` |
| `location_restrictions`, `gaps` | do ponto, como em `POST /api/fishing/evaluate` |

Cada linha tem o mesmo status e as mesmas regras que `POST /api/fishing/evaluate` com o `species_id` dela. Linhas `prohibited` não repetem as lacunas de classificação, porque a proibição já decide.

A lista só traz espécies citadas por alguma norma. As 351 espécies da Lista Nacional de ameaçadas aparecem em qualquer ponto, sempre `prohibited` a partir de 27/10/2026. Para uma espécie fora das listas, `defaults` mostra o que valeria se ela fosse nativa ou não nativa; `POST /api/fishing/evaluate` continua classificando-a como `unknown`.
