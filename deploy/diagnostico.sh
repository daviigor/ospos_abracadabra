#!/bin/bash
# Diagnostico do design quebrado em sys.severinus.com.br
# Roda como root: bash /home/abracadabra/pdv-migracao/diagnostico.sh
# Para assim que encontrar o problema, ou mostra tudo OK.

echo "=============================================="
echo " DIAGNOSTICO OSPOS - $(date '+%F %T')"
echo "=============================================="
echo

echo "[1] HTTP local (nginx -> php)"
C1=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: sys.severinus.com.br" http://127.0.0.1:8084/login)
echo "    /login -> $C1"
[ "$C1" != "200" ] && echo "    >>> PROBLEMA: app nao responde 200"

echo
echo "[2] base href no HTML (deve ser https://)"
BASE=$(curl -s -H "Host: sys.severinus.com.br" http://127.0.0.1:8084/login | grep -oE 'base href="[^"]*"')
echo "    $BASE"
if echo "$BASE" | grep -q 'http://sys'; then
  echo "    >>> PROBLEMA ENCONTRADO: base href em http:// (mixed content)"
  echo "        O navegador em https:// bloqueia os assets http:// -> design quebrado"
  echo "        CORRECAO: nginx precisa passar HTTPS on / X-Forwarded-Proto ao PHP"
  echo
  echo "[2b] nginx passa X-Forwarded-Proto?"
  grep -nE "HTTPS on|X_FORWARDED_PROTO" /etc/nginx/sites-enabled/ospos-sys || echo "    NAO -> e essa a causa"
  echo
  echo "[2c] testa com header forcado (prova que e isso)"
  PROVA=$(curl -s -H "Host: sys.severinus.com.br" -H "X-Forwarded-Proto: https" http://127.0.0.1:8084/login | grep -oE 'base href="[^"]*"')
  echo "    com X-Forwarded-Proto: https -> $PROVA"
  exit 1
fi

echo
echo "[3] assets do login"
for a in "resources/bootswatch5/flatly/bootstrap.min.css" "css/login.css" "images/favicon.ico"; do
  C=$(curl -s -H "Host: sys.severinus.com.br" -o /dev/null -w '%{http_code}' "http://127.0.0.1:8084/$a")
  echo "    $C  $a"
done

echo
echo "[4] assets do layout (pos-login)"
for a in "resources/opensourcepos-c3c51fd7e7.min.css" "resources/opensourcepos-f26533fa83.min.js" "resources/jquery-2c872dbe60.min.js"; do
  C=$(curl -s -H "Host: sys.severinus.com.br" -o /dev/null -w '%{http_code}' "http://127.0.0.1:8084/$a")
  echo "    $C  $a"
done

echo
echo "[5] externo (via Cloudflare)"
E=$(curl -s -o /dev/null -w '%{http_code}' -L --max-time 25 https://sys.severinus.com.br/login)
echo "    https://sys.severinus.com.br/login -> $E"

echo
echo "[6] migrations aplicadas"
export MYSQL_PWD=pointofsale
M=$(mysql -h127.0.0.1 -P3307 -uroot -N -e "SELECT COUNT(*) FROM pdv_severinus.ospos_migrations;" 2>/dev/null)
echo "    $M migrations (esperado: 46)"

echo
echo "[7] dados"
D=$(mysql -h127.0.0.1 -P3307 -uroot -N -e "SELECT CONCAT((SELECT COUNT(*) FROM pdv_severinus.ospos_items),' itens / ',(SELECT COUNT(*) FROM pdv_severinus.ospos_sales),' vendas');" 2>/dev/null)
echo "    $D"
unset MYSQL_PWD

echo
echo "[8] .env critico"
grep -E "CI_ENVIRONMENT|baseURL|forceGlobalSecure" /var/www/ospos/.env

echo
echo "=============================================="
echo " FIM"
echo "=============================================="
