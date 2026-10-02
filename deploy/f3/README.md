# F3 — /api/itens no OSPOS DEV (:8085)

## O que foi feito

Endpoint JSON `/api/itens` dentro do proprio OSPOS (CodeIgniter 4), imitando o
fluxo interno do app (nao e API paralela, reusa os MESMOS models do OSPOS):

| Metodo | Rota              | Faz                                            | Model usado |
|--------|-------------------|------------------------------------------------|-------------|
| GET    | /api/itens/{id}   | le 1 item                                      | Item::get_info / exists |
| POST   | /api/itens        | cria item (201)                                | Item::save_value(NEW_ENTRY) |
| PUT    | /api/itens/{id}   | altera so os campos enviados (200)             | Item::save_value(id) |
| PATCH  | /api/itens/{id}   | idem PUT                                       | Item::save_value(id) |
| DELETE | /api/itens/{id}   | soft delete: deleted=1 + zera quantidades (200)| Item::delete() |

Regras copiadas do OSPOS (`app/Controllers/Items.php::postSave`,
`app/Models/Item.php`): name obrigatorio, item_number nao pode repetir entre
itens vivos (409), ITEM_TEMP zera estoque/reorder, ITEM = 0, HAS_STOCK = 0,
pack_name default 'Each', qty_per_pack default 1.

## Arquivos

- `app/Controllers/Api/Itens_controller.php`   (novo — o endpoint)
- `app/Config/Routes.php`                       (rotas api/itens)
- `app/Config/Filters.php`                      (csrf + isLoggedIn exceto 'api/*')
- `deploy/f3/testa-api-itens.sh`                (teste real, 9 cenarios)
- `deploy/f3/PROVA-f3-api-itens.txt`            (saida real do teste)

## Deploy no dev (IMPORTANTE)

O container `dev_ospos_app` usa a imagem `jekkos/opensourcepos:master` SEM bind
mount do codigo-fonte. Para o codigo novo valer no dev sem rebuild:

    docker cp app/Controllers/Api/Itens_controller.php dev_ospos_app:/app/app/Controllers/Api/
    docker cp app/Config/Routes.php                     dev_ospos_app:/app/app/Config/Routes.php
    docker cp app/Config/Filters.php                    dev_ospos_app:/app/app/Config/Filters.php

(Ou reconstruir a imagem a partir de ~/ospos-fork. O dev nao monta o fonte —
por isso o docker cp. Persiste na camada do container; rebuild do container
exige reaplicar.)

## Bug pre-existente achado e corrigido no dev

O app dev estava 100% quebrado (toda pagina com Fatal error
`Class "CodeIgniter\Exceptions\InvalidArgumentException" not found` em
`DotEnv.php:64`). Causa real: `/app/.env` estava `root:root 640`, ilegivel para
o Apache (www-data) -> DotEnv lancava excecao ao ler, e a excecao tambem nao
resolvia. Fix: `docker exec dev_ospos_app chmod 644 /app/.env`.
NAO era bug do codigo novo. Antes: /login fatal. Depois: /login 200.

## Como testar

    bash deploy/f3/testa-api-itens.sh

Contra `http://127.0.0.1:8085`. Usa codigo unico por execucao (timestamp).

## Verificação — 2026-09-29 (card [1] /api/itens ESCRITA, card f3 6abac1f07e0e36d173daf5fd)

Três provas rodadas contra o que está no ar, todas PASSOU:

    bash deploy/f3/testa-api-itens.sh            # OSPOS :8085   -> 9/9 cenarios PASSARAM
    cd ~/pdv-api && node test/teste_itens_escrita.js      # lib dev  -> PASSOU
    cd ~/pdv-api && node test/teste_api_itens_escrita.js  # HTTP :8877 -> PASSOU

- OSPOS :8085 -> "RESULTADO: TODOS OS 9 CENARIOS PASSARAM" (201 criar, 200 GET/PATCH/PUT,
  200 DELETE soft, 404 apos excluir, 404 inexistente, 409 duplicado, 400 sem name).
- pdv-api :8877 (PDV_ENV=homolog) -> CRUD completo via rede, validacoes 409/400/404,
  soft delete + reativar + purge, limpa o item de teste no fim.
- lib dev (dev_severinus) -> cria/le/edita/exclui/reativa, atributos e taxa fiscal,
  historico de estoque (ospos_inventory), purge remove links.

## Observacoes / limites (deliberados)

- `number_locale` do dev = `en_US`: precos devem ir como `"10.50"`, nao
  `"10,50"` (esse vira 0 por design do parse_decimals). Com `pt_BR` o inverso.
- Sem auth/token no dev: a rota e aberta so no ambiente dev de teste. Se for
  expor alem do localhost, adicionar header de token (padrao ja usado no
  pdv-api: PDV_ESCRITA_CONFIRMACAO). Nao foi adicionado aqui para nao inventar
  requisito.
- `?purge` (hard delete) nao implementado — o OSPOS so faz soft delete; o
  pdv-api tem isso como opcao explicita. Fora do escopo do card.
- Quantidades/atributos/taxas nao expostos nesse endpoint (o pdv-api cobre);
  aqui e o CRUD base de itens pedido no card.
