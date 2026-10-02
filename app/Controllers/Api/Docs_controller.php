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
                'metodo' => 'GET', 'rota' => '/api/itens/{id}',
                'grant' => 'items', 'desc' => 'Le um item pelo id.',
                'corpo' => '—',
                'curl' => "curl -s {$base}/api/itens/1 -H 'Authorization: Bearer <TOKEN>'",
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
                'curl' => "curl -s -X DELETE {$base}/api/itens/1 -H 'Authorization: Bearer <TOKEN>'",
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

        $html = $this->layout('API — PDV', <<<HTML
            <p class="lead">
              API JSON servida pela <strong>propria aplicacao do PDV</strong>. Nao ha servico
              separado: PDV de pe significa API de pe. As regras de negocio, validacoes e
              permissoes sao as mesmas da tela — a API reusa os models do OSPOS.
            </p>

            <h2>1. Pegue o token</h2>
            <p>Use o <strong>mesmo usuario e senha</strong> que voce usa no PDV.</p>
            <pre>curl -s -X POST {$base}/api/auth/token \\
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
              <li>Serie e mensagem de erro genericas no login: nao revelam usuario existente.</li>
              <li>Escrita apenas pelos models do PDV — sem SQL montado a mao.</li>
            </ul>
        HTML);

        return $this->response->setBody($html);
    }

    /** GET /mcp */
    public function getMcp(): ResponseInterface
    {
        $base = rtrim(base_url(), '/');
        $download = $base . '/mcp/servidor-mcp-pdv.js';

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
            <p>Settings → MCP → Add new MCP server → tipo <code>command</code>,
            comando <code>node /caminho/para/servidor-mcp-pdv.js</code>.</p>

            <h3>Hermes Agent</h3>
            <pre>mcp:
  servers:
    pdv:
      command: node
      args: ['/caminho/para/servidor-mcp-pdv.js']</pre>

            <h3>Qualquer outra IA</h3>
            <p>
              Dê o arquivo e diga: <em>"este é um servidor MCP via stdio; configure
              como servidor MCP e use a tool pdv_* que ele expõe"</em>.
              O próprio arquivo documenta as tools no topo.
            </p>

            <h2>Ferramentas expostas</h2>
            <ul class="codes">
              <li><code>pdv_obter_item</code> — le um item pelo id</li>
              <li><code>pdv_criar_item</code> — cria item</li>
              <li><code>pdv_alterar_item</code> — altera campos do item</li>
              <li><code>pdv_excluir_item</code> — exclui (soft delete)</li>
            </ul>

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
        return <<<HTML
        <!doctype html>
        <html lang="pt-BR">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>{$titulo}</title>
        <style>
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
        ul.codes li:before{content:"›";position:absolute;left:4px;color:var(--ac);font-weight:700}
        ol.codes{padding-left:22px} ol.codes li{padding:5px 0;color:var(--mu)}
        .btn{display:inline-block;background:var(--ac);color:#08101f;font-weight:700;
             padding:11px 20px;border-radius:9px;text-decoration:none}
        footer{margin-top:60px;padding-top:20px;border-top:1px solid var(--bd);
               color:var(--mu);font-size:13px}
        </style>
        </head>
        <body><div class="wrap">
        <header>
          <div class="kicker">PDV · API e MCP</div>
          <h1>{$titulo}</h1>
        </header>
        {$conteudo}
        <footer>
          Servido pela propria aplicacao do PDV. Nenhum servico externo envolvido.
          <br>Rotas com JWT · credenciais = usuario do PDV.
        </footer>
        </div></body></html>
        HTML;
    }
}
