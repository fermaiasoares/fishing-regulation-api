# ADR 0001 — Várias jurisdições e rios que cruzam estados

- Status: aceito (15/11/2026)
- Contexto do pedido: a API será usada por clientes em todo o Brasil e todas as bacias serão mapeadas aos poucos. Um rio pode passar por mais de um estado ou servir de divisa entre estados.

## Problema

Até a Sprint 4 o código assumia uma única jurisdição:

- `FishingEvaluator::JURISDICTION = 'mg'` fixo; uma avaliação usava um só catálogo.
- Códigos de área (`doce`, `grande`, `sao_francisco`) só eram únicos dentro de MG, e `legal_area_geometries` não sabia a qual jurisdição a área pertencia.
- A hierarquia terminava em `mg`, então normas federais (IBAMA, MMA) estavam gravadas no catálogo de MG com `basins = ["mg"]`. Ao incluir SP ou GO, elas teriam de ser copiadas em cada estado.
- A resposta tinha um único objeto `catalog`.

Do ponto de vista jurídico (a conferir caso a caso):

- Rios que banham mais de um estado ou servem de divisa são bens da União (CF, art. 20, III); os demais são dos estados (art. 26, I).
- A competência para legislar sobre pesca é concorrente (CF, art. 24, VI): normas federais valem para a bacia inteira, em todos os estados que ela atravessa; cada estado pode ser mais restritivo no próprio território.
- Num ponto do Rio Grande (divisa MG/SP) ou do Paranaíba (MG/GO/MS) podem incidir ao mesmo tempo a norma federal da bacia e as normas de mais de um estado.

## Decisão

1. **Uma jurisdição, um catálogo.** `catalog/legal/br` guarda as normas federais; `catalog/legal/<uf>` as de cada estado. Cada catálogo é versionado e publicado de forma independente (`legal_catalog_versions.jurisdiction`).
2. **Códigos de área qualificados.** No export, no banco e na API toda área é `<jurisdição>:<id>` (`mg:doce`, `br:sao_francisco`); a raiz de cada catálogo é o próprio código (`mg`, `br`). Nos arquivos TOML os ids continuam locais; referências a outra jurisdição são escritas qualificadas.
3. **Ligação explícita entre jurisdições (`within`).** Uma área estadual declara em quais áreas federais está contida, por exemplo `mg:sao_francisco` → `br:sao_francisco`. As regras federais da bacia alcançam o ponto por essa ligação, sem duplicação. Enquanto não houver geometria nacional (ANA), a área federal é "derivada": existe onde existirem as áreas estaduais ligadas a ela.
4. **Combinação conservadora.** Para um ponto, valem o catálogo federal e os catálogos dos estados cujas áreas contêm o ponto. Qualquer proibição aplicável → `prohibited`; senão as condições de todas as jurisdições se somam → `conditional`; senão `undetermined`. Cada regra e cada condição diz de qual jurisdição veio.
5. **O catálogo federal é obrigatório.** Sem catálogo `br` publicado a API responde `503`, porque sem ele espécies ameaçadas poderiam sair `conditional`. Ponto em área de um estado sem catálogo publicado → `undetermined`, com a lacuna explicada.
6. **Contrato já preparado para o país.** A resposta passa a ter `catalogs[]`, `location.states`, `location.jurisdictions`, `location.water_domain` e `jurisdiction` em cada regra e condição. O formato do export muda para `fishing-regulation-legal-catalog/2`.

## Próximas etapas (fora desta mudança)

O plano de execução destas etapas em escala nacional está no [ADR 0002](0002-cobertura-nacional.md).

| Etapa | O que resolve |
| --- | --- |
| Malha estadual do IBGE no PostGIS | `location.states` passa a vir da divisa oficial, e não só das áreas legais carregadas |
| Faixa de divisa (distância configurável) | Ponto perto de divisa aplica os estados dos dois lados e avisa em `gaps` |
| Dominialidade da ANA (federal × estadual) | Preenche `location.water_domain`; hoje é sempre `undetermined` |
| Geometria nacional das bacias (ANA/BHO) | Áreas federais deixam de ser derivadas das estaduais |
| Catálogos de SP, GO, BA… | Um diretório por estado, com o mesmo formato |
| Licença de pesca por estado | Avaliar se a licença do pescador vale no estado do ponto (campo opcional `license_state`); depende de verificação jurídica |

## Consequências

- Incluir um estado não exige mudar código: novo diretório de catálogo, mapeamento de áreas e ligações `within`.
- Ids de regra continuam únicos em todo o repositório (verificado em teste), para que `code` identifique a regra sem ambiguidade.
- Mudança incompatível do contrato feita antes de qualquer cliente consumir a API.
