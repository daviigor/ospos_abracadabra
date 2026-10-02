<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Paginas institucionais servidas pelo proprio PDV.
 *
 *   /api   -> documentacao das rotas (estilo Swagger, sem dependencia nova)
 *   /mcp   -> o que e o MCP, download do cliente e instrucoes por IA
 *
 * Nao usam sessao: sao publicas de proposito (documentacao). Nenhuma delas
 * expoe dado da loja nem permite acao.
 */
class Docs_controller extends Api_base_controller
{
    /** GET /api */
    public function getIndex(): ResponseInterface
    {
        $base = rtrim(base_url(), '/');

        $rotas = [
            [
                'metodo' => 'POST', 'rota' => '/api/auth/token',
                'grant' => '—', 'desc' => 'Troca usuario+senha do PDV por um JWT.',
                'corpo' => '{"username": "...", "password": "..."}',
                'curl' => "curl -s -X POST {$base}/api/auth/token \\\n"
                    . "  -H 'Content-Type: application/json' \\\n"
                    . '  -d \'{"username":"seu_usuario","password":"sua_senha"}\'',
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/itens',
                'grant' => 'items',
                'desc' => 'Lista/busca itens paginado. Parametros: search, limit (1-100), offset.',
                'corpo' => '—',
                'curl' => "curl -s \"{$base}/api/itens?search=arco&limit=20\" -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/itens/{id}',
                'grant' => 'items', 'desc' => 'Le um item pelo id.',
                'corpo' => '—',
                'curl' => "curl -s {$base}/api/itens/1 -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'POST', 'rota' => '/api/itens',
                'grant' => 'items', 'desc' => 'Cria um item. Retorna 201 com o id.',
                'corpo' => '{"name":"Produto","description":"...","item_number":"COD1",'
                    . '"cost_price":"10.00","unit_price":"20.00","quantity_1":"5","supplier_id":2}',
                'curl' => "curl -s -X POST {$base}/api/itens \\\n"
                    . "  -H 'Authorization: Bearer <TOKEN>' -H 'Content-Type: application/json' \\\n"
                    . '  -d \'{"name":"Produto","unit_price":"20.00","quantity_1":"5"}\'',
            ],
            [
                'metodo' => 'PUT|PATCH', 'rota' => '/api/itens/{id}',
                'grant' => 'items', 'desc' => 'Altera so os campos enviados.',
                'corpo' => '{"unit_price":"22.00"}',
                'curl' => "curl -s -X PATCH {$base}/api/itens/1 \\\n"
                    . "  -H 'Authorization: Bearer <TOKEN>' -H 'Content-Type: application/json' \\\n"
                    . '  -d \'{"unit_price":"22.00"}\'',
            ],
            [
                'metodo' => 'DELETE', 'rota' => '/api/itens/{id}',
                'grant' => 'items', 'desc' => 'Exclui (soft delete), igual ao PDV.',
                'corpo' => '—',
                'curl' => "curl -s -X DELETE {$base}/api/itens/1 -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/atributos',
                'grant' => 'attributes', 'desc' => 'Catalogo de definicoes (nome, unidade, tipo, flags).',
                'corpo' => '—',
                'curl' => "curl -s {$base}/api/atributos -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/atributos/{item_id}',
                'grant' => 'attributes', 'desc' => 'Valores de atributo de um item. Parametro: definicao.',
                'corpo' => '—',
                'curl' => "curl -s \"{$base}/api/atributos/5?definicao=7\" -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'POST', 'rota' => '/api/atributos',
                'grant' => 'attributes', 'desc' => 'Cria definicao. Retorna 201 com o id.',
                'corpo' => '{"nome":"Voltagem","unidade":"V","tipo":"DROPDOWN","flags":[1],"valores":["110","220"]}',
                'curl' => "curl -s -X POST {$base}/api/atributos \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"nome":"Voltagem","tipo":"DROPDOWN","flags":[1]}\'',
            ],
            [
                'metodo' => 'PATCH', 'rota' => '/api/atributos/{id}',
                'grant' => 'attributes', 'desc' => 'Altera a definicao.',
                'corpo' => '{"nome":"Voltagem (V)"}',
                'curl' => "curl -s -X PATCH {$base}/api/atributos/7 \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"nome":"Voltagem (V)"}\'',
            ],
            [
                'metodo' => 'DELETE', 'rota' => '/api/atributos/{id}',
                'grant' => 'attributes', 'desc' => 'Apaga a definicao (soft delete).',
                'corpo' => '—',
                'curl' => "curl -s -X DELETE {$base}/api/atributos/7 -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'POST', 'rota' => '/api/atributos/valores',
                'grant' => 'attributes', 'desc' => 'Grava o valor de um atributo num item.',
                'corpo' => '{"item_id":5,"definicao_id":7,"valor":"220"}',
                'curl' => "curl -s -X POST {$base}/api/atributos/valores \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"item_id":5,"definicao_id":7,"valor":"220"}\'',
            ],
            [
                'metodo' => 'DELETE', 'rota' => '/api/atributos/valores',
                'grant' => 'attributes', 'desc' => 'Apaga um valor de dropdown pelo par valor+definicao_id.',
                'corpo' => '{"valor":"220","definicao_id":7}',
                'curl' => "curl -s -X DELETE {$base}/api/atributos/valores \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"valor":"220","definicao_id":7}\'',
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/clientes',
                'grant' => 'customers', 'desc' => 'Lista/busca clientes. Parametros: search, limit, offset.',
                'corpo' => '—',
                'curl' => "curl -s \"{$base}/api/clientes?search=joao&limit=20\" -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/clientes/{id}',
                'grant' => 'customers', 'desc' => 'Ficha do cliente + estatisticas de compra.',
                'corpo' => '—',
                'curl' => "curl -s {$base}/api/clientes/12 -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'POST', 'rota' => '/api/clientes',
                'grant' => 'customers', 'desc' => 'Cria cliente (pessoa + dados comerciais). 201 com o id.',
                'corpo' => '{"nome":"Loja do Zé","sobrenome":"Ferragens","cnpj":"12345678000199","fantasia":"Zé Ferragens","email":"ze@ex.com","telefone":"17999990000"}',
                'curl' => "curl -s -X POST {$base}/api/clientes \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"nome":"Loja do Zé","cnpj":"12345678000199","email":"ze@ex.com"}\'',
            ],
            [
                'metodo' => 'PATCH', 'rota' => '/api/clientes/{id}',
                'grant' => 'customers', 'desc' => 'Altera so os campos enviados.',
                'corpo' => '{"telefone":"17988887777"}',
                'curl' => "curl -s -X PATCH {$base}/api/clientes/12 \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"telefone":"17988887777"}\'',
            ],
            [
                'metodo' => 'DELETE', 'rota' => '/api/clientes/{id}',
                'grant' => 'customers', 'desc' => 'Apaga o cliente (soft delete).',
                'corpo' => '—',
                'curl' => "curl -s -X DELETE {$base}/api/clientes/12 -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/fornecedores',
                'grant' => 'suppliers', 'desc' => 'Lista/busca fornecedores. Parametros: search, limit, offset, categoria.',
                'corpo' => '—',
                'curl' => "curl -s \"{$base}/api/fornecedores?search=acme\" -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/fornecedores/{id}',
                'grant' => 'suppliers', 'desc' => 'Ficha do fornecedor.',
                'corpo' => '—',
                'curl' => "curl -s {$base}/api/fornecedores/4 -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/fornecedores/categorias',
                'grant' => 'suppliers', 'desc' => 'Tipos de fornecedor (mercadoria, servico...).',
                'corpo' => '—',
                'curl' => "curl -s {$base}/api/fornecedores/categorias -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'POST', 'rota' => '/api/fornecedores',
                'grant' => 'suppliers', 'desc' => 'Cria fornecedor. 201 com o id.',
                'corpo' => '{"nome":"ACME Ltda","cnpj":"98765432000155","fantasia":"ACME","email":"vendas@acme.com"}',
                'curl' => "curl -s -X POST {$base}/api/fornecedores \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"nome":"ACME Ltda","cnpj":"98765432000155"}\'',
            ],
            [
                'metodo' => 'PATCH', 'rota' => '/api/fornecedores/{id}',
                'grant' => 'suppliers', 'desc' => 'Altera so os campos enviados.',
                'corpo' => '{"telefone":"1733332222"}',
                'curl' => "curl -s -X PATCH {$base}/api/fornecedores/4 \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"telefone":"1733332222"}\'',
            ],
            [
                'metodo' => 'DELETE', 'rota' => '/api/fornecedores/{id}',
                'grant' => 'suppliers', 'desc' => 'Apaga o fornecedor (soft delete).',
                'corpo' => '—',
                'curl' => "curl -s -X DELETE {$base}/api/fornecedores/4 -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/vendas',
                'grant' => 'reports_sales', 'desc' => 'Lista/busca vendas. Parametros: search, limit, offset.',
                'corpo' => '—',
                'curl' => "curl -s \"{$base}/api/vendas?limit=20\" -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/vendas/{id}',
                'grant' => 'reports_sales', 'desc' => 'Cabecalho, itens e pagamentos de uma venda.',
                'corpo' => '—',
                'curl' => "curl -s {$base}/api/vendas/1 -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'POST', 'rota' => '/api/vendas',
                'grant' => 'sales',
                'desc' => 'Grava venda, cotacao, fatura ou devolucao. tipo = venda|cotacao|fatura|devolucao. '
                    . 'Na devolucao use devolucao_de: "POS 123" (a venda original).',
                'corpo' => '{"tipo":"venda","cliente_id":12,"local_id":1,'
                    . '"itens":[{"item_id":5,"quantidade":"2","preco":"19.90","desconto":"0"}],'
                    . '"pagamentos":[{"tipo":"Dinheiro","valor":"39.80"}]}',
                'curl' => "curl -s -X POST {$base}/api/vendas \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"tipo":"venda","itens":[{"item_id":5,"quantidade":"2","preco":"19.90"}],'
                    . '"pagamentos":[{"tipo":"Dinheiro","valor":"39.80"}]}\'',
            ],
            [
                'metodo' => 'DELETE', 'rota' => '/api/vendas/{id}',
                'grant' => 'sales_delete', 'desc' => 'Cancela a venda e devolve o estoque.',
                'corpo' => '—',
                'curl' => "curl -s -X DELETE {$base}/api/vendas/1 -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/recebimentos',
                'grant' => 'receivings', 'desc' => 'Lista recebimentos. Parametros: search, limit, offset.',
                'corpo' => '—',
                'curl' => "curl -s \"{$base}/api/recebimentos?limit=20\" -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/recebimentos/{id}',
                'grant' => 'receivings', 'desc' => 'Cabecalho e itens de um recebimento.',
                'corpo' => '—',
                'curl' => "curl -s {$base}/api/recebimentos/1 -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/recebimentos/opcoes',
                'grant' => 'receivings', 'desc' => 'Locais, fornecedores e formas de pagamento.',
                'corpo' => '—',
                'curl' => "curl -s {$base}/api/recebimentos/opcoes -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'POST', 'rota' => '/api/recebimentos',
                'grant' => 'receivings',
                'desc' => 'Grava recebimento de compra (tipo=recebimento), requisicao/transferencia '
                    . '(tipo=requisicao, com origem_id e destino_id) ou devolucao ao fornecedor (tipo=devolucao).',
                'corpo' => '{"tipo":"recebimento","fornecedor_id":4,"local_id":1,"referencia":"NF 123",'
                    . '"itens":[{"item_id":5,"quantidade":"10","custo":"12.50"}]}',
                'curl' => "curl -s -X POST {$base}/api/recebimentos \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"tipo":"recebimento","fornecedor_id":4,"local_id":1,'
                    . '"itens":[{"item_id":5,"quantidade":"10","custo":"12.50"}]}\'',
            ],
            [
                'metodo' => 'DELETE', 'rota' => '/api/recebimentos/{id}',
                'grant' => 'receivings_delete', 'desc' => 'Cancela o recebimento e estorna o estoque.',
                'corpo' => '—',
                'curl' => "curl -s -X DELETE {$base}/api/recebimentos/1 -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/despesas',
                'grant' => 'expenses',
                'desc' => 'Lista/busca despesas. Parametros: search, data_inicio, data_fim, categoria, pagamento, limit, offset.',
                'corpo' => '—',
                'curl' => "curl -s \"{$base}/api/despesas?data_inicio=2026-01-01&data_fim=2026-12-31\" -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/despesas/{id}',
                'grant' => 'expenses', 'desc' => 'Uma despesa + seus pagamentos.',
                'corpo' => '—',
                'curl' => "curl -s {$base}/api/despesas/1 -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'POST', 'rota' => '/api/despesas',
                'grant' => 'expenses', 'desc' => 'Lanca uma despesa. 201 com o id.',
                'corpo' => '{"valor":"250.00","categoria_id":3,"descricao":"Energia","pagamento":"Transferencia","imposto":"0"}',
                'curl' => "curl -s -X POST {$base}/api/despesas \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"valor":"250.00","categoria_id":3,"descricao":"Energia"}\'',
            ],
            [
                'metodo' => 'PATCH', 'rota' => '/api/despesas/{id}',
                'grant' => 'expenses', 'desc' => 'Altera so os campos enviados.',
                'corpo' => '{"valor":"260.00"}',
                'curl' => "curl -s -X PATCH {$base}/api/despesas/1 \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"valor":"260.00"}\'',
            ],
            [
                'metodo' => 'DELETE', 'rota' => '/api/despesas/{id}',
                'grant' => 'expenses', 'desc' => 'Apaga a despesa.',
                'corpo' => '—',
                'curl' => "curl -s -X DELETE {$base}/api/despesas/1 -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/despesas/categorias',
                'grant' => 'expenses', 'desc' => 'Lista as categorias de despesa.',
                'corpo' => '—',
                'curl' => "curl -s {$base}/api/despesas/categorias -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'POST', 'rota' => '/api/despesas/categorias',
                'grant' => 'expenses_categories', 'desc' => 'Cria categoria de despesa.',
                'corpo' => '{"nome":"Frete","descricao":"Fretes e carretos"}',
                'curl' => "curl -s -X POST {$base}/api/despesas/categorias \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"nome":"Frete"}\'',
            ],
            [
                'metodo' => 'PATCH', 'rota' => '/api/despesas/categorias/{id}',
                'grant' => 'expenses_categories', 'desc' => 'Altera a categoria.',
                'corpo' => '{"nome":"Frete e carreto"}',
                'curl' => "curl -s -X PATCH {$base}/api/despesas/categorias/3 \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"nome":"Frete e carreto"}\'',
            ],
            [
                'metodo' => 'DELETE', 'rota' => '/api/despesas/categorias/{id}',
                'grant' => 'expenses_categories', 'desc' => 'Apaga a categoria (bloqueia se estiver em uso).',
                'corpo' => '—',
                'curl' => "curl -s -X DELETE {$base}/api/despesas/categorias/3 -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/giftcards',
                'grant' => 'giftcards', 'desc' => 'Lista/busca cartoes presente. Parametros: search, limit, offset.',
                'corpo' => '—',
                'curl' => "curl -s \"{$base}/api/giftcards?search=100\" -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/giftcards/{id}',
                'grant' => 'giftcards', 'desc' => 'Dados de um cartao.',
                'corpo' => '—',
                'curl' => "curl -s {$base}/api/giftcards/1 -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/giftcards/saldo/{numero}',
                'grant' => 'giftcards', 'desc' => 'Saldo atual de um cartao pelo numero.',
                'corpo' => '—',
                'curl' => "curl -s {$base}/api/giftcards/saldo/1001 -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'GET', 'rota' => '/api/giftcards/proximo-numero',
                'grant' => 'giftcards', 'desc' => 'Sugere o proximo numero de cartao livre.',
                'corpo' => '—',
                'curl' => "curl -s {$base}/api/giftcards/proximo-numero -H 'Authorization: Bearer ***'",
            ],
            [
                'metodo' => 'POST', 'rota' => '/api/giftcards',
                'grant' => 'giftcards', 'desc' => 'Cria cartao com valor inicial. 201 com o id.',
                'corpo' => '{"valor":"100.00","cliente_id":12}',
                'curl' => "curl -s -X POST {$base}/api/giftcards \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"valor":"100.00","cliente_id":12}\'',
            ],
            [
                'metodo' => 'POST', 'rota' => '/api/giftcards/{numero}/recarregar',
                'grant' => 'giftcards', 'desc' => 'Soma valor ao saldo do cartao.',
                'corpo' => '{"valor":"50.00"}',
                'curl' => "curl -s -X POST {$base}/api/giftcards/1001/recarregar \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"valor":"50.00"}\'',
            ],
            [
                'metodo' => 'PATCH', 'rota' => '/api/giftcards/{id}',
                'grant' => 'giftcards', 'desc' => 'Altera numero, saldo ou dono do cartao.',
                'corpo' => '{"cliente_id":13}',
                'curl' => "curl -s -X PATCH {$base}/api/giftcards/1 \\\\\\n"
                    . "  -H 'Authorization: Bearer ***' -H 'Content-Type: application/json' \\\\\\n"
                    . '  -d \'{"cliente_id":13}\'',
            ],
            [
                'metodo' => 'DELETE', 'rota' => '/api/giftcards/{id}',
                'grant' => 'giftcards', 'desc' => 'Apaga o cartao (soft delete).',
                'corpo' => '—',
                'curl' => "curl -s -X DELETE {$base}/api/giftcards/1 -H 'Authorization: Bearer ***'",
            ],
        ];

        $linhas = '';
        foreach ($rotas as $r) {
            $linhas .= '<tr>'
                . '<td><span class="m">' . esc($r['metodo']) . '</span></td>'
                . '<td><code>' . esc($r['rota']) . '</code></td>'
                . '<td>' . esc($r['grant']) . '</td>'
                . '<td>' . esc($r['desc']) . '</td>'
                . '</tr>' . "\n";

            $linhas .= '<tr class="det"><td colspan="4">'
                . '<div class="lbl">corpo</div><pre>' . esc($r['corpo']) . '</pre>'
                . '<div class="lbl">exemplo</div><pre>' . esc($r['curl']) . '</pre>'
                . '</td></tr>' . "\n";
        }

        $api = $base . '/api/auth/token';

        $html = $this->layout('API — PDV', <<<HTML
<p class="lead">
  API JSON servida pela <strong>propria aplicacao do PDV</strong>. Nao ha servico
  separado: PDV de pe significa API de pe. As regras de negocio, validacoes e
  permissoes sao as mesmas da tela — a API reusa os models do OSPOS.
</p>

<h2>1. Pegue o token</h2>
<p>Use o <strong>mesmo usuario e senha</strong> que voce usa no PDV.</p>
<pre>curl -s -X POST {$api} \\
  -H 'Content-Type: application/json' \\
  -d '{"username":"seu_usuario","password":"sua_senha"}'</pre>
<p class="ret">Resposta: <code>{"success":true,"token":"eyJ...","tipo":"Bearer","expira_em":43200}</code></p>
<p>Mande esse token no cabecalho <code>Authorization: Bearer &lt;TOKEN&gt;</code> nas demais rotas.</p>

<h2>2. Rotas</h2>
<table>
  <thead><tr><th>Metodo</th><th>Rota</th><th>Permissao</th><th>O que faz</th></tr></thead>
  <tbody>
{$linhas}
  </tbody>
</table>

<h2>3. Codigos de retorno</h2>
<ul class="codes">
  <li><b>200</b> ok</li>
  <li><b>201</b> item criado</li>
  <li><b>400</b> dado invalido</li>
  <li><b>401</b> token ausente, invalido ou expirado</li>
  <li><b>403</b> sem permissao no modulo</li>
  <li><b>404</b> item nao encontrado</li>
  <li><b>409</b> item_number ja existe</li>
</ul>

<h2>4. Permissoes</h2>
<p>
  O token carrega o funcionario. Cada rota exige o mesmo <em>grant</em> de modulo
  que a tela do PDV usa (<code>ospos_grants</code>). Sem o grant, a resposta e 403
  mesmo com token valido.
</p>

<h2>5. Seguranca</h2>
<ul class="codes">
  <li>Token JWT HS256, assinado com <code>JWT_SECRET</code> do <code>.env</code>.</li>
  <li>Senha conferida com o mesmo hash do PDV (bcrypt).</li>
  <li>Erro de login generico: nao revela se o usuario existe.</li>
  <li>Escrita apenas pelos models do PDV — sem SQL montado a mao.</li>
</ul>
HTML);

        return $this->response->setBody($html);
    }

