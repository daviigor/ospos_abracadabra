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
echo "[4b] INJECAO DO BUILD nos Views (o ponto cego que quebrou 3x)"
# `gulp default` injeta os <script>/<link> entre os marcadores <!-- inject:prod:* -->.
# Se o gulp nao rodar, o arquivo de asset EXISTE e o teste [4] passa verde,
# mas o HTML nao o referencia -> sem jQuery, sem CSS -> UI quebrada.
# Aqui o teste e no CONTEUDO: quantas tags o HTML realmente emite.
NEST=$(curl -s -H "Host: sys.severinus.com.br" http://127.0.0.1:8084/login | grep -c '<script src\|<link rel="stylesheet"')
echo "    tags no HTML do login: $NEST"
if [ "$NEST" -lt 3 ]; then
  echo "    >>> PROBLEMA ENCONTRADO: HTML do login quase sem tags de asset"
  echo "        Causa: gulp nao rodou / Views com bloco de injecao VAZIO"
  echo "        CORRECAO: cd /home/abracadabra/ospos-fork && npx gulp default"
  echo "                  depois redeploy (o auto-deploy ja roda gulp a cada vez)"
  echo
  echo "[4c] estado dos Views no destino"
  echo "    header.php: $(grep -c '<script src' /var/www/ospos/app/Views/partial/header.php) scripts"
  echo "    login.php:  $(grep -c '<script src' /var/www/ospos/app/Views/login.php) scripts"
  echo "    (esperado: header ~37, login 3)"
  exit 1
fi

echo
echo "[4d] jQuery presente no login (sem ele o layout colapsa)"
if ! curl -s -H "Host: sys.severinus.com.br" http://127.0.0.1:8084/login | grep -q 'jquery'; then
  echo "    >>> PROBLEMA ENCONTRADO: login sem jQuery"
  exit 1
fi
echo "    OK"

echo
echo "[4e] logotipo configurado existe no disco"
LOGO=$(MYSQL_PWD=pointofsale mysql -h127.0.0.1 -P3307 -uroot -N -e "SELECT value FROM pdv_severinus.ospos_app_config WHERE \`key\`='company_logo';" 2>/dev/null)
if [ -n "$LOGO" ]; then
  LC=$(curl -s -H "Host: sys.severinus.com.br" -o /dev/null -w '%{http_code}' "http://127.0.0.1:8084/uploads/$LOGO")
  echo "    uploads/$LOGO -> $LC"
  [ "$LC" != "200" ] && echo "    >>> PROBLEMA: logo configurado no banco mas arquivo ausente (uploads/ e gitignored)"
fi

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
