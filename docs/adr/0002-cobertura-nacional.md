# ADR 0002: Cobertura nacional, da coleta das normas à carga no banco

- Status: aceito (15/11/2026)
- Contexto do pedido: a meta é cobrir todo o Brasil. Minas Gerais continua sendo o estado de desenvolvimento.
  A API atende só o aplicativo de pesca do projeto. Ela organiza normas oficiais e não substitui a conferência
  pelo pescador, que usa a referência formal de cada regra (`citation`).

## Problema

O pipeline atual funciona para um estado, mas três partes não escalam para 27:

1. **Descoberta.** Os atos e as URLs de download são escritos à mão no `acts.toml`. Ninguém fica sabendo de
   uma portaria nova, como as que saem a cada piracema.
2. **Geografia.** As áreas legais vêm de uma camada estadual (as CHs do IDE-Sisema). Cada estado publica a
   sua em formato, escala e serviço diferentes. As áreas federais (`br:*`) só existem onde houver área
   estadual ligada a elas, e um ponto fora de MG sai `undetermined` mesmo quando há norma federal aplicável.
   Isso inclui espécie ameaçada, que é proibida no país todo.
3. **Armazenamento.** Os originais (PDF/HTML) ficam no git. Com milhares de atos, o repositório cresce sem
   limite, e arquivos binários não se beneficiam do histórico do git.

## Decisão

### 1. Geografia nacional agora

A geografia de todo o país é importada já, independentemente de quantos estados tenham catálogo.

| Camada | Fonte | Uso |
| --- | --- | --- |
| Unidades da Federação | IBGE, malha territorial | `location.states` e a faixa de divisa (ADR 0001) |
| Ottobacias por nível | ANA, Base Hidrográfica Atlas-Estudos (BHAE) v1.0, arquivos `geoft_bhae_ach_otto_nivel_01` a `_07` | Áreas legais federais e, quando a norma usa bacia, estaduais |
| Trechos de drenagem | ANA, BHAE v1.0, `geoft_bhae_trecho_drenagem` (atributo `dedominial`: Federal, Estadual ou Linha de Costa) | `location.water_domain` |