    /** GET /mcp */
    public function getMcp(): ResponseInterface
    {
        $base = rtrim(base_url(), '/');
        $download = $base . '/mcp-download/servidor-mcp-pdv.js';

        $html = $this->layout('MCP — PDV', <<<HTML
<p class="lead">
  O <strong>MCP</strong> conecta uma IA (Claude, Cursor, Hermes, ChatGPT Desktop...)
  diretamente ao seu PDV. A IA passa a consultar e cadastrar itens
  <em>usando as regras do seu PDV</em>, sem acesso direto ao banco.
</p>

<h2>Como funciona</h2>
<ol class="codes">
  <li>A IA roda o <code>servidor-mcp-pdv.js</code> na maquina dela.</li>
  <li>O servidor fala com a API deste PDV em <code>{$base}/api</code>.</li>
  <li>Toda acao passa pelas validacoes e permissoes do PDV.</li>
</ol>

<h2>Download</h2>
<p><a class="btn" href="{$download}" download>Baixar servidor-mcp-pdv.js</a></p>
<p class="ret">Escrito em Node.js. Precisa apenas de Node 18+ (sem instalar pacotes).</p>

<h2>Configuracao</h2>
<p>Antes de rodar, edite as duas primeiras linhas do arquivo:</p>
<pre>const PDV_URL   = '{$base}';   // endereco deste PDV
const PDV_TOKEN = 'SEU_TOKEN_JWT';      // veja como gerar em /api</pre>

<h2>Instrucoes por IA</h2>

<h3>Claude Desktop</h3>
<pre>{
  "mcpServers": {
    "pdv": {
      "command": "node",
      "args": ["/caminho/para/servidor-mcp-pdv.js"]
    }
  }
}</pre>

<h3>Cursor</h3>
<p>Settings &rarr; MCP &rarr; Add new MCP server &rarr; tipo <code>command</code>,
comando <code>node /caminho/para/servidor-mcp-pdv.js</code>.</p>

<h3>Hermes Agent</h3>
<pre>mcp:
  servers:
    pdv:
      command: node
      args: ['/caminho/para/servidor-mcp-pdv.js']</pre>

<h3>Qualquer outra IA</h3>
<p>
  De o arquivo e diga: <em>"este e um servidor MCP via stdio; configure
  como servidor MCP e use as tools pdv_* que ele expoe"</em>.
  O proprio arquivo documenta as tools no topo.
</p>

<h2>Ferramentas expostas</h2>
<ul class="codes">
  <li><code>pdv_buscar_itens</code> — lista/busca itens (paginado)</li>
  <li><code>pdv_obter_item</code> — le um item pelo id</li>
  <li><code>pdv_criar_item</code> — cria item</li>
  <li><code>pdv_alterar_item</code> — altera campos do item</li>
  <li><code>pdv_excluir_item</code> — exclui (soft delete)</li>
  <li><code>pdv_buscar_clientes</code> — lista/busca clientes</li>
  <li><code>pdv_obter_cliente</code> — ficha do cliente</li>
  <li><code>pdv_criar_cliente</code> — cria cliente (PJ: nome = razao social, fantasia = nome fantasia, cnpj = CNPJ)</li>
  <li><code>pdv_alterar_cliente</code> — altera campos do cliente</li>
  <li><code>pdv_buscar_fornecedores</code> — lista/busca fornecedores</li>
  <li><code>pdv_criar_fornecedor</code> — cria fornecedor</li>
  <li><code>pdv_buscar_atributos</code> — lista definicoes de atributo (Voltagem, Cor, Tamanho...)</li>
  <li><code>pdv_obter_atributos_item</code> — valores de atributo de um item</li>
  <li><code>pdv_gravar_atributo_item</code> — grava valor de atributo num item</li>
  <li><code>pdv_buscar_vendas</code> — lista/busca vendas gravadas</li>
  <li><code>pdv_obter_venda</code> — itens e pagamentos de uma venda</li>
  <li><code>pdv_gravar_venda</code> — grava venda, cotacao, fatura (fiado) ou devolucao</li>
  <li><code>pdv_buscar_recebimentos</code> — lista recebimentos de compra</li>
  <li><code>pdv_obter_recebimento</code> — itens e custos de um recebimento</li>
  <li><code>pdv_gravar_recebimento</code> — recebimento de compra, requisicao ou devolucao ao fornecedor</li>
  <li><code>pdv_buscar_despesas</code> — lista/busca despesas por periodo e categoria</li>
  <li><code>pdv_lancar_despesa</code> — lanca despesa</li>
  <li><code>pdv_buscar_categorias_despesa</code> — categorias de despesa</li>
  <li><code>pdv_buscar_giftcards</code> — lista cartoes presente</li>
  <li><code>pdv_saldo_giftcard</code> — saldo do cartao pelo numero</li>
  <li><code>pdv_criar_giftcard</code> — cria cartao com valor inicial</li>
  <li><code>pdv_recarregar_giftcard</code> — soma valor ao saldo</li>
</ul>
<p class="ret">Sao 27 tools. Todas batem nas rotas de <a href="{$base}/api">{$base}/api</a> — mesmas permissoes
do usuario do PDV cujo token foi colado no arquivo. Os mesmos numeros do Swagger.</p>

<h2>Credenciais</h2>
<p>
  O MCP usa um <strong>token JWT</strong> do PDV, obtido em <code>/api</code> com
  um usuario que tenha permissao de itens. Gere, cole no arquivo, pronto.
  O token expira; gere outro quando vencer.
</p>
HTML);

        return $this->response->setBody($html);
    }

