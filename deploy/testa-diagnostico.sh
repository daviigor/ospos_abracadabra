#!/bin/bash
# Prova que o teste [4b] do diagnostico.sh pega o bug de injecao vazia.
# Roda como root: bash /home/abracadabra/pdv-migracao/testa-diagnostico.sh
set -u
LOGIN=/var/www/ospos/app/Views/login.php
HDR=/var/www/ospos/app/Views/partial/header.php

echo "=== 1. backup do estado bom ==="
cp "$LOGIN" /tmp/login.php.bom
cp "$HDR"   /tmp/header.php.bom
echo "    salvo em /tmp/*.bom"

echo
echo "=== 2. simulando o bug: tirando as tags do login.php ==="
sed -i '/<script src/d' "$LOGIN"
sed -i '/<link rel="stylesheet"/d' "$LOGIN"
echo "    login.php agora: $(grep -c '<script src' "$LOGIN") scripts (era 3)"

echo
echo "=== 3. o diagnostico DETECTA? ==="
bash /home/abracadabra/pdv-migracao/diagnostico.sh 2>&1 | sed -n '/\[4b\]/,/\[5\]/p'
RC=$?

echo
echo "=== 4. restaurando estado bom ==="
cp /tmp/login.php.bom "$LOGIN"
cp /tmp/header.php.bom "$HDR"
chown www-data:www-data "$LOGIN" "$HDR"
echo "    login.php restaurado: $(grep -c '<script src' "$LOGIN") scripts"

echo
echo "=== 5. confirma que voltou ao normal ==="
curl -s -H "Host: sys.severinus.com.br" -o /dev/null -w '    /login -> %{http_code}\n' http://127.0.0.1:8084/login
