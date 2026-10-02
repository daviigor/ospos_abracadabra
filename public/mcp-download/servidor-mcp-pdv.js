#!/usr/bin/env node
/**
 * servidor-mcp-pdv.js — MCP do PDV (stdio).
 *
 * Conecta uma IA ao SEU PDV. A IA nao fala com banco nenhum: ela chama a API
 * JSON do proprio PDV (/api), que aplica as regras de negocio e permissoes
 * do sistema. Se o PDV esta de pe, isto funciona.
 *
 * ── ANTES DE RODAR ────────────────────────────────────────────────────
 * Edite as duas constantes abaixo. O token se obtem em <PDV_URL>/api
 * (POST /api/auth/token com usuario e senha do PDV).
 *
 * Requisitos: Node 18+. Nao instala nada.
 * ──────────────────────────────────────────────────────────────────────
 */

const PDV_URL = 'http://localhost';        // <<< endereco do seu PDV
const PDV_TOKEN = 'COLE_SEU_TOKEN_JWT';    // <<< token gerado em /api

const { createInterface } = require('readline');

const TOOLS = [
    {
        name: 'pdv_buscar_itens',
        description: 'Lista/busca itens do PDV (paginado). Use antes de alterar, para descobrir o id.',
        inputSchema: {
            type: 'object',
            properties: {
                search: { type: 'string', description: 'texto a procurar em nome, codigo ou categoria' },
                limit: { type: 'integer', description: 'quantos trazer (1-100, padrao 20)' },
                offset: { type: 'integer', description: 'a partir de qual (padrao 0)' },
            },
        },
    },
    {
        name: 'pdv_obter_item',
        description: 'Le um item do PDV pelo id. Retorna nome, precos, estoque.',
        inputSchema: {
            type: 'object',
            properties: { id: { type: 'integer', description: 'item_id' } },
            required: ['id'],
        },
    },
    {
        name: 'pdv_criar_item',
        description: 'Cria um item no PDV. Exige ao menos o nome. Usa as validacoes do PDV.',
        inputSchema: {
            type: 'object',
            properties: {
                name: { type: 'string', description: 'nome do item (obrigatorio)' },
                description: { type: 'string' },
                item_number: { type: 'string', description: 'codigo/sku; nao pode repetir' },
                category: { type: 'string' },
                cost_price: { type: 'string', description: 'preco de custo, ex "10.00"' },
                unit_price: { type: 'string', description: 'preco de venda, ex "20.00"' },
                quantity_1: { type: 'string', description: 'estoque inicial na localidade 1' },
                supplier_id: { type: 'integer' },
            },
            required: ['name'],
        },
    },
    {
        name: 'pdv_alterar_item',
        description: 'Altera campos de um item existente. So envia o que mudar.',
        inputSchema: {
            type: 'object',
            properties: {
                id: { type: 'integer' },
                name: { type: 'string' },
                description: { type: 'string' },
                item_number: { type: 'string' },
                category: { type: 'string' },
                cost_price: { type: 'string' },
                unit_price: { type: 'string' },
                quantity_1: { type: 'string' },
                supplier_id: { type: 'integer' },
            },
            required: ['id'],
        },
    },
    {
        name: 'pdv_excluir_item',
        description: 'Exclui um item do PDV (soft delete, como na tela).',
        inputSchema: {
            type: 'object',
            properties: { id: { type: 'integer' } },
            required: ['id'],
        },
    },
    {
        name: 'pdv_buscar_clientes',
        description: 'Lista/busca clientes do PDV. Use antes de vender para achar o cliente_id.',
        inputSchema: {
            type: 'object',
            properties: {
                search: { type: 'string', description: 'texto em nome, razao social, cnpj/cpf, email, telefone' },
                limit: { type: 'integer', description: 'quantos trazer (1-100, padrao 20)' },
                offset: { type: 'integer', description: 'a partir de qual (padrao 0)' },
            },
        },
    },
    {
        name: 'pdv_obter_cliente',
        description: 'Ficha de um cliente pelo id, com estatisticas de compra.',
        inputSchema: {
            type: 'object',
            properties: { id: { type: 'integer', description: 'person_id do cliente' } },
            required: ['id'],
        },
    },
    {
        name: 'pdv_criar_cliente',
        description: 'Cria cliente no PDV. Para pessoa juridica: nome = razao social, fantasia = nome fantasia, cnpj = CNPJ.',
        inputSchema: {
            type: 'object',
            properties: {
                nome: { type: 'string', description: 'nome, ou razao social se for empresa (obrigatorio)' },
                sobrenome: { type: 'string' },
                fantasia: { type: 'string', description: 'nome fantasia, so PJ' },
                cnpj: { type: 'string', description: 'CNPJ ou CPF so numeros' },
                email: { type: 'string' },
                telefone: { type: 'string' },
                endereco: { type: 'string' },
            },
            required: ['nome'],
        },
    },
    {
        name: 'pdv_alterar_cliente',
        description: 'Altera campos de um cliente existente. So envia o que mudar.',
        inputSchema: {
            type: 'object',
            properties: {
                id: { type: 'integer' },
                nome: { type: 'string' },
                sobrenome: { type: 'string' },
                fantasia: { type: 'string' },
                cnpj: { type: 'string' },
                email: { type: 'string' },
                telefone: { type: 'string' },
            },
            required: ['id'],
        },
    },
    {
        name: 'pdv_buscar_fornecedores',
        description: 'Lista/busca fornecedores do PDV. Use antes de receber compra para achar o fornecedor_id.',
        inputSchema: {
            type: 'object',
            properties: {
                search: { type: 'string', description: 'texto em nome, razao social, cnpj, email' },
                limit: { type: 'integer', description: 'quantos trazer (1-100, padrao 20)' },
                offset: { type: 'integer', description: 'a partir de qual (padrao 0)' },
            },
        },
    },
    {
        name: 'pdv_criar_fornecedor',
        description: 'Cria fornecedor no PDV. nome = razao social; fantasia = nome fantasia.',
        inputSchema: {
            type: 'object',
            properties: {
                nome: { type: 'string', description: 'razao social (obrigatorio)' },
                fantasia: { type: 'string' },
                cnpj: { type: 'string' },
                email: { type: 'string' },
                telefone: { type: 'string' },
                categoria: { type: 'string', description: 'tipo de fornecedor, ex Mercadoria' },
            },
            required: ['nome'],
        },
    },
    {
        name: 'pdv_buscar_atributos',
        description: 'Lista as definicoes de atributo do PDV (ex Voltagem, Cor, Tamanho), com id e tipo.',
        inputSchema: { type: 'object', properties: {} },
    },
    {
        name: 'pdv_obter_atributos_item',
        description: 'Le os valores de atributo ja gravados num item. Use item_id do PDV e, opcionalmente, uma definicao.',
        inputSchema: {
            type: 'object',
            properties: {
                item_id: { type: 'integer' },
                definicao: { type: 'integer', description: 'definicao_id, opcional' },
            },
            required: ['item_id'],
        },
    },
    {
        name: 'pdv_gravar_atributo_item',
        description: 'Grava o valor de um atributo num item (ex Voltagem=220 num produto).',
        inputSchema: {
            type: 'object',
            properties: {
                item_id: { type: 'integer' },
                definicao_id: { type: 'integer' },
                valor: { type: 'string' },
            },
            required: ['item_id', 'definicao_id', 'valor'],
        },
    },
    {
        name: 'pdv_buscar_vendas',
        description: 'Lista/busca vendas ja gravadas no PDV, com data, cliente, total e tipo.',
        inputSchema: {
            type: 'object',
            properties: {
                search: { type: 'string', description: 'texto em numero da venda, cliente ou comentario' },
                limit: { type: 'integer', description: 'quantos trazer (1-100, padrao 20)' },
                offset: { type: 'integer', description: 'a partir de qual (padrao 0)' },
            },
        },
    },
    {
        name: 'pdv_obter_venda',
        description: 'Le uma venda pelo id: itens, quantidades, precos e pagamentos.',
        inputSchema: {
            type: 'object',
            properties: { id: { type: 'integer' } },
            required: ['id'],
        },
    },
    {
        name: 'pdv_gravar_venda',
        description: 'Grava venda, cotacao, fatura (fiado) ou devolucao no PDV. tipo = venda|cotacao|fatura|devolucao. ' +
            'Precos em "19.90"; na devolucao mande devolucao_de com o numero da venda original (ex "POS 123").',
        inputSchema: {
            type: 'object',
            properties: {
                tipo: { type: 'string', description: 'venda | cotacao | fatura | devolucao (padrao venda)' },
                cliente_id: { type: 'integer' },
                local_id: { type: 'integer', description: 'localidade de estoque (padrao 1)' },
                comentario: { type: 'string' },
                devolucao_de: { type: 'string', description: 'numero da venda original, so na devolucao' },
                itens: {
                    type: 'array',
                    description: 'itens da venda',
                    items: {
                        type: 'object',
                        properties: {
                            item_id: { type: 'integer' },
                            quantidade: { type: 'string', description: 'ex "2" ou "1.5"' },
                            preco: { type: 'string', description: 'ex "19.90"' },
                            desconto: { type: 'string', description: 'ex "0" ou "10"' },
                            desconto_tipo: { type: 'string', description: 'percentual | valor' },
                        },
                        required: ['item_id', 'quantidade'],
                    },
                },
                pagamentos: {
                    type: 'array',
                    description: 'formas de pagamento; a soma deve fechar o total',
                    items: {
                        type: 'object',
                        properties: {
                            tipo: { type: 'string', description: 'ex Dinheiro, Cartao, Gift Card' },
                            valor: { type: 'string', description: 'ex "39.80"' },
                        },
                        required: ['tipo', 'valor'],
                    },
                },
            },
            required: ['itens'],
        },
    },
    {
        name: 'pdv_buscar_recebimentos',
        description: 'Lista recebimentos de compra ja gravados no PDV (entrada de mercadoria).',
        inputSchema: {
            type: 'object',
            properties: {
                search: { type: 'string' },
                limit: { type: 'integer', description: 'quantos trazer (1-100, padrao 20)' },
                offset: { type: 'integer', description: 'a partir de qual (padrao 0)' },
            },
        },
    },
    {
        name: 'pdv_obter_recebimento',
        description: 'Le um recebimento pelo id: fornecedor, itens, custos e quantidades.',
        inputSchema: {
            type: 'object',
            properties: { id: { type: 'integer' } },
            required: ['id'],
        },
    },
    {
        name: 'pdv_gravar_recebimento',
        description: 'Grava entrada de mercadoria no PDV. tipo = recebimento (compra de fornecedor) | ' +
            'requisicao (transferencia entre locais, use origem_id e destino_id) | devolucao (devolucao ao fornecedor). ' +
            'Custo em "12.50". Isso atualiza o estoque e o custo medio, igual a tela.',
        inputSchema: {
            type: 'object',
            properties: {
                tipo: { type: 'string', description: 'recebimento | requisicao | devolucao (padrao recebimento)' },
                fornecedor_id: { type: 'integer', description: 'obrigatorio em recebimento e devolucao' },
                local_id: { type: 'integer', description: 'localidade de destino (padrao 1)' },
                origem_id: { type: 'integer', description: 'localidade de origem, so em requisicao' },
                destino_id: { type: 'integer', description: 'localidade de destino, so em requisicao' },
                referencia: { type: 'string', description: 'numero da nota/documento do fornecedor' },
                comentario: { type: 'string' },
                itens: {
                    type: 'array',
                    items: {
                        type: 'object',
                        properties: {
                            item_id: { type: 'integer' },
                            quantidade: { type: 'string', description: 'ex "10"' },
                            custo: { type: 'string', description: 'ex "12.50"' },
                            desconto: { type: 'string' },
                            quantidade_volume: { type: 'string' },
                        },
                        required: ['item_id', 'quantidade'],
                    },
                },
            },
            required: ['itens'],
        },
    },
    {
        name: 'pdv_buscar_despesas',
        description: 'Lista/busca despesas lancadas no PDV. Filtra por periodo, categoria ou forma de pagamento.',
        inputSchema: {
            type: 'object',
            properties: {
                search: { type: 'string', description: 'texto na descricao' },
                data_inicio: { type: 'string', description: 'AAAA-MM-DD' },
                data_fim: { type: 'string', description: 'AAAA-MM-DD' },
                categoria: { type: 'integer', description: 'categoria_id' },
                pagamento: { type: 'string' },
                limit: { type: 'integer', description: 'quantos trazer (1-100, padrao 20)' },
                offset: { type: 'integer', description: 'a partir de qual (padrao 0)' },
            },
        },
    },
    {
        name: 'pdv_lancar_despesa',
        description: 'Lanca uma despesa no PDV (energia, frete, material...). categoria_id vem de pdv_buscar_categorias_despesa.',
        inputSchema: {
            type: 'object',
            properties: {
                valor: { type: 'string', description: 'ex "250.00"' },
                descricao: { type: 'string' },
                categoria_id: { type: 'integer' },
                pagamento: { type: 'string', description: 'forma de pagamento' },
                imposto: { type: 'string', description: 'valor de imposto retido, ex "0"' },
                observacao: { type: 'string' },
            },
            required: ['valor'],
        },
    },
    {
        name: 'pdv_buscar_categorias_despesa',
        description: 'Lista as categorias de despesa do PDV, com id e nome.',
        inputSchema: { type: 'object', properties: {} },
    },
    {
        name: 'pdv_buscar_giftcards',
        description: 'Lista/busca cartoes presente do PDV, com numero, saldo e dono.',
        inputSchema: {
            type: 'object',
            properties: {
                search: { type: 'string', description: 'numero do cartao' },
                limit: { type: 'integer', description: 'quantos trazer (1-100, padrao 20)' },
                offset: { type: 'integer', description: 'a partir de qual (padrao 0)' },
            },
        },
    },
    {
        name: 'pdv_saldo_giftcard',
        description: 'Consulta o saldo atual de um cartao presente pelo numero.',
        inputSchema: {
            type: 'object',
            properties: { numero: { type: 'string', description: 'numero do cartao' } },
            required: ['numero'],
        },
    },
    {
        name: 'pdv_criar_giftcard',
        description: 'Cria cartao presente com valor inicial. Sem numero, o PDV sugere o proximo livre.',
        inputSchema: {
            type: 'object',
            properties: {
                valor: { type: 'string', description: 'valor inicial, ex "100.00"' },
                numero: { type: 'string', description: 'opcional; sem ele o PDV escolhe' },
                cliente_id: { type: 'integer', description: 'dono do cartao, opcional' },
            },
            required: ['valor'],
        },
    },
    {
        name: 'pdv_recarregar_giftcard',
        description: 'Soma valor ao saldo de um cartao presente (recarga).',
        inputSchema: {
            type: 'object',
            properties: {
                numero: { type: 'string', description: 'numero do cartao' },
                valor: { type: 'string', description: 'quanto somar, ex "50.00"' },
            },
            required: ['numero', 'valor'],
        },
    },
];

