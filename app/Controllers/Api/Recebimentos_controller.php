<?php

namespace App\Controllers\Api;

use App\Libraries\Receiving_lib;
use App\Models\Item;
use App\Models\Receiving;
use App\Models\Stock_location;
use App\Models\Supplier;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Recebimento de mercadoria em JSON — /api/recebimentos.
 *
 * Cobre os tres casos da tela:
 *   recebimento  (compra)        -> estoque entra
 *   requisicao   (transferencia) -> sai da origem, entra no destino
 *   devolucao    (ao fornecedor) -> estoque sai
 *
 * Igual a venda, o PDV monta o carrinho no Receiving_lib e so entao grava com
 * Receiving::save_value(). Aqui a requisicao ja vem inteira no corpo JSON.
 *
 *   {
 *     "tipo": "recebimento" | "requisicao" | "devolucao",
 *     "fornecedor_id": 4,             // recebimento/devolucao
 *     "origem_id": 1, "destino_id": 2,// requisicao
 *     "local_id": 1,                  // recebimento/devolucao
 *     "comentario": "...", "referencia": "NF 123", "pagamento": "Dinheiro",
 *     "itens": [ { "item_id": 5, "quantidade": "2", "custo": "10.00",
 *                  "desconto": "0", "quantidade_volume": "1" } ]
 *   }
 *
 *   GET    /api/recebimentos?search=     -> lista
 *   GET    /api/recebimentos/{id}        -> cabecalho + itens
 *   POST   /api/recebimentos             -> grava
 *   DELETE /api/recebimentos/{id}        -> cancela e estorna o estoque
 *   GET    /api/recebimentos/opcoes      -> locais, fornecedores e formas de pagamento
 */
class Recebimentos_controller extends Api_base_controller
{
    private const TIPOS = ['recebimento', 'requisicao', 'devolucao'];

    private Receiving $receiving;
    private Item $item;
    private Supplier $supplier;
    private Stock_location $stock_location;
    private Receiving_lib $receiving_lib;

    public function __construct()
    {
        parent::__construct();

        $this->receiving = model(Receiving::class);
        $this->item = model(Item::class);
        $this->supplier = model(Supplier::class);
        $this->stock_location = model(Stock_location::class);
        $this->receiving_lib = new Receiving_lib();
    }

    /** GET /api/recebimentos[/{id}] */
    public function getIndex(?string $receivingId = null, ?string $acao = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('receivings')) {
            return $erro;
        }

        if ($acao === 'opcoes') {
            return $this->opcoes();
        }

        if ($receivingId !== null && $receivingId !== '') {
            return $this->detalhe((int) $receivingId);
        }

        $busca = trim((string) $this->request->getGet('search'));
        $limit = max(1, min((int) ($this->request->getGet('limit') ?? 20), 100));
        $offset = max(0, (int) ($this->request->getGet('offset') ?? 0));

        $db = db_connect();
        $builder = $db->table('receivings');
        $builder->select('receivings.receiving_id, receivings.receiving_time, receivings.reference,
            receivings.comment, receivings.payment_type,
            CONCAT(people.first_name, " ", people.last_name) AS supplier_name, people.person_id AS supplier_id,
            (SELECT SUM(ri.quantity_purchased * ri.item_unit_price)
               FROM ' . $db->prefixTable('receivings_items') . ' AS ri
              WHERE ri.receiving_id = receivings.receiving_id) AS total', false);
        $builder->join('people', 'people.person_id = receivings.supplier_id', 'left');

        if ($busca !== '') {
            $builder->groupStart()
                ->like('receivings.reference', $busca)
                ->orLike('receivings.comment', $busca)
                ->orLike('people.last_name', $busca)
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $builder->orderBy('receivings.receiving_id', 'DESC');
        $builder->limit($limit, $offset);

        $recebimentos = [];
        foreach ($builder->get()->getResultArray() as $linha) {
            $recebimentos[] = [
                'id'            => (int) $linha['receiving_id'],
                'data'          => $linha['receiving_time'],
                'referencia'    => $linha['reference'],
                'comentario'    => $linha['comment'],
                'pagamento'     => $linha['payment_type'],
                'fornecedor_id' => $linha['supplier_id'] === null ? null : (int) $linha['supplier_id'],
                'fornecedor'    => $linha['supplier_name'],
                'total'         => $linha['total'] === null ? null : round((float) $linha['total'], 2),
            ];
        }

        return $this->ok([
            'success'      => true,
            'total'        => $total,
            'limit'        => $limit,
            'offset'       => $offset,
            'recebimentos' => $recebimentos,
        ]);
    }

    /** POST /api/recebimentos — grava recebimento, requisicao ou devolucao. */
    public function postIndex(): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('receivings')) {
            return $erro;
        }

        $c = $this->corpo();

