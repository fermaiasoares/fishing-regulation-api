# Catálogo jurídico — Minas Gerais

Levantamento de 14/11/2026. Todas as regras aguardam revisão do Fernando (`review = "pending"`). Nenhuma regra concede permissão: sem restrição carregada, a resposta é `undetermined`.

## Arquivos

- `catalog/legal/mg/acts.toml`: atos normativos, fonte oficial, onde o texto foi lido e evidência de vigência.
- `catalog/legal/mg/rules.toml`: regras extraídas, com artigos, bacias, período, escopo de espécie e efeito.
- `catalog/legal/mg/basins.toml`: áreas de aplicação e hierarquia (Doce ⊂ Leste; Paraopeba ⊂ São Francisco).
- `catalog/legal/mg/species.toml`: listas de espécies citadas pelos atos.
- `services/legal-catalog/catalog.py`: valida o catálogo e gera a matriz abaixo.

```bash
cd services/legal-catalog
python3 catalog.py ../../catalog/legal/mg            # valida
python3 catalog.py ../../catalog/legal/mg --matrix   # matriz por bacia
python3 -m unittest discover -s tests -v
```

## Atos levantados

| Ato | Área | Texto | Uso no catálogo |
| --- | --- | --- | --- |
| [Portaria IEF 154/2011](http://www.ief.mg.gov.br/images/stories/pesca/portaria_n_154_2.pdf) | São Francisco | Integral (espelho da Agência Peixe Vivo) | Defeso, cotas, locais e rios fechados |
| [Portaria IEF 155/2011](http://www.ief.mg.gov.br/images/stories/pesca/portaria_n_155_2.pdf) | Leste (inclui Doce, Jequitinhonha, Mucuri, Pardo, Paraíba do Sul) | Integral (espelho) | Defeso, cotas, locais |
| [Portaria IEF 156/2011](http://www.ief.mg.gov.br/images/stories/pesca/portaria_n_156_2.pdf) | Grande e Paranaíba | Integral (espelho) | Defeso, cotas, locais e 12 trechos fechados |
| [Portaria IEF 31/2025](https://www.ief.mg.gov.br/web/ief/pesca-no-rio-doce) | Doce | Resumo oficial do IEF | Nativas proibidas o ano todo; revoga a 40/2017 |
| Portaria IEF 16/2019 | Paraopeba | Resumo oficial do IEF | Nativas proibidas o ano todo; profissional proibida |
| [Portaria IBAMA 50/2007](https://www.ibama.gov.br/phocadownload/noticias/noticias2016/portaria_ibama_50_2007.pdf) | São Francisco (federal) | Integral | Lagoas marginais fechadas até 30/04 |
| IN IBAMA 196/2008 | Leste (federal) | Integral (agregador) | Defeso federal; cota menos restritiva que a estadual |
| [Lei 15.082/2004](https://www.almg.gov.br/legislacao-mineira/texto/LEI/15082/2004/) | Rios de preservação permanente | Integral (espelho CBH Doce) | Sem proibição expressa de pesca; não gera regra |
| [Página IEF de restrições](https://www.ief.mg.gov.br/web/ief/restri%C3%A7%C3%B5es-ambientais-%C3%A0-pesca-em-minas-gerais) | MG | Orientação oficial | Pesca proibida em UCs estaduais |
| Portaria IEF 40/2017 | Doce | Integral | Revogada; só histórico |
| IN IBAMA 25/2009 | Paraná (federal) | Não lido | Sem regra até leitura |
| Portaria MMA 300/2022 (+ GM/MMA 1.667/2026) | Brasil | Não lido | Lista de ameaçadas a extrair |

A vigência das Portarias 154, 155 e 156 no ciclo atual está confirmada pela [Agência Minas em 05/11/2026](https://www.agenciaminas.mg.gov.br/noticia/piracema-comeca-em-minas-gerais-e-restringe-pesca-para-proteger-reproducao-dos-peixes-nativos): piracema até 28/02/2027.

## Situação por bacia

Gerada por `catalog.py --matrix`. "Não nativas" pressupõe espécie presente na lista oficial da bacia.

| Bacia | Período | Nativas | Não nativas | Restrições por local (pendentes de mapa) |
| --- | --- | --- | --- | --- |
| São Francisco | defeso | `prohibited` | `conditional` (3 kg + 1) | faixas de barragens e confluências, lagoas marginais, 5 rios fechados, UCs |
| São Francisco | fora do defeso | `undetermined` | `undetermined` | UCs |
| Paraopeba | defeso | `prohibited` | `conditional` (só amador) | as do São Francisco, UCs |
| Paraopeba | fora do defeso | `prohibited` | `conditional` (só amador) | UCs |
| Grande / Paranaíba | defeso | `prohibited` | `conditional` (3 kg + 1, até 5 varas) | faixas, 12 trechos fechados, UCs |
| Grande / Paranaíba | fora do defeso | `undetermined` | `undetermined` | UCs |
| Leste (exceto Doce) | defeso | `prohibited` | `conditional` (3 kg + 1) | faixas, UCs |
| Leste (exceto Doce) | fora do defeso | `undetermined` | `undetermined` | UCs |
| Doce | defeso | `prohibited` | `conditional` (155/2011 + 31/2025) | faixas, UCs |
| Doce | fora do defeso | `prohibited` | `conditional` (31/2025) | UCs |

## Lacunas antes da v1.0

1. **Fora do defeso** quase tudo é `undetermined`: faltam tamanhos mínimos, cotas gerais e regras da Lei 14.181/2002 e do Decreto 43.713/2004.
2. **Espécies ameaçadas**: extrair o Anexo III da Portaria MMA 300/2022 com a atualização de 2026; a proibição vale o ano todo em qualquer bacia.
3. **Textos não obtidos**: PDF oficial da Portaria IEF 31/2025 (bloqueia leitura automática), Portaria IEF 16/2019, IN IBAMA 25/2009 e planilha de defesos vigentes do MPA.
4. **Mapeamento geográfico**: faixas de 300 a 1.500 m, lagoas marginais, trechos nomeados, cursos com até 20 m de largura e UCs dependem de camadas que ainda não estão no PostGIS.
5. **Conflito no texto**: a Portaria 154/2011 lista autóctones como capturáveis no art. 5º, IV, mas o art. 4º só admite não nativas. Tratado como `undetermined`.
6. **29 de fevereiro**: em ano bissexto o período é estendido até 29/02 por precaução. Decisão a aprovar.
7. **Concorrência federal × estadual**: a regra mais restritiva prevalece (ex.: cota de 3 kg estadual sobre 5 kg federal no São Francisco).
