# Deploy — Loja Severinus (sys.severinus.com.br)

OSPOS oficial rodando em `/var/www/ospos`, servido por nginx (`127.0.0.1:8084`),
exposto pela internet via Cloudflare Tunnel (`sys.severinus.com.br`).

## Fluxo de trabalho

```
feature/*  →  PR no GitHub  →  você revisa  →  merge em master
                                                    ↓
                                      auto-deploy (timer, 5 min)
                                                    ↓
                                        /var/www/ospos + migrations
```

- **`master`** deste fork = espelho exato do upstream `opensourcepos/opensourcepos`.
- **Nunca** commitar direto na `master`: criar branch, abrir PR.
- Produção **não** tem `.git` (o rsync exclui) — o estado fica em `/var/lib/ospos-deploy/last_commit`.

## Arquivos

| Arquivo | Papel |
|---|---|
| `auto-deploy.sh` | Sincroniza fork master → `/var/www/ospos`. Roda pelo timer. |
| `install-autodeploy.sh` | Instala o `ospos-deploy.timer` (executar como root, uma vez). |
| `nginx-ospos.conf` | Vhost nginx da `127.0.0.1:8084`. |

## Invariantes (não quebrar)

Estes artefatos são **gerados no servidor** e o deploy **nunca** os remove
(o `rsync` roda sem `--delete`, de propósito):

- `vendor/` → `composer install` (recriado automaticamente se faltar)
- `public/resources/` → `npx gulp default` (recriado automaticamente se faltar; é gitignored)
- `writable/` → logs, cache, sessões
- `.env` → configuração de produção, jamais sobrescrita

O script termina com **healthcheck** em `/login`: se não retornar `200`, ele
marca falha no log e **não** avança o estado (o backup fica em `/var/www/ospos.bak-<timestamp>`).

> Histórico: a primeira versão usava `rsync --delete` e apagou `vendor/` e
> `public/resources/` (não versionados no fork), derrubando o site com 500.
> Daí a remoção do `--delete` + recriação automática + healthcheck.

## Operação manual

```bash
# deploy normal (detecta commit novo)
sudo /home/abracadabra/pdv-migracao/auto-deploy.sh

# forçar deploy mesmo sem commit novo
sudo /home/abracadabra/pdv-migracao/auto-deploy.sh --force

# ver log
tail -30 /var/log/ospos-deploy.log

# rollback (escolher um backup)
cp -a /var/www/ospos.bak-YYYYmmdd-HHMMSS /var/www/ospos.rollback
```
