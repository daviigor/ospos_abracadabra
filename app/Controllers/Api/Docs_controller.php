<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Paginas institucionais servidas pelo proprio PDV.
 *
 * /api             -> documentacao das rotas (abas por linguagem, sem lib nova)
 * /mcp             -> o que e o MCP, gerador do cliente e instrucoes por IA
 * /mcp/servidor.js -> baixa o servidor MCP ja com a URL base escolhida na pagina
 *
 * Nao usam sessao: sao publicas de proposito (documentacao). Nenhuma delas
 * expoe dado da loja nem permite acao.
 */
class Docs_controller extends Api_base_controller
{
    /** Exemplo de codigo da mesma chamada em cada linguagem. */
    private function exemplos(string $metodo, string $caminho, ?string $corpo = null): array
    {
        $url = '{{BASE}}' . $caminho;
        $m = strtoupper($metodo);

        $curl = "curl -s -X {$m} \"{$url}\" \\\n"
            . "  -H \"Authorization: Bearer \$PDV_TOKEN\""
            . ($corpo !== null ? " \\\n  -H \"Content-Type: application/json\" \\\n  -d '{$corpo}'" : '');

        $jsHead = "const r = await fetch(\"{$url}\", {\n"
            . "  method: \"{$m}\",\n"
            . "  headers: {\n"
            . "    \"Authorization\": `Bearer \${token}`,\n"
            . ($corpo !== null ? "    \"Content-Type\": \"application/json\",\n" : '')
            . "  },";
        $jsBody = $corpo !== null ? "\n  body: JSON.stringify({$corpo})," : '';
        $js = $jsHead . $jsBody . "\n});\nconst dados = await r.json();\nconsole.log(dados);";

        $py = "import os, requests\n\n"
            . "token = os.environ[\"PDV_TOKEN\"]\n"
            . "r = requests." . strtolower($m === 'DELETE' ? 'delete' : ($m === 'GET' ? 'get' : 'request')) . "(\n"
            . "    \"" . $url . "\",\n"
            . ($m === 'GET' || $m === 'DELETE' ? '' : "    method=\"{$m}\",\n")
            . "    headers={\"Authorization\": f\"Bearer {token}\"},\n"
            . ($corpo !== null ? "    json={$corpo},\n" : '')
            . ")\nprint(r.json())";

        $php = "\$ch = curl_init(\"{$url}\");\n"
            . "curl_setopt_array(\$ch, [\n"
            . "    CURLOPT_CUSTOMREQUEST  => \"{$m}\",\n"
            . "    CURLOPT_RETURNTRANSFER => true,\n"
            . "    CURLOPT_HTTPHEADER     => [\n"
            . "        \"Authorization: Bearer \" . getenv(\"PDV_TOKEN\"),\n"
            . ($corpo !== null ? "        \"Content-Type: application/json\",\n" : '')
            . "    ],\n";
        if ($corpo !== null) {
            $php .= "    CURLOPT_POSTFIELDS     => '" . $corpo . "',\n";
        }
        $php .= "]);\n\$resposta = json_decode(curl_exec(\$ch), true);\nprint_r(\$resposta);";

        return ['curl' => $curl, 'js' => $js, 'python' => $py, 'php' => $php];
    }