/** Chama a API do PDV. */
async function api(metodo, caminho, corpo) {
    const url = `${PDV_URL.replace(/\/+$/, '')}/api${caminho}`;
    const opts = {
        method: metodo,
        headers: {
            Authorization: `Bearer ${PDV_TOKEN}`,
            'Content-Type': 'application/json',
        },
    };
    if (corpo) opts.body = JSON.stringify(corpo);

    const r = await fetch(url, opts);
    const texto = await r.text();

    try {
        return JSON.parse(texto);
    } catch {
        return { success: false, message: `resposta nao-JSON (HTTP ${r.status})`, bruto: texto.slice(0, 300) };
    }
}

/** Executa uma tool e devolve o texto que a IA le. */
async function executar(nome, args) {
    switch (nome) {
        case 'pdv_buscar_itens': {
            const qs = new URLSearchParams();
            if (args.search) qs.set('search', args.search);
            if (args.limit) qs.set('limit', args.limit);
            if (args.offset) qs.set('offset', args.offset);
            const sufixo = qs.toString() ? `?${qs}` : '';
            return api('GET', `/itens${sufixo}`);
        }

        case 'pdv_obter_item':
            return api('GET', `/itens/${args.id}`);

        case 'pdv_criar_item': {
            const { name, ...resto } = args;
            return api('POST', '/itens', { name, ...resto });
        }

        case 'pdv_alterar_item': {
            const { id, ...campos } = args;
            return api('PATCH', `/itens/${id}`, campos);
        }

        case 'pdv_excluir_item':
            return api('DELETE', `/itens/${args.id}`);

        // ── clientes ─────────────────────────────────────────────────────
        case 'pdv_buscar_clientes':
            return api('GET', `/clientes${querystring(args, ['search', 'limit', 'offset'])}`);

        case 'pdv_obter_cliente':
            return api('GET', `/clientes/${args.id}`);

        case 'pdv_criar_cliente':
            return api('POST', '/clientes', args);

        case 'pdv_alterar_cliente': {
            const { id, ...campos } = args;
            return api('PATCH', `/clientes/${id}`, campos);
        }

        // ── fornecedores ─────────────────────────────────────────────────
        case 'pdv_buscar_fornecedores':
            return api('GET', `/fornecedores${querystring(args, ['search', 'limit', 'offset'])}`);

        case 'pdv_criar_fornecedor':
            return api('POST', '/fornecedores', args);

        // ── atributos ────────────────────────────────────────────────────
        case 'pdv_buscar_atributos':
            return api('GET', '/atributos');

        case 'pdv_obter_atributos_item': {
            const { item_id, definicao } = args;
            return api('GET', `/atributos/${item_id}${querystring({ definicao }, ['definicao'])}`);
        }

        case 'pdv_gravar_atributo_item':
            return api('POST', '/atributos/valores', args);

        // ── vendas ───────────────────────────────────────────────────────
        case 'pdv_buscar_vendas':
            return api('GET', `/vendas${querystring(args, ['search', 'limit', 'offset'])}`);

        case 'pdv_obter_venda':
            return api('GET', `/vendas/${args.id}`);

        case 'pdv_gravar_venda':
            return api('POST', '/vendas', args);

        // ── recebimentos ─────────────────────────────────────────────────
        case 'pdv_buscar_recebimentos':
            return api('GET', `/recebimentos${querystring(args, ['search', 'limit', 'offset'])}`);

        case 'pdv_obter_recebimento':
            return api('GET', `/recebimentos/${args.id}`);

        case 'pdv_gravar_recebimento':
            return api('POST', '/recebimentos', args);

        // ── despesas ─────────────────────────────────────────────────────
        case 'pdv_buscar_despesas':
            return api('GET', `/despesas${querystring(args, ['search', 'data_inicio', 'data_fim', 'categoria', 'pagamento', 'limit', 'offset'])}`);

        case 'pdv_lancar_despesa':
            return api('POST', '/despesas', args);

        case 'pdv_buscar_categorias_despesa':
            return api('GET', '/despesas/categorias');

        // ── cartoes presente ─────────────────────────────────────────────
        case 'pdv_buscar_giftcards':
            return api('GET', `/giftcards${querystring(args, ['search', 'limit', 'offset'])}`);

        case 'pdv_saldo_giftcard':
            return api('GET', `/giftcards/saldo/${encodeURIComponent(args.numero)}`);

        case 'pdv_criar_giftcard':
            return api('POST', '/giftcards', args);

        case 'pdv_recarregar_giftcard': {
            const { numero, ...resto } = args;
            return api('POST', `/giftcards/${encodeURIComponent(numero)}/recarregar`, resto);
        }

        default:
            return { success: false, message: `tool desconhecida: ${nome}` };
    }
}