    /**
     * Moldura HTML comum. CSS inline de proposito: sem build, sem CDN.
     */
    private function layout(string $titulo, string $conteudo): string
    {
        $css = <<<'CSS'
:root{--bg:#0f1115;--card:#171a21;--bd:#262b36;--fg:#e6e9ef;--mu:#9aa4b2;--ac:#5b9dff;--ok:#3fbf7f}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);
     font:15px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.wrap{max-width:920px;margin:0 auto;padding:48px 22px 90px}
header{border-bottom:1px solid var(--bd);padding-bottom:22px;margin-bottom:34px}
.kicker{font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--ac);font-weight:700}
h1{font-size:31px;margin:.35em 0 0;letter-spacing:-.02em}
h2{font-size:18px;margin:42px 0 12px;padding-top:22px;border-top:1px solid var(--bd)}
h3{font-size:15px;margin:26px 0 8px;color:var(--ac)}
.lead{font-size:16px;color:var(--mu);max-width:70ch}
p{color:var(--mu)} strong{color:var(--fg)}
a{color:var(--ac)}
pre{background:var(--card);border:1px solid var(--bd);border-radius:10px;
    padding:14px 16px;overflow-x:auto;font:13px/1.6 ui-monospace,SFMono-Regular,Menlo,monospace;
    color:#cfe3ff;white-space:pre}
code{background:var(--card);border:1px solid var(--bd);border-radius:5px;
     padding:1px 6px;font:13px ui-monospace,Menlo,monospace;color:#cfe3ff}
table{width:100%;border-collapse:collapse;margin:14px 0;font-size:14px}
th,td{text-align:left;padding:11px 12px;border-bottom:1px solid var(--bd);vertical-align:top}
th{color:var(--mu);font-size:12px;text-transform:uppercase;letter-spacing:.07em}
td .m{font:700 11px ui-monospace,Menlo,monospace;color:var(--ok)}
tr.det td{background:#12151b;border-bottom:1px solid var(--bd);padding:0 12px 14px}
.lbl{font-size:11px;text-transform:uppercase;letter-spacing:.09em;color:var(--mu);
     margin:12px 0 6px;font-weight:700}
.ret{font-size:13.5px}
ul.codes{list-style:none;padding:0}
ul.codes li{padding:7px 0 7px 22px;position:relative;color:var(--mu)}
ul.codes li:before{content:"\203A";position:absolute;left:4px;color:var(--ac);font-weight:700}
ol.codes{padding-left:22px} ol.codes li{padding:5px 0;color:var(--mu)}
.btn{display:inline-block;background:var(--ac);color:#08101f;font-weight:700;
     padding:11px 20px;border-radius:9px;text-decoration:none}
footer{margin-top:60px;padding-top:20px;border-top:1px solid var(--bd);
       color:var(--mu);font-size:13px}
CSS;

        return '<!doctype html>'
            . '<html lang="pt-BR"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>' . esc($titulo) . '</title>'
            . '<style>' . $css . '</style>'
            . '</head><body><div class="wrap">'
            . '<header><div class="kicker">PDV · API e MCP</div>'
            . '<h1>' . esc($titulo) . '</h1></header>'
            . $conteudo
            . '<footer>Servido pela propria aplicacao do PDV. Nenhum servico externo envolvido.'
            . '<br>Rotas com JWT · credenciais = usuario do PDV.</footer>'
            . '</div></body></html>';
    }
}
