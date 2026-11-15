# Catálogo jurídico — federal e Minas Gerais

Atualizado em 14/11/2026. Todas as regras aguardam revisão do Fernando (`review = "pending"`). Nenhuma regra concede permissão: sem restrição carregada, a resposta é `undetermined`.

## Jurisdições

Cada diretório de `catalog/legal` é uma jurisdição com catálogo próprio ([ADR 0001](adr/0001-jurisdicoes.md)):

- `br`: normas federais (IBAMA, MMA). Áreas `br`, `br:sao_francisco`, `br:leste`, por ora derivadas das áreas estaduais.
- `mg`: normas de Minas Gerais. Cada área pode declarar `within = ["br:<id>"]`; assim `mg:sao_francisco` recebe as regras federais da bacia do São Francisco.

Ids de área são locais nos TOML e viram `<jurisdição>:<id>` no `catalog.json`. Ids de regra são únicos em todo o repositório.

## Pipeline

1. **Coleta** (`services/legal-catalog/sources.py`, workflow `legal-sources`): baixa a primeira URL que responder de cada ato, grava o arquivo original em `catalog/legal/<jurisdição>/sources/` e registra URL, SHA-256, tamanho e data no `manifest.json`. Roda no runner do PR, que tem internet, e faz commit no próprio branch.
2. **Extração e segmentação**: `pdftotext` ou HTML → texto sem cabeçalhos de página → segmentos com ids estáveis (`art2/III/g`, `art4/par-unico`). `sources.py --reextract` refaz essa etapa a partir dos originais, conferindo o SHA-256.
3. **Estruturação**: regras em `rules.toml`, cada uma com `evidence` (segmento + trecho literal). Extração feita por Claude e revisada em PR.
4. **Validação** (`catalog.py`): rejeita regra com ato revogado ou não lido, trecho que não existe no segmento citado, ou regra sem evidência quando o texto do ato está armazenado.
5. **Exportação**: `catalog.py --export` gera `catalog.json` canônico. O CI falha se o arquivo estiver desatualizado.
6. **Carga** (`php artisan legal:import [--publish]`): importa todos os `catalog/legal/*/catalog.json`, cada um como versão imutável da sua jurisdição, identificada pelo SHA-256. Reimportar o mesmo arquivo não cria nada. A API responde pela versão publicada mais recente de cada jurisdição.

```bash
cd services/legal-catalog
for j in br mg; do                                                          # valida e exporta
  python3 catalog.py ../../catalog/legal/$j --export ../../catalog/legal/$j/catalog.json
done
python3 catalog.py ../../catalog/legal/mg --matrix                          # matriz por bacia (inclui federais)
python3 sources.py ../../catalog/legal/mg --reextract                       # refaz textos sem rede
python3 -m unittest discover -s tests -v
```

## Atos levantados