A BHAE ([metadados SNIRH](https://metadados.snirh.gov.br/geonetwork/srv/api/records/8ad07d33-1677-481d-bc61-ed5ca204926f))
deriva da BHO 6, tem acesso livre e usa SIRGAS 2000 (EPSG:4674). Ela inclui os cursos d'água com área de
drenagem de 10 km² ou mais e todos os de domínio da União. A ANA marca a BHO 2017 como desatualizada e indica
a BHAE no lugar.
| Camadas estaduais | Órgão de cada estado | Só quando a norma do estado se referir a elas (ex.: CHs de MG) |

- **Área legal = seleção de ottobacias.** No `basins.toml`, a área declara um ou mais prefixos de código
  Pfafstetter (`otto_prefix`). Por exemplo, a bacia do São Francisco é o conjunto de ottobacias cujo código
  começa pelo prefixo dela. Um arquivo de mapeamento, como o `area_mapping.toml`, liga cada prefixo à área
  legal e confere o nome do curso d'água. Prefixo ambíguo ou sem regra de ligação faz a ingestão falhar.
- **Usar os níveis já agregados pela ANA.** A camada completa de áreas de contribuição tem 5,8 GB, e o banco
  de produção não precisa dela. A BHAE já publica as ottobacias agregadas por nível de Pfafstetter: o nível 1
  tem 51 MB, o 3 tem 205 MB e o 4 tem 467 MB. Cada área legal usa o nível mais baixo que a delimita, e a
  ingestão carrega até o nível 4. Um nível mais fino só entra quando uma norma exigir. Os trechos de drenagem
  (986 MB) entram só com o código, a geometria e a dominialidade.
- **As áreas federais deixam de ser derivadas.** `br:sao_francisco` passa a ter geometria própria, vinda da
  BHAE. As áreas estaduais continuam ligadas a elas por `within`.
- **MG mantém as CHs.** As normas de MG falam das bacias que as CHs representam. A BHAE não substitui essa
  camada, mas serve para conferi-la: uma CH que não caia na bacia federal à qual está ligada é erro de ingestão.

**Efeito na API.** Qualquer ponto do país passa a receber as regras federais da sua bacia e a proteção das
espécies ameaçadas. Num estado sem catálogo publicado, o estado entra em `gaps` ("normas estaduais de XX ainda
não catalogadas"). Por isso a resposta só pode ser `prohibited` ou `undetermined`, nunca `conditional`: sem as
normas do estado, uma condição federal pode ser incompleta.

### 2. Normas: cinco etapas por jurisdição

| Etapa | Entrada → saída | Implementação |
| --- | --- | --- |
| **Descoberta** | Fontes registradas → candidatos | `catalog/sources/<jurisdição>.toml` lista as fontes de cada jurisdição: DOU (INLABS, XML), diário oficial do estado e páginas do órgão ambiental. Busca por termos (`pesca`, `defeso`, `piracema`, `ictiofauna`, `pescado`) e por emissor. Cada candidato vira uma issue com link, data e trecho. Nunca vira regra automaticamente. |
| **Aquisição** | Candidato aceito → original guardado | `sources.py`, generalizado: baixa, calcula o SHA-256 e grava no arquivo de originais (seção 3), com o hash como nome. O manifesto no git registra URL, hash, tamanho e data. Em desenvolvimento, o armazenamento é um diretório local. |
| **Extração** | Original → texto segmentado | Extração e segmentação atuais. Se o PDF não tiver camada de texto, faz OCR em português (tesseract) e marca `text_status = "ocr"`. Trecho conferido em texto de OCR exige aprovação com ressalva. |
| **Estruturação e aprovação** | Texto → regras aprovadas | Claude extrai as regras para o `rules.toml`. `catalog.py` valida os trechos, o agente `consultor-juridico` emite o parecer e decide o `review`, e o CI fecha. Um PR por ato. |
| **Carga** | `catalog.json` → banco | `legal:import --publish` no deploy, uma versão imutável por jurisdição (ADR 0001). A geografia é carregada à parte, uma vez por versão de dataset (`geo:import-*`). |

- **Orquestração:** GitHub Actions agendado. A descoberta roda toda semana e, de outubro a março, todo dia.
  As outras etapas rodam no PR do ato. Cada etapa é idempotente: mesmo hash, nada muda. O volume esperado
  (centenas de atos no país) não justifica um orquestrador dedicado.
- **Ato novo pendente:** enquanto houver candidato aceito e ainda não aprovado para uma área, as regras atuais
  continuam publicadas e a resposta ganha uma lacuna ("há norma nova em análise para esta área"). O catálogo
  não revoga nem altera regra sozinho.
- **Originais já no git:** os de MG migram para o arquivo de originais na fase 3. Até lá, ficam onde estão.

### 3. Arquivo de originais: Google Drive do projeto

Os originais (PDFs, HTMLs, GeoPackages) ficam numa pasta do Google Drive do dono do projeto. Eles servem de
prova do que foi lido e permitem refazer a extração sem depender da fonte oficial continuar no ar.

- **Endereçamento por conteúdo.** O arquivo tem o SHA-256 como nome (`sha256/<2 primeiros>/<hash>.<ext>`).
  O manifesto no git é a fonte da verdade: URL de origem, hash, tamanho e data. Um arquivo no Drive sem entrada
  no manifesto não vale nada. Uma entrada sem arquivo é erro de ingestão.
- **Acesso por `rclone`**, com OAuth da conta do dono e escopo `drive.file`: a ferramenta só enxerga os arquivos
  que ela mesma criou, nunca o resto do Drive. O token fica num segredo do GitHub Actions
  (`RCLONE_CONFIG_ARCHIVE_TOKEN`), nunca no repositório. Contas de serviço do Google não servem aqui, porque não
  têm cota própria fora de um Drive compartilhado do Workspace.
- **Só na ingestão.** A API em produção nunca lê o Drive: ela lê o PostGIS, carregado pelas etapas de carga.
  Uma falha do Drive atrasa uma ingestão nova, mas não derruba o serviço.
- **Na leitura, o hash é conferido.** Arquivo baixado com hash diferente do manifesto faz a etapa falhar.
- **Troca de backend sem retrabalho.** O código fala com um "remote" do `rclone`. Em desenvolvimento é um
  diretório local. Se o volume ou o acesso pedirem, trocar por S3 ou R2 é mudar a configuração e copiar os
  arquivos, sem mexer nos manifestos.
- **Cota.** A primeira carga cabe nos 15 GB gratuitos: BHAE níveis 1 a 4 e trechos somam cerca de 1,8 GB, e os
  textos legais são pequenos. Os níveis 5 a 7 (7,6 GB) só entram se uma norma exigir.

### 4. Fases

| Fase | Entrega | Resultado na API |
| --- | --- | --- |
| 1. Geografia nacional | IBGE UF + BHAE (ottobacias até o nível 4 e trechos com dominialidade); `otto_prefix` em `basins.toml`; conferência das CHs de MG | Todo ponto do país localizado em estado, bacia federal e domínio do rio |
| 2. Catálogo federal completo | Defesos e ordenamentos federais por bacia (IBAMA, MMA, MPA e portarias interministeriais) e lista de ameaçadas | Regras federais no país todo |
| 3. Descoberta e armazenamento | Registro de fontes, descoberta agendada, arquivo de originais no Google Drive, OCR | Normas novas detectadas, com lacuna de "em análise" |
| 4. Estados | Na ordem: SP, GO, MS, BA, ES, RJ, PR (dividem bacias com MG); MT, AM, PA (Pantanal e Amazônia); os demais | Uma jurisdição por vez sai de "não catalogada" |

A infraestrutura de produção (servidor de aplicação, segredos, backup, monitoramento) segue em paralelo e não
depende destas fases.

## Consequências

- Pontos fora de MG deixam de ser sempre `undetermined`. Espécie ameaçada sai `prohibited` em todo o país desde a fase 1.
- O contrato da API não muda: `location.states`, `location.water_domain`, `catalogs[]` e `gaps` já existem (ADR 0001).
- A BHAE vem do Mapeamento Sistemático Brasileiro, com escala variável conforme a região. Pontos perto de
  um divisor de águas continuam sujeitos ao erro de borda já descrito em `docs/api.md`.
- A BHAE está na versão 1.0, de 28/03/2023, com metadado revisado em 20/03/2025. Uma nova versão da ANA entra
  como nova versão de dataset (`docs/geography.md`).
- Uma ottobacia é a área que drena para um trecho de rio. Um ponto no leito de um rio de divisa pode cair na
  ottobacia de um afluente vizinho. Por isso `water_domain` vem do trecho mais próximo, dentro de uma
  tolerância, e não da ottobacia.
- A descoberta depende de fontes que mudam de layout. A falha de uma fonte abre uma issue em vez de passar em silêncio.

## A confirmar na implementação

- Os prefixos de Pfafstetter e o nível de cada bacia federal, conferidos contra os nomes dos cursos d'água.
- A tolerância de distância entre o ponto e o trecho usada para `water_domain`.
- A licença: o metadado diz "O acesso ao dado é livre", sem nomear uma licença (como nas CHs de MG).
- O acesso ao INLABS (cadastro gratuito, segundo a Imprensa Nacional) e o formato dos arquivos XML.