    /**
     * Catalogo das rotas, agrupado por entidade. Cada entidade vira um bloco.
     */
    private function grupos(): array
    {
        $r = static fn (string $metodo, string $rota, string $grant, string $desc, ?string $corpo)
            => ['metodo' => $metodo, 'rota' => $rota, 'grant' => $grant, 'desc' => $desc, 'corpo' => $corpo];

        return [
            [
                'nome' => 'Autenticacao', 'icone' => '1',
                'desc' => 'Troca usuario e senha do PDV por um token JWT. O token vai em todas as outras rotas.',
                'rotas' => [
                    $r('POST', '/api/auth/token', '—', 'Gera o token. Corpo com username e password do PDV.', '{"username": "seu_usuario", "password": "sua_senha"}'),
                ],
            ],
            [
                'nome' => 'Itens', 'icone' => '2',
                'desc' => 'Catalogo de produtos: preco de custo e venda, estoque, codigo de barras.',
                'rotas' => [
                    $r('GET', '/api/itens', 'items', 'Lista/busca paginado. Parametros: search, limit (1-100), offset.', null),
                    $r('GET', '/api/itens/{id}', 'items', 'Ficha de um item.', null),
                    $r('POST', '/api/itens', 'items', 'Cria item. 201 com o id.', '{"name": "Produto", "unit_price": "20.00", "quantity_1": "5"}'),
                    $r('PATCH', '/api/itens/{id}', 'items', 'Altera so os campos enviados.', '{"unit_price": "22.00"}'),
                    $r('DELETE', '/api/itens/{id}', 'items', 'Apaga o item (soft delete).', null),
                ],
            ],
            [
                'nome' => 'Atributos', 'icone' => '3',
                'desc' => 'Variacoes de produto (Voltagem, Cor, Tamanho...) e o valor de cada item.',
                'rotas' => [
                    $r('GET', '/api/atributos', 'attributes', 'Lista definicoes de atributo.', null),
                    $r('GET', '/api/atributos/{item_id}', 'attributes', 'Valores de atributo de um item. Parametro: definicao.', null),
                    $r('POST', '/api/atributos', 'attributes', 'Cria definicao. 201 com o id.', '{"nome": "Voltagem", "tipo": "DROPDOWN", "flags": [1]}'),
                    $r('PATCH', '/api/atributos/{id}', 'attributes', 'Altera definicao.', '{"nome": "Voltagem (V)"}'),
                    $r('DELETE', '/api/atributos/{id}', 'attributes', 'Apaga definicao.', null),
                    $r('POST', '/api/atributos/valores', 'attributes', 'Grava valor de atributo num item.', '{"item_id": 5, "definicao_id": 7, "valor": "220"}'),
                    $r('DELETE', '/api/atributos/valores', 'attributes', 'Remove valor de atributo de um item.', '{"item_id": 5, "definicao_id": 7, "valor": "220"}'),
                ],
            ],
            [
                'nome' => 'Clientes', 'icone' => '4',
                'desc' => 'Pessoa fisica ou juridica. PJ: nome = razao social, fantasia = nome fantasia, cnpj = CNPJ.',
                'rotas' => [
                    $r('GET', '/api/clientes', 'customers', 'Lista/busca. Parametros: search, limit, offset.', null),
                    $r('GET', '/api/clientes/{id}', 'customers', 'Ficha do cliente.', null),
                    $r('POST', '/api/clientes', 'customers', 'Cria cliente. 201 com o id.', '{"nome": "Ze", "sobrenome": "Ferragens", "cnpj": "12345678000199", "email": "ze@ex.com"}'),
                    $r('PATCH', '/api/clientes/{id}', 'customers', 'Altera so os campos enviados.', '{"telefone": "17988887777"}'),
                    $r('DELETE', '/api/clientes/{id}', 'customers', 'Apaga o cliente (soft delete).', null),
                ],
            ],
            [
                'nome' => 'Fornecedores', 'icone' => '5',
                'desc' => 'Quem vende para a loja. Aparece tambem no recebimento de mercadoria.',
                'rotas' => [
                    $r('GET', '/api/fornecedores', 'suppliers', 'Lista/busca. Parametros: search, limit, offset, categoria.', null),
                    $r('GET', '/api/fornecedores/{id}', 'suppliers', 'Ficha do fornecedor.', null),
                    $r('GET', '/api/fornecedores/categorias', 'suppliers', 'Categorias de fornecedor disponiveis.', null),
                    $r('POST', '/api/fornecedores', 'suppliers', 'Cria fornecedor. 201 com o id.', '{"nome": "ACME Ltda", "cnpj": "98765432000155", "fantasia": "ACME"}'),
                    $r('PATCH', '/api/fornecedores/{id}', 'suppliers', 'Altera so os campos enviados.', '{"telefone": "1733332222"}'),
                    $r('DELETE', '/api/fornecedores/{id}', 'suppliers', 'Apaga o fornecedor.', null),
                ],
            ],
            [
                'nome' => 'Vendas', 'icone' => '6',
                'desc' => 'Uma rota grava os quatro tipos: venda (PDV), cotacao, fatura (fiado) e devolucao. Na devolucao informe devolucao_de com a venda original ("POS 123" ou so o numero).',
                'rotas' => [
                    $r('GET', '/api/vendas', 'sales', 'Lista/busca vendas gravadas.', null),
                    $r('GET', '/api/vendas/{id}', 'sales', 'Cabecalho, itens e pagamentos de uma venda.', null),
                    $r('POST', '/api/vendas', 'sales', 'Grava venda/cotacao/fatura/devolucao. tipo = venda|cotacao|fatura|devolucao.', '{"tipo": "venda", "cliente_id": 12, "itens": [{"item_id": 5, "quantidade": "2", "preco": "19.90"}], "pagamentos": [{"tipo": "Dinheiro", "valor": "39.80"}]}'),
                    $r('DELETE', '/api/vendas/{id}', 'sales_delete', 'Cancela a venda e devolve o estoque.', null),
                ],
            ],
            [
                'nome' => 'Recebimentos', 'icone' => '7',
                'desc' => 'Entrada de mercadoria. tipo = recebimento (compra), requisicao (transferencia entre locais) ou devolucao (devolucao ao fornecedor).',
                'rotas' => [
                    $r('GET', '/api/recebimentos', 'receivings', 'Lista/busca recebimentos.', null),
                    $r('GET', '/api/recebimentos/{id}', 'receivings', 'Itens e custos de um recebimento.', null),
                    $r('GET', '/api/recebimentos/opcoes', 'receivings', 'Locais, fornecedores e formas de pagamento.', null),
                    $r('POST', '/api/recebimentos', 'receivings', 'Grava recebimento/requisicao/devolucao.', '{"tipo": "recebimento", "fornecedor_id": 4, "local_id": 1, "itens": [{"item_id": 5, "quantidade": "10", "custo": "12.50"}]}'),
                    $r('DELETE', '/api/recebimentos/{id}', 'receivings', 'Cancela e estorna o estoque.', null),
                ],
            ],
            [
                'nome' => 'Despesas', 'icone' => '8',
                'desc' => 'Contas da loja (energia, internet, frete) e as categorias delas.',
                'rotas' => [
                    $r('GET', '/api/despesas', 'expenses', 'Lista/busca. Parametros: data_inicio, data_fim, categoria, pagamento.', null),
                    $r('GET', '/api/despesas/{id}', 'expenses', 'Ficha da despesa.', null),
                    $r('POST', '/api/despesas', 'expenses', 'Lanca despesa. 201 com o id.', '{"valor": "250.00", "categoria_id": 3, "descricao": "Energia"}'),
                    $r('PATCH', '/api/despesas/{id}', 'expenses', 'Altera so os campos enviados.', '{"valor": "260.00"}'),
                    $r('DELETE', '/api/despesas/{id}', 'expenses', 'Apaga a despesa.', null),
                    $r('GET', '/api/despesas/categorias', 'expenses_categories', 'Lista categorias.', null),
                    $r('POST', '/api/despesas/categorias', 'expenses_categories', 'Cria categoria.', '{"nome": "Fretes"}'),
                    $r('PATCH', '/api/despesas/categorias/{id}', 'expenses_categories', 'Altera categoria.', '{"nome": "Frete e entrega"}'),
                    $r('DELETE', '/api/despesas/categorias/{id}', 'expenses_categories', 'Apaga categoria.', null),
                ],
            ],
            [
                'nome' => 'Cartoes presente', 'icone' => '9',
                'desc' => 'Gift card com saldo prepago. O PDV debita sozinho quando usado como forma de pagamento.',
                'rotas' => [
                    $r('GET', '/api/giftcards', 'giftcards', 'Lista/busca cartoes.', null),
                    $r('GET', '/api/giftcards/{id}', 'giftcards', 'Ficha do cartao.', null),
                    $r('GET', '/api/giftcards/saldo/{numero}', 'giftcards', 'Saldo atual de um cartao pelo numero.', null),
                    $r('GET', '/api/giftcards/proximo-numero', 'giftcards', 'Sugere o proximo numero livre.', null),
                    $r('POST', '/api/giftcards', 'giftcards', 'Cria cartao com valor inicial. 201 com o id.', '{"valor": "100.00", "cliente_id": 12}'),
                    $r('POST', '/api/giftcards/{numero}/recarregar', 'giftcards', 'Soma valor ao saldo.', '{"valor": "50.00"}'),
                    $r('PATCH', '/api/giftcards/{id}', 'giftcards', 'Altera dono ou valor do cartao.', '{"cliente_id": 13}'),
                    $r('DELETE', '/api/giftcards/{id}', 'giftcards', 'Apaga o cartao (soft delete).', null),
                ],
            ],
        ];
    }