/** Monta "?a=1&b=2" so com as chaves informadas. */
function querystring(args, chaves) {
    const qs = new URLSearchParams();
    for (const chave of chaves) {
        const v = args[chave];
        if (v !== undefined && v !== null && v !== '') qs.set(chave, v);
    }
    const s = qs.toString();
    return s ? `?${s}` : '';
}

// ── transporte MCP: JSON-RPC 2.0 por stdio ───────────────────────────
const rl = createInterface({ input: process.stdin, terminal: false });

function responder(id, result) {
    process.stdout.write(JSON.stringify({ jsonrpc: '2.0', id, result }) + '\n');
}

rl.on('line', async (linha) => {
    if (!linha.trim()) return;

    let msg;
    try {
        msg = JSON.parse(linha);
    } catch {
        return;
    }

    const { id, method, params } = msg;

    try {
        switch (method) {
            case 'initialize':
                responder(id, {
                    protocolVersion: '2024-11-05',
                    capabilities: { tools: {} },
                    serverInfo: { name: 'pdv', version: '1.0.0' },
                });
                break;

            case 'tools/list':
                responder(id, { tools: TOOLS });
                break;

            case 'tools/call':
                responder(id, {
                    content: [{
                        type: 'text',
                        text: JSON.stringify(await executar(params.name, params.arguments || {}), null, 2),
                    }],
                });
                break;

            case 'notifications/initialized':
                break;

            default:
                if (id !== undefined) {
                    process.stdout.write(JSON.stringify({
                        jsonrpc: '2.0', id,
                        error: { code: -32601, message: `metodo nao suportado: ${method}` },
                    }) + '\n');
                }
        }
    } catch (e) {
        if (id !== undefined) {
            process.stdout.write(JSON.stringify({
                jsonrpc: '2.0', id,
                error: { code: -32603, message: String(e && e.message ? e.message : e) },
            }) + '\n');
        }
    }
});
