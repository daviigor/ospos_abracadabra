#!/usr/bin/env bash
# Prova F3 — /api/itens (criar/alterar/excluir) contra o OSPOS DEV (:8085).
# Roda os 9 cenarios e falha se qualquer status HTTP nao bater.
set -u
B="${B:-http://127.0.0.1:8085}"
COD="T-F3-PROVA-$(date +%s)"
fail=0

check() { # check <esperado> <desc> <arquivo>
    local exp="$1" desc="$2" f="$3"
    local got
    got=$(tail -1 "$f" | grep -o '[0-9]\{3\}')
    if [ "$got" = "$exp" ]; then echo "OK   $desc -> HTTP $got"; else echo "FAIL $desc -> HTTP $got (esperado $exp)"; fail=1; fi
}

echo "== base: $B  codigo: $COD =="

echo "-- 1) POST criar"
curl -s -w "\nHTTP %{http_code}\n" -X POST "$B/api/itens" -H 'Content-Type: application/json' \
    -d "{\"name\":\"PROVA F3\",\"item_number\":\"$COD\",\"cost_price\":\"10.50\",\"unit_price\":\"19.90\"}" > /tmp/f3_post.txt
cat /tmp/f3_post.txt; check 201 "POST criar" /tmp/f3_post.txt

ID=$(grep -o '"item_id": [0-9]*' /tmp/f3_post.txt | head -1 | grep -o '[0-9]*')
echo "   id=$ID"

echo "-- 2) GET ler"
curl -s -w "\nHTTP %{http_code}\n" "$B/api/itens/$ID" > /tmp/f3_get.txt
cat /tmp/f3_get.txt; check 200 "GET ler" /tmp/f3_get.txt

echo "-- 3) PATCH alterar so o preco"
curl -s -w "\nHTTP %{http_code}\n" -X PATCH "$B/api/itens/$ID" -H 'Content-Type: application/json' \
    -d '{"unit_price":"24.90"}' > /tmp/f3_patch.txt
cat /tmp/f3_patch.txt; check 200 "PATCH preco" /tmp/f3_patch.txt

echo "-- 4) PUT alterar nome"
curl -s -w "\nHTTP %{http_code}\n" -X PUT "$B/api/itens/$ID" -H 'Content-Type: application/json' \
    -d '{"name":"PROVA F3 ALTERADA"}' > /tmp/f3_put.txt
cat /tmp/f3_put.txt; check 200 "PUT nome" /tmp/f3_put.txt

echo "-- 5) DELETE excluir (soft)"
curl -s -w "\nHTTP %{http_code}\n" -X DELETE "$B/api/itens/$ID" > /tmp/f3_del.txt
cat /tmp/f3_del.txt; check 200 "DELETE excluir" /tmp/f3_del.txt

echo "-- 6) GET apos excluir -> 404"
curl -s -w "\nHTTP %{http_code}\n" "$B/api/itens/$ID" > /tmp/f3_404.txt
cat /tmp/f3_404.txt; check 404 "GET apos excluir" /tmp/f3_404.txt

echo "-- 7) GET id inexistente -> 404"
curl -s -w "\nHTTP %{http_code}\n" "$B/api/itens/999999" > /tmp/f3_nx.txt
cat /tmp/f3_nx.txt; check 404 "GET inexistente" /tmp/f3_nx.txt

echo "-- 8) POST codigo repetido -> 409"
curl -s -w "\nHTTP %{http_code}\n" -X POST "$B/api/itens" -H 'Content-Type: application/json' \
    -d '{"name":"DUP","item_number":"TESTE-001"}' > /tmp/f3_409.txt
cat /tmp/f3_409.txt; check 409 "codigo repetido" /tmp/f3_409.txt

echo "-- 9) POST sem name -> 400"
curl -s -w "\nHTTP %{http_code}\n" -X POST "$B/api/itens" -H 'Content-Type: application/json' \
    -d '{"item_number":"SEM-NOME-1"}' > /tmp/f3_400.txt
cat /tmp/f3_400.txt; check 400 "sem name" /tmp/f3_400.txt

echo
if [ "$fail" = 0 ]; then echo "RESULTADO: TODOS OS 9 CENARIOS PASSARAM"; else echo "RESULTADO: FALHOU"; fi
exit $fail
