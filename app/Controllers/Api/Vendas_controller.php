<?php

namespace App\Controllers\Api;

use App\Libraries\Sale_lib;
use App\Libraries\Tax_lib;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Sale;
use App\Models\Stock_location;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Vendas, cotacao, fatura e devolucao em JSON — /api/vendas.
 *
 * O PDV monta o carrinho na sessao (Sale_lib) e so entao chama
 * Sale::save_value(). A API nao tem carrinho persistente, entao recebe a
 * venda inteira numa requisicao:
 *
 *   {
 *     "tipo": "venda" | "cotacao" | "fatura" | "devolucao",
 *     "cliente_id": 12,                      // opcional
 *     "comentario": "...",                   // opcional
 *     "numero": "123",                       // opcional; sem ele o PDV gera
 *     "local_id": 1,                         // opcional; default = 1o do usuario
 *     "itens": [ { "item_id": 5, "quantidade": "2", "preco": "19.90",
 *                  "desconto": "0", "desconto_tipo": 0 } ],
 *     "pagamentos": [ { "tipo": "Dinheiro", "valor": "39.80" } ],
 *     "devolucao_de": "POS 123"              // so no tipo devolucao
 *   }
 *
 * Regra de negocio, validacao e gravacao continuam sendo as do PDV: os itens
 * passam pelo mesmo Sale_lib::add_item() da tela, e a gravacao e a mesma
 * Sale::save_value(). Nada foi reimplementado.
 */
class Vendas_controller extends Api_base_controller
{
    /** tipo aceito => [mode do Sale_lib, constante de sale_type do PDV] */
    private const TIPOS = [
        'venda'      => ['sale', SALE_TYPE_POS],
        'fatura'     => ['sale_invoice', SALE_TYPE_INVOICE],
        'cotacao'    => ['sale_quote', SALE_TYPE_QUOTE],
        'devolucao'  => ['return', SALE_TYPE_RETURN],
    ];

    private Sale $sale;
    private Item $item;
    private Customer $customer;
    private Stock_location $stock_location;
    private Sale_lib $sale_lib;
    private Tax_lib $tax_lib;

    public function __construct()
    {
        parent::__construct();

        $this->sale = model(Sale::class);
        $this->item = model(Item::class);
        $this->customer = model(Customer::class);
        $this->stock_location = model(Stock_location::class);
        $this->sale_lib = new Sale_lib();
        $this->tax_lib = new Tax_lib();
    }

    /** GET /api/vendas — lista/busca vendas, no mesmo filtro da tela de gestao. */
    public function getIndex(?int $saleId = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('reports_sales')) {
            return $erro;
        }

        if ($saleId !== null) {
            return $this->detalhe($saleId);
        }

        $busca = trim((string) $this->request->getGet('search'));
        $limit = max(1, min((int) ($this->request->getGet('limit') ?? 20), 100));
        $offset = max(0, (int) ($this->request->getGet('offset') ?? 0));

        $db = db_connect();
        $pref = $db->getPrefix();
        $builder = $db->table($pref . 'sales AS sales');
        $builder->select('sales.sale_id, sales.sale_time, sales.invoice_number, sales.quote_number,
            sales.sale_status, sales.sale_type, sales.comment,
            CONCAT(people.first_name, " ", people.last_name) AS customer_name,
            (SELECT SUM(si.quantity_purchased * si.item_unit_price)
               FROM ' . $pref . 'sales_items AS si
              WHERE si.sale_id = sales.sale_id) AS total', false);
        $builder->join($pref . 'people AS people', 'people.person_id = sales.customer_id', 'left');

        if ($busca !== '') {
            $builder->groupStart()
                ->like('sales.invoice_number', $busca)
                ->orLike('sales.quote_number', $busca)
                ->orLike('sales.comment', $busca)
                ->orLike('people.last_name', $busca)
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);

        $builder->orderBy('sales.sale_id', 'DESC');
        $builder->limit($limit, $offset);

        $vendas = [];
        foreach ($builder->get()->getResultArray() as $linha) {
            $vendas[] = $this->formatarLinha($linha);
        }

        return $this->ok([
            'success' => true,
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
            'vendas'  => $vendas,
        ]);
    }