    /** GET /api */
    public function getIndex(): ResponseInterface
    {
        $base = rtrim(base_url(), '/');
        $grupos = $this->grupos();

        $nav = '';
        foreach ($grupos as $g) {
            $nav .= '<a class="chip" href="#g' . esc($g['icone']) . '">' . esc($g['nome']) . '</a>';
        }

        $secoes = '';
        foreach ($grupos as $g) {
            $secoes .= '<section class="grupo" id="g' . esc($g['icone']) . '">'
                . '<div class="grupo-topo"><span class="num">' . esc($g['icone']) . '</span>'
                . '<div><h3>' . esc($g['nome']) . '</h3>'
                . '<p class="grupo-desc">' . esc($g['desc']) . '</p></div></div>';

            foreach ($g['rotas'] as $rota) {
                $ex = $this->exemplos($rota['metodo'], $rota['rota'], $rota['corpo']);
                $met = strtolower($rota['metodo']);

                $secoes .= '<div class="rota"><div class="rota-topo">'
                    . '<span class="m ' . esc($met) . '">' . esc($rota['metodo']) . '</span>'
                    . '<code class="rota-url">' . esc($rota['rota']) . '</code>'
                    . '<span class="grant" title="permissao exigida">' . esc($rota['grant']) . '</span>'
                    . '</div><p class="rota-desc">' . esc($rota['desc']) . '</p>';

                if ($rota['corpo'] !== null) {
                    $secoes .= '<div class="lbl">corpo (JSON)</div><pre class="json">' . esc($rota['corpo']) . '</pre>';
                }

                $secoes .= '<div class="tabs"><div class="tabbar">'
                    . '<button type="button" class="tab ativo" data-lang="curl">cURL</button>'
                    . '<button type="button" class="tab" data-lang="js">JavaScript</button>'
                    . '<button type="button" class="tab" data-lang="python">Python</button>'
                    . '<button type="button" class="tab" data-lang="php">PHP</button>'
                    . '</div>'
                    . '<pre class="codigo ativo" data-lang="curl">' . esc($ex['curl']) . '</pre>'
                    . '<pre class="codigo" data-lang="js">' . esc($ex['js']) . '</pre>'
                    . '<pre class="codigo" data-lang="python">' . esc($ex['python']) . '</pre>'
                    . '<pre class="codigo" data-lang="php">' . esc($ex['php']) . '</pre>'
                    . '<button type="button" class="copiar" data-copiar>copiar</button>'
                    . '</div></div>';
            }

            $secoes .= '</section>';
        }

        $conteudo = <<<HTML
<div class="hero">
  <h2>API JSON do PDV</h2>
  <p class="lead">Todas as rotas do PDV em JSON, com as <strong>mesmas regras e permissoes da tela</strong>.
  Serve para integrar outra ferramenta — ou uma IA — sem abrir o sistema na mao.
  O hospedeiro e o proprio OSPOS: nao ha servico separado.</p>
  <div class="passos">
    <div class="passo"><span>1</span><div>Pegue o token em <code>POST /api/auth/token</code> com usuario e senha do PDV.</div></div>
    <div class="passo"><span>2</span><div>Mande <code>Authorization: Bearer &lt;TOKEN&gt;</code> nas demais rotas.</div></div>
    <div class="passo"><span>3</span><div>Cada rota exige a mesma permissao de modulo (<em>grant</em>) que a tela.</div></div>
  </div>
</div>

<div class="campo-base">
  <label for="base">Endereco deste PDV (usado nos exemplos)</label>
  <div class="linha">
    <input id="base" type="text" value="{$base}" spellcheck="false" autocomplete="off">
    <button type="button" id="aplicar">aplicar</button>
  </div>
  <p class="hint">Os exemplos abaixo trocam <code>{{BASE}}</code> por este endereco.</p>
</div>

<nav class="chips">{$nav}</nav>

{$secoes}

<section class="grupo">
  <h3>Codigos de retorno</h3>
  <ul class="codes">
    <li><b>200</b> ok &nbsp;·&nbsp; <b>201</b> criado &nbsp;·&nbsp; <b>400</b> dado invalido</li>
    <li><b>401</b> token ausente, invalido ou expirado</li>
    <li><b>403</b> sem permissao no modulo &nbsp;·&nbsp; <b>404</b> nao encontrado</li>
    <li><b>409</b> codigo (item_number) ja existe</li>
  </ul>
</section>

<section class="grupo">
  <h3>Seguranca</h3>
  <ul class="codes">
    <li>JWT HS256, assinado com <code>JWT_SECRET</code> do .env.</li>
    <li>A senha conferida e a mesma do PDV (bcrypt em <code>ospos_employees</code>).</li>
    <li>O token nao concede nada por si: valem os <em>grants</em> do funcionario.</li>
    <li>Producao exige <code>PDV_ENV=prod</code> — nao ha escrita acidental.</li>
  </ul>
</section>
HTML;

        return $this->layout('API PDV', $conteudo);
    }

