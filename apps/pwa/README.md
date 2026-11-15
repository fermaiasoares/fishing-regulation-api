# App de exemplo (PWA)

App de exemplo para validar a API com um consumidor real. Implementa as telas **Mapa** e **Legalidade** do
canvas "Telas do app de pesca". As outras telas do canvas (Condições, Diário, Cartão, Equipamento, Comunidade)
dependem de serviços que a API não oferece.

O app chama só `POST /api/fishing/evaluate` ([docs/api.md](../../docs/api.md)).

## Rodar

Com a API no ar em `http://localhost:8000` (veja o README da raiz):

```sh
cd apps/pwa
npm ci
npm run dev        # http://localhost:5173, recarrega a cada mudança
# ou, para testar como PWA (service worker e modo offline):
npm run build && npm run preview   # http://localhost:4173
```

O Vite repassa `/api` para a API, então não é preciso configurar CORS. Para apontar para outra API, use
`API_PROXY_TARGET=http://host:porta npm run dev`. Num build servido fora do Vite, defina
`VITE_API_URL=https://api.exemplo` na hora do build. A API então precisa liberar CORS para a origem do app.

No celular, geolocalização e service worker exigem HTTPS. Em `localhost` funcionam sem ele.

## O que validar

| Cenário | Como | Esperado hoje |
| --- | --- | --- |
| Não nativa na piracema | Três Marias, tilápia, 15/12/2026 | Permitida só com condições, 5 condições |
| Espécie ameaçada | Três Marias, tambaqui, qualquer data | Proibida (Portaria GM/MMA 1.666/2026) |
| Nativa sem lista oficial | Belo Horizonte, dourado | Sem regra confirmada, com as lacunas |
| Bacia sem regras | Extrema, qualquer espécie | Sem regra confirmada (CH PJ1) |
| Bacia do Doce fora da piracema | Gov. Valadares, tilápia, 15/06/2027 | Permitida só com condições |
| Sem conexão | Faça uma consulta, desligue a rede e repita | Mostra a consulta salva, marcada com a data |

## Decisões de apresentação

- **Nunca "permitido".** A API não responde `allowed`. O app mostra três estados: proibida (vermelho),
  permitida só com condições (azul-petróleo) e sem regra confirmada (cinza).
- **A referência formal é a fonte.** Cada norma mostra a `citation` (ato, data, artigos e órgão) e o trecho
  literal. O link da norma aparece só como informativo.
- **Restrições por local** (faixas de barragens, lagoas, trechos, UCs) aparecem em "Atenção nesta região",
  porque a API ainda não as verifica no mapa.
- **Offline:** as últimas 30 respostas ficam no aparelho (`localStorage`) e aparecem marcadas quando não há rede.
  O service worker só guarda os arquivos do app, nunca respostas da API.
- **Espécies:** a lista de sugestões é fixa no app (`src/main.ts`), porque a API ainda não tem rota de espécies.
- **Mapa:** tiles do OpenStreetMap, com atribuição. Servem para desenvolvimento, não para produção em volume.