| Ato | Área | Texto armazenado | Regras |
| --- | --- | --- | --- |
| [Portaria IEF 154/2011](http://www.ief.mg.gov.br/images/stories/pesca/portaria_n_154_2.pdf) | São Francisco | Sim (espelho Agência Peixe Vivo; URL oficial não respondeu) | 4, todas com trecho conferido |
| [Portaria IEF 155/2011](http://www.ief.mg.gov.br/images/stories/pesca/portaria_n_155_2.pdf) | Leste (Doce, Jequitinhonha, Mucuri, Pardo, Paraíba do Sul…) | Sim (espelho) | 3, conferidas |
| [Portaria IEF 156/2011](http://www.ief.mg.gov.br/images/stories/pesca/portaria_n_156_2.pdf) | Grande e Paranaíba | Sim (espelho) | 4, conferidas |
| [Portaria IEF 31/2025](https://www.ief.mg.gov.br/documents/d/ief/utf-8portaria-ief-31-de-2025-pdf) | Doce | Sim (PDF oficial) | 2, conferidas |
| [Portaria IEF 40/2017](https://meioambiente.mg.gov.br/documents/d/ief/portaria_n-_40_-_2017-pdf) | Doce | Sim (oficial) | Revogada pelo art. 9º da 31/2025 |
| [Lei 15.082/2004](https://www.almg.gov.br/legislacao-mineira/texto/LEI/15082/2004/) | Rios de preservação permanente | Sim (espelho CBH Doce) | Nenhuma: não proíbe pesca expressamente |
| Portaria IEF 16/2019 | Paraopeba | Não localizado | 2, sem trecho conferido |
| [Portaria IBAMA 50/2007](https://www.ibama.gov.br/phocadownload/noticias/noticias2016/portaria_ibama_50_2007.pdf) | São Francisco (federal) | Não (HTTP 403 para download automático) | 1, sem trecho conferido |
| IN IBAMA 196/2008 | Leste em SE, BA, MG e ES, exceto São Francisco (federal) | Sim (agregador NormasBrasil) | 1, conferida (arts. 1º, 2º e 7º); art. 2º (lagoas marginais e 1.000 m de barragens) ainda sem regra |
| IN IBAMA 25/2009 | Paraná (federal; inclui rios de MG) | Sim (agregador), ainda não lido | Nenhuma até leitura |
| IN IBAMA 195/2008 | Sudeste (federal) | Não | Nenhuma até leitura |
| Portaria GM/MMA 1.666/2026 | Brasil | Sim (LegisWeb; DOU não respondeu) | 1: proteção integral de espécies ameaçadas (art. 3º), conferida |
| [Portaria GM/MMA 1.667/2026](https://www.gov.br/icmbio/pt-br/assuntos/biodiversidade/pan/saiba-mais/documentos-e-downloads/9-portaria-gm_mma-no-1-667-de-27-de-abril-de-2026.pdf) | Brasil | Sim (PDF oficial) + Anexo I em CSV (`annex_1667.py`) | Lista com 351 peixes continentais e invertebrados de água doce |
| Portaria MMA 300/2022 | Brasil | Não | Substituída pelas 1.666 e 1.667/2026 para fauna aquática |
| [Página IEF de restrições](https://www.ief.mg.gov.br/web/ief/restri%C3%A7%C3%B5es-ambientais-%C3%A0-pesca-em-minas-gerais) | MG | Orientação oficial | 1 (UCs estaduais) |

15 das 19 regras (3 federais, 16 de MG) têm trecho literal conferido no texto armazenado. As demais aparecem como lacuna em toda avaliação. A vigência das Portarias 154, 155 e 156 no ciclo atual está confirmada pela [Agência Minas em 05/11/2026](https://www.agenciaminas.mg.gov.br/noticia/piracema-comeca-em-minas-gerais-e-restringe-pesca-para-proteger-reproducao-dos-peixes-nativos): piracema até 28/02/2027.

## Situação por bacia

Gerada por `catalog.py --matrix`. "Não nativas" pressupõe espécie presente na lista oficial da bacia.

| Bacia | Período | Nativas | Não nativas | Restrições por local (pendentes de mapa) |
| --- | --- | --- | --- | --- |
| São Francisco | defeso | `prohibited` | `conditional` (3 kg + 1; profissional só consumo familiar) | faixas de barragens e confluências, lagoas marginais (até 30/04), 5 rios fechados, UCs |
| São Francisco | fora do defeso | `undetermined` | `undetermined` | UCs |
| Paraopeba | defeso | `prohibited` | `conditional` (só amador) | as do São Francisco, UCs |
| Paraopeba | fora do defeso | `prohibited` | `conditional` (só amador) | UCs |
| Grande / Paranaíba | defeso | `prohibited` | `conditional` (3 kg + 1, até 5 varas, sem piauçu) | faixas, 12 trechos fechados, UCs |
| Grande / Paranaíba | fora do defeso | `undetermined` | `undetermined` | UCs |
| Leste (exceto Doce) | defeso | `prohibited` | `conditional` (3 kg + 1) | faixas, UCs |
| Leste (exceto Doce) | fora do defeso | `undetermined` | `undetermined` | UCs |
| Doce | defeso | `prohibited` | `conditional` (155/2011 + 31/2025) | faixas, UCs |
| Doce | fora do defeso | `prohibited` | `conditional` (amador 10 kg + 1; profissional sem cota) | UCs |

## Lacunas antes da v1.0

1. **Fora do defeso** quase tudo é `undetermined`: faltam tamanhos mínimos, cotas gerais e regras da Lei 14.181/2002 e do Decreto 43.713/2004.
2. **Espécies ameaçadas**: carregadas (Portarias GM/MMA 1.666 e 1.667/2026). Pendências: Planos de Recuperação que autorizem uso sustentável (art. 4º) não estão catalogados; o anexo marca linhas com "*" sem legenda, então a data de 27/10/2026 do art. 12 vale para todas; a portaria não excetua populações introduzidas, e o catálogo aplica a proibição também fora da área nativa (ex.: tambaqui em MG), interpretação a revisar.
8. **Nativas por bacia**: não foi encontrada lista oficial de espécies nativas para São Francisco, Grande, Paranaíba e Leste. Nativas não ameaçadas dessas bacias seguem `undetermined` na piracema. Candidatas: tabelas de tamanho mínimo da Portaria IBAMA 18/2008 (São Francisco) e da IN IBAMA 26/2009 (Paraná), que listam espécies sem declará-las nativas.
3. **Textos federais e do Paraopeba**: sem texto armazenado, suas regras ficam sem trecho conferido. Se os PDFs forem anexados ao repositório, o fluxo `--reextract` os processa sem rede.
4. **Mapeamento geográfico**: faixas de 300 a 1.500 m, lagoas marginais, trechos nomeados, cursos com até 20 m de largura e UCs dependem de camadas que ainda não estão no PostGIS.
5. **Conflitos no texto da Portaria 154/2011**: o art. 5º, IV lista autóctones como capturáveis, mas o art. 4º só admite não nativas; o art. 2º, § 1º proíbe anzóis múltiplos, mas o art. 4º, IV permite garatéia com isca artificial. O primeiro conflito vira `undetermined`; o segundo fica nas condições, com a garatéia, até decisão.
6. **29 de fevereiro**: em ano bissexto o período é estendido até 29/02 por precaução. Decisão a aprovar.
7. **Concorrência federal × estadual**: a regra mais restritiva prevalece (ex.: cota de 3 kg estadual sobre 5 kg federal no São Francisco).