    /** GET /mcp */
    public function getMcp(): ResponseInterface
    {
        $base = rtrim(base_url(), '/');

        $tofus = [
            'Itens'            => ['pdv_buscar_itens', 'pdv_obter_item', 'pdv_criar_item', 'pdv_alterar_item', 'pdv_excluir_item'],
            'Atributos'        => ['pdv_buscar_atributos', 'pdv_obter_atributos_item', 'pdv_gravar_atributo_item'],
            'Clientes'         => ['pdv_buscar_clientes', 'pdv_obter_cliente', 'pdv_criar_cliente', 'pdv_alterar_cliente'],
            'Fornecedores'     => ['pdv_buscar_fornecedores', 'pdv_criar_fornecedor'],
            'Vendas'           => ['pdv_buscar_vendas', 'pdv_obter_venda', 'pdv_gravar_venda'],
            'Recebimentos'     => ['pdv_buscar_recebimentos', 'pdv_obter_recebimento', 'pdv_gravar_recebimento'],
            'Despesas'         => ['pdv_buscar_despesas', 'pdv_lancar_despesa', 'pdv_buscar_categorias_despesa'],
            'Cartoes presente' => ['pdv_buscar_giftcards', 'pdv_saldo_giftcard', 'pdv_criar_giftcard', 'pdv_recarregar_giftcard'],
        ];

        $blocos = '';
        foreach ($tofus as $nome => $tools) {
            $blocos .= '<div class="tofu"><b>' . esc($nome) . '</b><ul>';
            foreach ($tools as $t) {
                $blocos .= '<li><code>' . esc($t) . '</code></li>';
            }
            $blocos .= '</ul></div>';
        }
        $total = array_sum(array_map('count', $tofus));

        $conteudo = <<<HTML
<div class="hero">
  <h2>MCP do PDV</h2>
  <p class="lead">O <strong>MCP</strong> e a ponte entre uma IA (Claude, Cursor, Hermes...) e o seu PDV.
  A IA nao fala com o banco: ela chama a API JSON do PDV, que aplica as regras e permissoes.
  Voce baixa o arquivo, aponta para o seu endereco e pronto.</p>
</div>

<div class="campo-base destaque">
  <label for="base-mcp">Endereco base do seu PDV (onde o MCP vai bater)</label>
  <div class="linha">
    <input id="base-mcp" type="text" value="{$base}" spellcheck="false" autocomplete="off" placeholder="https://seu-pdv.com">
    <a id="baixar" class="btn" href="{$base}/mcp/servidor.js" download="servidor-mcp-pdv.js">baixar servidor-mcp-pdv.js</a>
  </div>
  <p class="hint">O arquivo baixado ja sai com este endereco como padrao.
  So o <strong>token</strong> voce informa depois, por <code>PDV_TOKEN=...</code> ou <code>--token ...</code>.</p>
</div>

<section class="grupo">
  <h3>Como usar (3 passos)</h3>
  <div class="passos">
    <div class="passo"><span>1</span><div>Baixe o <code>servidor-mcp-pdv.js</code> acima, com o endereco ja embutido.</div></div>
    <div class="passo"><span>2</span><div>Gere um token em <a href="{$base}/api">{$base}/api</a> (usuario e senha do PDV).</div></div>
    <div class="passo"><span>3</span><div>Aponte a IA para o arquivo, passando o token. Nao instala nada (Node 18+).</div></div>
  </div>
</section>

<section class="grupo">
  <h3>Configurar na sua IA</h3>

  <h4>Claude Desktop</h4>
  <pre>{
  "mcpServers": {
    "pdv": {
      "command": "node",
      "args": ["/caminho/para/servidor-mcp-pdv.js"],
      "env": { "PDV_TOKEN": "seu_token_jwt" }
    }
  }
}</pre>

  <h4>Cursor</h4>
  <p>Em <em>Settings &gt; MCP</em>, aponte o <code>command</code> para
  <code>node /caminho/para/servidor-mcp-pdv.js</code> e defina <code>PDV_TOKEN</code> no ambiente.</p>

  <h4>Hermes Agent</h4>
  <pre>mcp:
  servidores:
    pdv:
      command: node
      args: ["/caminho/para/servidor-mcp-pdv.js"]
      env:
        PDV_TOKEN: "seu_token_jwt"</pre>

  <h4>Qualquer outra IA</h4>
  <p>Rode <code>PDV_URL=... PDV_TOKEN=... node servidor-mcp-pdv.js</code> e fale MCP por stdio.
  Ou por argumento: <code>node servidor-mcp-pdv.js --url ... --token ...</code>.</p>
</section>

<section class="grupo">
  <h3>{$total} ferramentas expostas</h3>
  <p class="grupo-desc">Agrupadas por area do PDV, iguais as rotas de <a href="{$base}/api">{$base}/api</a>.</p>
  <div class="tofus">{$blocos}</div>
</section>
HTML;

        return $this->layout('MCP PDV', $conteudo);
    }