        $tipo = strtolower(trim((string) ($c['tipo'] ?? 'recebimento')));
        if (!in_array($tipo, self::TIPOS, true)) {
            return $this->erro('tipo invalido: use recebimento, requisicao ou devolucao');
        }

        $itens = $c['itens'] ?? [];
        if (!is_array($itens) || $itens === []) {
            return $this->erro('informe itens: [{item_id, quantidade, custo, desconto}]');
        }

        $fornecedorId = (int) ($c['fornecedor_id'] ?? 0);
        if ($tipo !== 'requisicao' && $fornecedorId > 0 && !$this->supplier->exists($fornecedorId)) {
            return $this->erro("fornecedor nao encontrado: {$fornecedorId}", 404);
        }

        // local unico (compra/devolucao) ou par origem->destino (requisicao)
        $origemId = (int) ($c['origem_id'] ?? $c['local_id'] ?? 0);
        $destinoId = (int) ($c['destino_id'] ?? 0);

        if ($origemId <= 0) {
            $locais = $this->locaisDoUsuario();
            if ($locais === []) {
                return $this->erro('nenhum local de estoque disponivel');
            }
            $origemId = (int) $locais[0]['location_id'];
        }

        $this->receiving_lib->clear_all();
        // modos reais do Receiving_lib: receive / requisition / return
        $this->receiving_lib->set_mode(match ($tipo) {
            'requisicao' => 'requisition',
            'devolucao'  => 'return',
            default      => 'receive',
        });
        $this->receiving_lib->set_stock_source($origemId);

        // A requisicao no PDV e o mesmo item lancado duas vezes: negativo na
        // origem e positivo no destino. Mantemos exatamente esse desenho.
        if ($tipo === 'requisicao') {
            if ($destinoId <= 0) {
                return $this->erro('requisicao exige destino_id');
            }
            if ($destinoId === $origemId) {
                return $this->erro('origem e destino nao podem ser o mesmo local');
            }
            $this->receiving_lib->set_stock_destination($destinoId);
        } elseif ($fornecedorId > 0) {
            $this->receiving_lib->set_supplier($fornecedorId);
        }

        if (isset($c['comentario'])) {
            $this->receiving_lib->set_comment((string) $c['comentario']);
        }
        if (isset($c['referencia'])) {
            $this->receiving_lib->set_reference((string) $c['referencia']);
        }

        foreach ($itens as $i => $linha) {
            if (!is_array($linha)) {
                return $this->erro('item ' . ($i + 1) . ' invalido');
            }

            $itemId = (string) ($linha['item_id'] ?? '');
            if ($itemId === '') {
                return $this->erro('item ' . ($i + 1) . ': informe item_id');
            }

            $quantidade = (float) $this->numeroAritmetico((string) ($linha['quantidade'] ?? '1'));
            $custo = $this->numeroLocale((string) ($linha['custo'] ?? $linha['preco'] ?? '0'));
            $desconto = (float) $this->numeroAritmetico((string) ($linha['desconto'] ?? '0'));
            $descontoTipo = (int) ($linha['desconto_tipo'] ?? PERCENT);
            $quantidadeVolume = isset($linha['quantidade_volume'])
                ? (float) $this->numeroAritmetico((string) $linha['quantidade_volume'])
                : null;

            // requisicao: dois lancamentos, como o postRequisitionComplete da tela
            if ($tipo === 'requisicao') {
                $ok = $this->receiving_lib->add_item(
                    $itemId, (int) $quantidade, $destinoId, $desconto, $descontoTipo, (float) $custo
                );
                $ok = $this->receiving_lib->add_item(
                    $itemId, -(int) $quantidade, $origemId, $desconto, $descontoTipo, (float) $custo
                ) && $ok;
            } else {
                $ok = $this->receiving_lib->add_item(
                    $itemId,
                    (int) $quantidade,
                    $origemId,
                    $desconto,
                    $descontoTipo,
                    (float) $custo,
                    null,
                    null,
                    $quantidadeVolume
                );
            }

            if (!$ok) {
                return $this->erro('item ' . ($i + 1) . " nao encontrado no PDV: {$itemId}", 404);
            }
        }

        $cart = $this->receiving_lib->get_cart();
        if ($cart === []) {
            return $this->erro('carrinho vazio: nenhum item foi aceito');
        }

        $pagamento = isset($c['pagamento']) ? (string) $c['pagamento'] : null;

        $receivingId = $this->receiving->save_value(
            $cart,
            $fornecedorId,
            $this->personId(),
            (string) ($c['comentario'] ?? ''),
            (string) ($c['referencia'] ?? ''),
            $pagamento
        );

        $this->receiving_lib->clear_all();

        if ($receivingId === -1) {
            return $this->erro('o PDV recusou a gravacao do recebimento');
        }

