#!/bin/bash
# Deploy OSPOS -> /var/www/ospos a partir do fork (branch master).
#
# Uso:
#   /home/abracadabra/pdv-migracao/auto-deploy.sh           # deploy se houver commit novo
#   /home/abracadabra/pdv-migracao/auto-deploy.sh --force   # deploy sempre
#
# Invariantes (artefatos GERADOS NO SERVIDOR, nunca removidos por deploy):
#   vendor/              -> composer install (recriado se faltar)
#   public/resources/    -> npx gulp default (recriado se faltar)
#   writable/            -> dados de runtime (logs, cache, sessoes)
#   .env                 -> config de producao, nunca sobrescrito
set -euo pipefail

REPO=/home/abracadabra/ospos-fork
DESTINO=/var/www/ospos
LOG=/var/log/ospos-deploy.log
STATE=/var/lib/ospos-deploy
FORCE="${1:-}"

mkdir -p "$STATE"
cd "$REPO"

sudo -u abracadabra git fetch origin master --quiet 2>/dev/null || exit 0

REMOTE=$(sudo -u abracadabra git rev-parse origin/master)
DEPLOYED=$(cat "$STATE/last_commit" 2>/dev/null || echo "nenhum")

if [ "$REMOTE" = "$DEPLOYED" ] && [ "$FORCE" != "--force" ]; then
  exit 0
fi

{
  echo "=== $(date '+%F %T') ==="
  echo "deploy: $DEPLOYED -> $REMOTE"

  STAMP=$(date +%Y%m%d-%H%M%S)
  [ -d "$DESTINO" ] && cp -a "$DESTINO" "/var/www/ospos.bak-$STAMP" && echo "backup: /var/www/ospos.bak-$STAMP"

  sudo -u abracadabra git checkout master --quiet
  sudo -u abracadabra git reset --hard origin/master --quiet
  sudo -u abracadabra git log --oneline -1

  # SEM --delete: nunca remove vendor/, writable/ nem public/resources/ (gerados aqui)
  rsync -a \
    --exclude='node_modules' --exclude='.env' \
    --exclude='writable/*' --exclude='.git' --exclude='public/uploads/*' \
    "$REPO"/ "$DESTINO"/
  rm -rf "$DESTINO/.git"

  # 1. Dependencias PHP
  if [ ! -f "$DESTINO/vendor/autoload.php" ]; then
    echo "vendor ausente -> composer install"
    (cd "$DESTINO" && sudo -u www-data composer install --no-interaction --no-progress --no-dev 2>&1 | tail -3)
  fi

  # 2. Assets front (public/resources e gitignored -> build local + copia)
  if [ ! -d "$DESTINO/public/resources/bootswatch5" ]; then
    echo "assets ausentes -> build"
    [ -d "$REPO/node_modules" ] || (cd "$REPO" && sudo -u abracadabra npm install --no-audit --no-fund 2>&1 | tail -2)
    (cd "$REPO" && sudo -u abracadabra npx gulp default 2>&1 | tail -2)
    rsync -a "$REPO/public/resources/" "$DESTINO/public/resources/"
  fi

  mkdir -p "$DESTINO/writable/cache" "$DESTINO/writable/logs" "$DESTINO/writable/session" "$DESTINO/writable/uploads"
  chown -R www-data:www-data "$DESTINO"
  chmod -R 755 "$DESTINO"
  chmod -R 775 "$DESTINO/writable"
  chmod 640 "$DESTINO/.env" 2>/dev/null || true

  # 3. Migrations
  (cd "$DESTINO" && sudo -u www-data php spark migrate --all 2>&1 | tail -3) || true

  rm -rf "$DESTINO/writable/cache/"* 2>/dev/null || true
  systemctl reload php8.4-fpm 2>/dev/null || true

  # 4. Verificacao pos-deploy (falha alto se o app nao responder)
  sleep 2
  CODE=$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: sys.severinus.com.br' http://127.0.0.1:8084/login || echo 000)
  echo "healthcheck /login -> $CODE"
  if [ "$CODE" != "200" ]; then
    echo "FALHA: app retornou $CODE. Backup em /var/www/ospos.bak-$STAMP"
  else
    echo "$REMOTE" > "$STATE/last_commit"
  fi
  echo "OK"
} >> "$LOG" 2>&1