    /**
     * GET /mcp/servidor.js — o mesmo arquivo de public/mcp-download/, mas com a
     * URL base escolhida na pagina injetada no lugar do placeholder.
     */
    public function getServidor(): ResponseInterface
    {
        $url = rtrim(trim((string) ($this->request->getGet('base') ?? '')), '/');

        // so http(s) com host simples — evita embutir qualquer outra coisa
        if ($url === '' || !preg_match('#^https?://[A-Za-z0-9\.\-]+(:\d+)?$#', $url)) {
            $url = rtrim(base_url(), '/');
        }

        $arquivo = ROOTPATH . 'public/mcp-download/servidor-mcp-pdv.js';
        $js = is_file($arquivo) ? (string) file_get_contents($arquivo) : '';

        $js = str_replace('%%PDV_URL%%', $url, $js);
        // token nunca vem preenchido: e segredo de quem baixa
        $js = str_replace('%%PDV_TOKEN%%', '', $js);

        return $this->response
            ->setHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="servidor-mcp-pdv.js"')
            ->setBody($js);
    }

    /** Layout unico das duas paginas: responsivo, sem CDN. */
    private function layout(string $titulo, string $conteudo): ResponseInterface
    {
        $css = <<<'CSS'
:root{--bg:#0f1115;--card:#171a21;--card2:#12151b;--bd:#262b36;--fg:#e6e9ef;--mu:#9aa4b2;
--ac:#5b9dff;--ok:#3fbf7f;--wa:#e0a34a;--del:#e06c6c}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--fg);
 font:15px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.wrap{max-width:960px;margin:0 auto;padding:28px 20px 80px}
header{border-bottom:1px solid var(--bd);padding-bottom:22px;margin-bottom:30px}
.kicker{font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--ac);font-weight:700}
h1{font-size:30px;margin:.35em 0 0;letter-spacing:-.02em}
h2{font-size:22px;margin:0 0 10px}
h3{font-size:17px;margin:0}
h4{font-size:15px;margin:26px 0 8px;color:var(--ac)}
.lead{font-size:16px;color:var(--mu);max-width:72ch}
p{color:var(--mu)} strong{color:var(--fg)}
a{color:var(--ac)}
code{background:var(--card);border:1px solid var(--bd);border-radius:5px;
 padding:1px 6px;font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;color:#cfe3ff}
pre{background:var(--card);border:1px solid var(--bd);border-radius:10px;padding:14px 16px;
 overflow-x:auto;font:13px/1.6 ui-monospace,SFMono-Regular,Menlo,monospace;color:#cfe3ff;
 white-space:pre;-webkit-overflow-scrolling:touch}
.passos{display:flex;flex-direction:column;gap:10px;margin:18px 0}
.passo{display:flex;gap:10px;align-items:flex-start;background:var(--card);
 border:1px solid var(--bd);border-radius:10px;padding:12px 14px;color:var(--mu);font-size:14px}
.passo span{flex:0 0 22px;height:22px;border-radius:50%;background:var(--ac);color:#08101f;
 font-weight:700;font-size:12px;display:grid;place-items:center}
.campo-base{background:var(--card);border:1px solid var(--bd);border-radius:12px;
 padding:16px;margin:26px 0}
.campo-base.destaque{border-color:var(--ac)}
.campo-base label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.08em;
 color:var(--mu);font-weight:700;margin-bottom:8px}
.campo-base .linha{display:flex;gap:8px;flex-wrap:wrap}
.campo-base input{flex:1 1 260px;min-width:0;background:var(--card2);border:1px solid var(--bd);
 border-radius:9px;padding:11px 12px;color:var(--fg);font-size:15px}
.campo-base input:focus{outline:none;border-color:var(--ac)}
.campo-base button,.btn{background:var(--ac);color:#08101f;font-weight:700;border:0;
 border-radius:9px;padding:11px 18px;cursor:pointer;font-size:15px;text-decoration:none;
 white-space:nowrap;text-align:center}
.hint{font-size:13px;color:var(--mu);margin:10px 0 0}
.chips{display:flex;flex-wrap:wrap;gap:8px;margin:26px 0 8px}
.chip{background:var(--card);border:1px solid var(--bd);border-radius:99px;padding:6px 14px;
 font-size:13px;text-decoration:none;color:var(--mu)}
.chip:hover{border-color:var(--ac);color:var(--fg)}
.grupo{margin:34px 0;border-top:1px solid var(--bd);padding-top:22px}
.grupo-topo{display:flex;gap:12px;align-items:flex-start;margin-bottom:14px}
.grupo-topo .num{flex:0 0 28px;height:28px;border-radius:8px;background:var(--card);
 border:1px solid var(--bd);color:var(--ac);font-weight:700;font-size:13px;
 display:grid;place-items:center;margin-top:2px}
.grupo-desc{font-size:14px;color:var(--mu);margin:6px 0 0;max-width:76ch}
.rota{background:var(--card);border:1px solid var(--bd);border-radius:12px;
 padding:14px;margin-bottom:14px}
.rota-topo{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.m{font:700 11px/1 ui-monospace,Menlo,monospace;padding:6px 9px;border-radius:6px;
 letter-spacing:.05em;color:#08101f;background:var(--ok)}
.m.post{background:var(--ac)} .m.patch{background:var(--wa)}
.m.delete{background:var(--del)} .m.get{background:var(--ok)}
.rota-url{background:transparent;border:0;padding:0;font-size:14px;word-break:break-all;flex:1 1 200px}
.grant{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--mu);
 border:1px solid var(--bd);border-radius:6px;padding:3px 8px}
.rota-desc{font-size:14px;margin:10px 0}
.lbl{font-size:11px;text-transform:uppercase;letter-spacing:.09em;color:var(--mu);
 font-weight:700;margin:14px 0 6px}
pre.json{color:#e8d9a8;margin:0}
.tabs{position:relative;margin-top:14px}
.tabbar{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid var(--bd);margin-bottom:-1px;
 position:relative;z-index:1}
.tab{background:transparent;border:1px solid transparent;border-bottom:0;color:var(--mu);
 padding:7px 13px;font-size:13px;cursor:pointer;border-radius:8px 8px 0 0}
.tab.ativo{background:var(--card2);border-color:var(--bd);color:var(--fg);font-weight:600}
.codigo{display:none;margin:0;border-radius:0 10px 10px 10px}
.codigo.ativo{display:block}
.copiar{position:absolute;top:8px;right:8px;background:var(--card2);color:var(--mu);
 border:1px solid var(--bd);border-radius:7px;padding:4px 10px;font-size:12px;cursor:pointer}
.copiar:hover{color:var(--fg);border-color:var(--ac)}
.tofus{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px;margin-top:14px}
.tofu{background:var(--card);border:1px solid var(--bd);border-radius:10px;padding:12px}
.tofu b{font-size:13px;color:var(--fg)}
.tofu ul{list-style:none;margin:8px 0 0;padding:0}
.tofu li{font-size:12.5px;margin-bottom:4px}
ul.codes{list-style:none;padding:0}
ul.codes li{padding:3px 0 3px 22px;position:relative;color:var(--mu);font-size:14px}
ul.codes li:before{content:"\203A";position:absolute;left:4px;color:var(--ac);font-weight:700}
footer{margin-top:60px;padding-top:20px;border-top:1px solid var(--bd);
 color:var(--mu);font-size:13px}
@media (max-width:640px){
 .wrap{padding:20px 14px 60px}
 h1{font-size:24px} h2{font-size:19px}
 pre{font-size:12px;padding:12px}
 .campo-base .linha{flex-direction:column}
 .campo-base input,.campo-base button,.btn{width:100%}
 .rota{padding:12px}
 .tofus{grid-template-columns:1fr}
 .tab{padding:7px 10px;font-size:12.5px}
 .chips{gap:6px}
 .chip{padding:5px 11px;font-size:12px}
}
CSS;

        $js = <<<'JS'
function pdvBase(){
    const campo = document.getElementById('base');
    if (!campo) return;
    const valor = campo.value.replace(/\/+$/, '');
    document.querySelectorAll('.codigo').forEach(function (bloco) {
        if (bloco.dataset.original === undefined) bloco.dataset.original = bloco.textContent;
        bloco.textContent = bloco.dataset.original.split('{{BASE}}').join(valor);
    });
}
function pdvBaixar(){
    const campo = document.getElementById('base-mcp');
    const link = document.getElementById('baixar');
    if (!campo || !link) return;
    const valor = campo.value.replace(/\/+$/, '');
    link.href = valor + '/mcp/servidor.js?base=' + encodeURIComponent(valor);
}
document.addEventListener('click', function (e) {
    const tab = e.target.closest('.tab');
    if (tab) {
        const caixa = tab.closest('.tabs');
        caixa.querySelectorAll('.tab').forEach(function (t) { t.classList.remove('ativo'); });
        caixa.querySelectorAll('.codigo').forEach(function (c) { c.classList.remove('ativo'); });
        tab.classList.add('ativo');
        const alvo = caixa.querySelector('.codigo[data-lang="' + tab.dataset.lang + '"]');
        if (alvo) alvo.classList.add('ativo');
        return;
    }
    if (e.target.closest('#aplicar')) { pdvBase(); return; }
    const copiar = e.target.closest('[data-copiar]');
    if (copiar) {
        const visivel = copiar.closest('.tabs').querySelector('.codigo.ativo');
        if (visivel && navigator.clipboard) {
            navigator.clipboard.writeText(visivel.textContent);
            copiar.textContent = 'copiado';
            setTimeout(function () { copiar.textContent = 'copiar'; }, 1500);
        }
    }
});
document.addEventListener('input', function (e) {
    if (e.target.id === 'base') pdvBase();
    if (e.target.id === 'base-mcp') pdvBaixar();
});
pdvBase();
JS;

        $t = esc($titulo);

        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $t . '</title><style>' . $css . '</style></head><body><div class="wrap">'
            . '<header><div class="kicker">PDV · API e MCP</div><h1>' . $t . '</h1></header>'
            . $conteudo
            . '<footer>Servido pelo proprio PDV. Nenhum servico externo envolvido.'
            . '<br>Rotas com JWT · credenciais = usuario do PDV.</footer>'
            . '</div><script>' . $js . '</script></body></html>';

        return $this->response->setHeader('Content-Type', 'text/html; charset=utf-8')->setBody($html);
    }
}
