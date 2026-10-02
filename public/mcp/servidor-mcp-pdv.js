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

        default:
            return { success: false, message: `tool desconhecida: ${nome}` };
    }
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