    /**
     * POST /api/vendas — cria a venda/cotacao/fatura/devolucao inteira.
     *
     * Os itens sao empilhados no Sale_lib (mesmo caminho da tela) e a gravacao
     * e a Sale::save_value() de sempre, entao estoque, imposto, gift card e
     * rewards seguem exatamente a regra do PDV.
     */
    public function postIndex(): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('sales')) {
            return $erro;
        }

        $c = $this->corpo();
        $tipo = strtolower(trim((string) ($c['tipo'] ?? 'venda')));
        $tipo = $this->normalizarTipo($tipo);

        if (!isset(self::TIPOS[$tipo])) {
            return $this->erro('tipo invalido: use venda, cotacao, fatura ou devolucao');
        }

        [$mode, $saleType] = self::TIPOS[$tipo];

        $itens = $c['itens'] ?? [];
        // devolucao: return_entire_sale() clona os itens da venda original,
        // entao a lista vem vazia de proposito
        if ($tipo !== 'devolucao' && (!is_array($itens) || $itens === [])) {
            return $this->erro('informe itens: [{item_id, quantidade, preco, desconto}]');
        }

        $pagamentos = $c['pagamentos'] ?? [];
        if (!is_array($pagamentos)) {
            return $this->erro('pagamentos deve ser uma lista');
        }

        // local padrao = primeiro do usuario do token, igual ao PDV que abre
        // na localidade padrao da loja
        $localId = (int) ($c['local_id'] ?? 0);
        if ($localId <= 0) {
            $locais = $this->locaisDoUsuario();
            if ($locais === []) {
                return $this->erro('nenhum local de estoque disponivel');
            }
            $localId = (int) $locais[0]['location_id'];
        }

        $this->sale_lib->clear_all();
        $this->sale_lib->set_mode($mode);
        $this->sale_lib->set_sale_location($localId);
        $this->sale_lib->set_employee($this->personId());

        if ($tipo === 'devolucao') {
            // A devolucao do PDV e a venda original clonada com quantidade
            // negativa. Aceita a mesma referencia da tela: "POS 123".
            // Numero puro ("338") tambem vale: isValidReceipt() so reconhece o
            // formato "POS 338" ou o numero da fatura, entao normalizamos aqui.
            $referencia = trim((string) ($c['devolucao_de'] ?? ''));
            if ($referencia === '') {
                return $this->erro('informe devolucao_de, ex: "POS 123"');
            }
            if (ctype_digit($referencia)) {
                $referencia = 'POS ' . $referencia;
            }
            // isValidReceipt() recebe a referencia por referencia e a normaliza
            if (!$this->sale->isValidReceipt($referencia)) {
                return $this->erro("venda nao encontrada: {$referencia}", 404);
            }

            $this->sale_lib->return_entire_sale($referencia);
        } else {
            $clienteId = (int) ($c['cliente_id'] ?? 0);
            if ($clienteId > 0) {
                if (!$this->customer->exists($clienteId)) {
                    return $this->erro("cliente nao encontrado: {$clienteId}", 404);
                }
                $this->sale_lib->set_customer($clienteId);
            }

            foreach ($itens as $i => $linha) {
                if (!is_array($linha)) {
                    return $this->erro('item ' . ($i + 1) . ' invalido');
                }

                $itemId = (string) ($linha['item_id'] ?? '');
                if ($itemId === '') {
                    return $this->erro('item ' . ($i + 1) . ': informe item_id');
                }

                $quantidade = (string) ($linha['quantidade'] ?? '1');
                $desconto = (string) ($linha['desconto'] ?? '0');
                $descontoTipo = (int) ($linha['desconto_tipo'] ?? PERCENT);
                $preco = isset($linha['preco']) ? (string) $linha['preco'] : null;
                $descricao = isset($linha['descricao']) ? (string) $linha['descricao'] : null;
                $serie = isset($linha['serial']) ? (string) $linha['serial'] : null;

                // mesmo add_item da tela: valida item, calcula total, aplica
                // desconto e monta a linha do carrinho.
                // add_item() recebe item_id e discount por referencia — precisam ser variaveis.
                $itemIdRef = $itemId;
                $descontoRef = $this->numeroLocale($desconto);

                $ok = $this->sale_lib->add_item(
                    $itemIdRef,
                    $localId,
                    $this->numeroLocale($quantidade),
                    $descontoRef,
                    $descontoTipo,
                    PRICE_MODE_STANDARD,
                    null,
                    null,
                    $preco === null ? null : $this->numeroLocale($preco),
                    $descricao,
                    $serie
                );

                if (!$ok) {
                    return $this->erro('item ' . ($i + 1) . " nao encontrado no PDV: {$itemId}", 404);
                }
            }
        }

        foreach ($pagamentos as $i => $pagamento) {
            if (!is_array($pagamento) || empty($pagamento['tipo'])) {
                return $this->erro('pagamento ' . ($i + 1) . ': informe tipo e valor');
            }

            $this->sale_lib->addPayment(
                (string) $pagamento['tipo'],
                $this->numeroLocale((string) ($pagamento['valor'] ?? '0')),
                isset($pagamento['referencia']) ? (string) $pagamento['referencia'] : null
            );
        }

        if (isset($c['comentario'])) {
            $this->sale_lib->set_comment((string) $c['comentario']);
        }

        // numeracao: cotacao/fatura tem campo proprio; sem numero informado o
        // Sale_lib deixa nulo e o PDV gera pelo token/counter na hora de salvar
        $numero = isset($c['numero']) ? trim((string) $c['numero']) : '';
        if ($numero !== '') {
            match ($tipo) {
                'cotacao' => $this->sale_lib->set_quote_number($numero),
                'fatura'  => $this->sale_lib->set_invoice_number((int) $numero),
                default   => null,
            };
        }

        $cart = $this->sale_lib->get_cart();
        if ($cart === []) {
            return $this->erro('carrinho vazio: nenhum item foi aceito');
        }

        // impostos pelo Tax_lib do PDV; get_taxes() recebe o carrinho por
        // referencia e le o modo/cliente que acabamos de por na sessao
        $salesTaxes = $this->tax_lib->get_taxes($cart);

        $saleStatus = $tipo === 'cotacao' ? SUSPENDED : COMPLETED;

        $saleId = $this->sale->save_value(
            NEW_ENTRY,
            $saleStatus,
            $cart,
            $this->sale_lib->get_customer(),
            $this->personId(),
            $this->sale_lib->get_comment() ?? '',
            $this->sale_lib->get_invoice_number(),
            $this->sale_lib->get_work_order_number(),
            $this->sale_lib->get_quote_number(),
            $saleType,
            $this->sale_lib->getPayments(),
            $this->sale_lib->get_dinner_table(),
            $salesTaxes
        );

        $this->sale_lib->clear_all();

        if ($saleId === NEW_ENTRY) {
            return $this->erro('o PDV recusou a gravacao: confira itens, estoque e pagamentos');
        }
        if ($saleId === INSUFFICIENT_GIFTCARD_BALANCE) {
            return $this->erro('saldo insuficiente no cartao presente');
        }
        if ($saleId === INSUFFICIENT_REWARD_POINTS) {
            return $this->erro('pontos de fidelidade insuficientes');
        }
        if ($saleId === INSUFFICIENT_STOCK) {
            return $this->erro('estoque insuficiente para um dos itens');
        }

        return $this->ok([
            'success'  => true,
            'id'       => $saleId,
            'tipo'     => $tipo,
            'mensagem' => match ($tipo) {
                'cotacao' => 'cotacao gravada (suspensa)',
                'fatura'  => 'fatura emitida',
                'devolucao' => 'devolucao registrada',
                default   => 'venda registrada',
            },
        ], 201);
    }

    /** DELETE /api/vendas/{id} — cancela a venda e devolve o estoque. */
    public function deleteIndex(?int $saleId = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('sales_delete')) {
            return $erro;
        }

        if ($saleId === null || !$this->sale->exists($saleId)) {
            return $this->erro("venda nao encontrada: {$saleId}", 404);
        }

        // Sale::delete() recebe o funcionario por parametro, nao le sessao
        if (!$this->sale->delete($saleId, false, true, $this->personId())) {
            return $this->erro('nao foi possivel cancelar a venda');
        }

        return $this->ok(['success' => true, 'id' => $saleId, 'mensagem' => 'venda cancelada, estoque devolvido']);
    }

    /** GET /api/vendas/{id} — cabecalho, itens e pagamentos de uma venda. */
    private function detalhe(int $saleId): ResponseInterface
    {
        if (!$this->sale->exists($saleId)) {
            return $this->erro("venda nao encontrada: {$saleId}", 404);
        }

        $cabecalho = $this->sale->get_info($saleId)->getRowArray() ?? [];

        $db = db_connect();
        $itens = $db->table('sales_items')
            ->where('sale_id', $saleId)
            ->orderBy('line', 'ASC')
            ->get()->getResultArray();

        $pagamentos = $db->table('sales_payments')
            ->where('sale_id', $saleId)
            ->get()->getResultArray();

        return $this->ok([
            'success'    => true,
            'venda'      => $this->formatarLinha($cabecalho),
            'itens'      => array_map(static fn ($i) => [
                'line'               => (int) $i['line'],
                'item_id'            => (int) $i['item_id'],
                'descricao'          => $i['description'],
                'quantidade'         => (float) $i['quantity_purchased'],
                'preco_unitario'     => (float) $i['item_unit_price'],
                'custo_unitario'     => (float) $i['item_cost_price'],
                'desconto'           => (float) $i['discount'],
                'desconto_tipo'      => (int) $i['discount_type'],
                'total'              => round((float) $i['quantity_purchased'] * (float) $i['item_unit_price'], 2),
                'local_id'           => (int) $i['item_location'],
            ], $itens),
            'pagamentos' => array_map(static fn ($p) => [
                'tipo'   => $p['payment_type'],
                'valor'  => (float) $p['payment_amount'],
            ], $pagamentos),
        ]);
    }

    /** Converte as chaves cruas da tabela para o JSON publico. */
    private function formatarLinha(array $l): array
    {
        $tipo = match ((int) ($l['sale_type'] ?? 0)) {
            SALE_TYPE_INVOICE    => 'fatura',
            SALE_TYPE_QUOTE      => 'cotacao',
            SALE_TYPE_RETURN     => 'devolucao',
            SALE_TYPE_WORK_ORDER => 'ordem_servico',
            default              => 'venda',
        };

        return [
            'sale_id'        => (int) ($l['sale_id'] ?? 0),
            'tipo'           => $tipo,
            'situacao'       => match ((int) ($l['sale_status'] ?? 0)) {
                SUSPENDED => 'suspensa',
                CANCELED  => 'cancelada',
                default   => 'concluida',
            },
            'data'           => $l['sale_time'] ?? null,
            'cliente_id'     => isset($l['customer_id']) ? (int) $l['customer_id'] : null,
            'cliente_nome'   => $l['customer_name'] ?? null,
            'numero_fatura'  => $l['invoice_number'] ?? null,
            'numero_cotacao' => $l['quote_number'] ?? null,
            'comentario'     => $l['comment'] ?? null,
            'total'          => isset($l['total']) ? round((float) $l['total'], 2) : null,
        ];
    }

    /** Aceita apelidos em portugues para o tipo. */
    private function normalizarTipo(string $tipo): string
    {
        return match ($tipo) {
            'venda', 'sale', 'pdv'                 => 'venda',
            'fatura', 'invoice', 'nota'            => 'fatura',
            'cotacao', 'cotação', 'quote', 'orcamento', 'orçamento' => 'cotacao',
            'devolucao', 'devolução', 'return'     => 'devolucao',
            default                                => $tipo,
        };
    }

    /** JSON usa ponto decimal; o PDV interpreta no locale da loja (virgula). */
    /**
     * Valores numericos chegam em JSON (sempre ponto: "19.90"). O nucleo do PDV
     * (bcmul/bcsub em Sale_lib) tambem trabalha com ponto — a virgula pt_BR so
     * aparece na formatacao de saida. Aqui so normalizamos virgula para ponto,
     * para aceitar as duas formas sem quebrar o bcmath.
     */
    private function numeroLocale(string $valor): string
    {
        return str_replace(',', '.', $valor);
    }

    /** Locais de estoque do dono do token (mesma ideia do Itens_controller). */
    private function locaisDoUsuario(): array
    {
        $db = db_connect();
        $builder = $db->table('stock_locations');
        $builder->select('stock_locations.location_id, stock_locations.location_name');
        $builder->join('permissions AS permissions', 'permissions.location_id = stock_locations.location_id');
        $builder->join('grants AS grants', 'grants.permission_id = permissions.permission_id');
        $builder->where('grants.person_id', $this->personId());
        $builder->like('permissions.permission_id', 'sales', 'after');
        $builder->where('stock_locations.deleted', 0);

        $locais = $builder->get()->getResultArray();

        if ($locais === []) {
            $locais = $db->table('stock_locations')
                ->select('location_id, location_name')
                ->where('deleted', 0)
                ->orderBy('location_id', 'ASC')
                ->limit(1)
                ->get()->getResultArray();
        }

        return $locais;
    }
}