        return $this->ok([
            'success'  => true,
            'id'       => $receivingId,
            'tipo'     => $tipo,
            'mensagem' => match ($tipo) {
                'requisicao' => "requisicao {$receivingId} gravada (transferencia de estoque)",
                'devolucao'  => "devolucao {$receivingId} registrada ao fornecedor",
                default      => "recebimento {$receivingId} gravado, estoque atualizado",
            },
        ], 201);
    }

    /** DELETE /api/recebimentos/{id} — cancela e estorna o estoque. */
    public function deleteIndex(?int $receivingId = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('receivings_delete')) {
            return $erro;
        }

        if ($receivingId === null || !$this->receiving->exists($receivingId)) {
            return $this->erro("recebimento nao encontrado: {$receivingId}", 404);
        }

        // o funcionario vai por parametro: nao depende de sessao
        if (!$this->receiving->delete_value($receivingId, $this->personId(), true)) {
            return $this->erro('nao foi possivel cancelar o recebimento');
        }

        return $this->ok([
            'success'  => true,
            'id'       => $receivingId,
            'mensagem' => 'recebimento cancelado, estoque estornado',
        ]);
    }

    private function detalhe(int $receivingId): ResponseInterface
    {
        if (!$this->receiving->exists($receivingId)) {
            return $this->erro("recebimento nao encontrado: {$receivingId}", 404);
        }

        $cabecalho = $this->receiving->get_info($receivingId)->getRowArray() ?? [];
        $itens = $this->receiving->get_receiving_items($receivingId)->getResultArray();

        return $this->ok([
            'success' => true,
            'recebimento' => [
                'id'         => (int) $cabecalho['receiving_id'],
                'data'       => $cabecalho['receiving_time'],
                'fornecedor_id' => $cabecalho['supplier_id'] === null ? null : (int) $cabecalho['supplier_id'],
                'funcionario_id' => (int) $cabecalho['employee_id'],
                'comentario' => $cabecalho['comment'],
                'referencia' => $cabecalho['reference'],
                'pagamento'  => $cabecalho['payment_type'],
            ],
            'itens' => array_map(static fn ($i) => [
                'line'               => (int) $i['line'],
                'item_id'            => (int) $i['item_id'],
                'descricao'          => $i['description'],
                'quantidade'         => (float) $i['quantity_purchased'],
                'quantidade_volume'  => (float) $i['receiving_quantity'],
                'custo_unitario'     => (float) $i['item_unit_price'],
                'custo_medio'        => (float) $i['item_cost_price'],
                'desconto'           => (float) $i['discount'],
                'desconto_tipo'      => (int) $i['discount_type'],
                'local_id'           => (int) $i['item_location'],
            ], $itens),
        ]);
    }

    /** GET /api/recebimentos/opcoes — o que a tela carrega nos selects. */
    private function opcoes(): ResponseInterface
    {
        $locais = [];
        foreach ($this->locaisDoUsuario() as $l) {
            $locais[] = ['id' => (int) $l['location_id'], 'nome' => $l['location_name']];
        }

        $fornecedores = [];
        foreach ($this->supplier->get_all(200, 0)->getResultArray() as $f) {
            $fornecedores[] = [
                'id'       => (int) $f['person_id'],
                'nome'     => trim(($f['first_name'] ?? '') . ' ' . ($f['last_name'] ?? '')),
                'fantasia' => $f['company_name'] ?? null,
            ];
        }

        return $this->ok([
            'success'      => true,
            'locais'       => $locais,
            'fornecedores' => $fornecedores,
            'pagamentos'   => $this->receiving->get_payment_options(),
            'tipos'        => self::TIPOS,
        ]);
    }

    /** Locais de estoque do dono do token, com fallback no primeiro da loja. */
    private function locaisDoUsuario(): array
    {
        $db = db_connect();
        $builder = $db->table('stock_locations');
        $builder->select('stock_locations.location_id, stock_locations.location_name');
        $builder->join('permissions AS permissions', 'permissions.location_id = stock_locations.location_id');
        $builder->join('grants AS grants', 'grants.permission_id = permissions.permission_id');
        $builder->where('grants.person_id', $this->personId());
        $builder->like('permissions.permission_id', 'receivings', 'after');
        $builder->where('stock_locations.deleted', 0);

        $locais = $builder->get()->getResultArray();

        if ($locais === []) {
            $locais = $db->table('stock_locations')
                ->select('location_id, location_name')
                ->where('deleted', 0)
                ->orderBy('location_id', 'ASC')
                ->get()->getResultArray();
        }

        return $locais;
    }

    /** JSON usa ponto decimal; o PDV interpreta no locale da loja (virgula). */
    private function numeroLocale(string $valor): string
    {
        return str_contains($valor, ',') ? $valor : str_replace('.', ',', $valor);
    }

    /** Idade/quantidade sao inteiros no PDV: aceita "2" ou "2,0" e devolve "2". */
    private function numeroAritmetico(string $valor): string
    {
        return str_replace(',', '.', $valor);
    }
}
