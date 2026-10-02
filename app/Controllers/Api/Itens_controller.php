<?php

namespace App\Controllers\Api;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\Item_quantity;
use App\Models\Item_taxes;
use App\Models\Stock_location;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * CRUD de itens em JSON — /api/itens.
 *
 * Nao reinventa regra: usa os MESMOS models e o MESMO fluxo do
 * app/Controllers/Items.php::postSave(). Se o PDV mudar, a API acompanha.
 *
 *   GET    /api/itens/{id}   ler um item
 *   POST   /api/itens        criar          (grant: items)
 *   PUT    /api/itens/{id}   alterar        (grant: items)
 *   PATCH  /api/itens/{id}   alterar        (grant: items)
 *   DELETE /api/itens/{id}   soft delete    (grant: items)
 *
 * Campos aceitos = os do postSave() do PDV, sem a parte de upload de imagem.
 */
class Itens_controller extends Api_base_controller
{
    private Item $item;
    private Item_quantity $item_quantity;
    private Inventory $inventory;
    private Item_taxes $item_taxes;
    private Stock_location $stock_location;
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        parent::__construct();

        $this->db = db_connect();
        $this->item = model(Item::class);
        $this->item_quantity = model(Item_quantity::class);
        $this->inventory = model(Inventory::class);
        $this->item_taxes = model(Item_taxes::class);
        $this->stock_location = model(Stock_location::class);
    }

    /** GET /api/itens/{id} */
    public function getIndex(?int $itemId = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('items')) {
            return $erro;
        }

        if ($itemId === null) {
            return $this->listar();
        }

        if (!$this->item->exists($itemId)) {
            return $this->erro("item nao encontrado: {$itemId}", 404);
        }

        $info = $this->item->get_info($itemId);

        // get_info() nao junta item_quantities: buscamos a quantidade a parte
        // para o JSON nao mentir zero em item que tem estoque.
        $item = $this->formatar($info);
        $soma = $this->db->table('item_quantities')
            ->selectSum('quantity')
            ->where('item_id', $itemId)
            ->get()->getRow();
        $item['quantity'] = (float) ($soma->quantity ?? 0);

        return $this->ok(['success' => true, 'item' => $item]);
    }

    /**
     * GET /api/itens — busca paginada, no formato que o PDV ja usa.
     *
     * Le os mesmos parametros de app/Controllers/Items.php::getSearch():
     * search, limit, offset. Serve para a IA descobrir ids antes de alterar.
     */
    private function listar(): ResponseInterface
    {
        $busca = trim((string) $this->request->getGet('search'));
        $limit = (int) ($this->request->getGet('limit') ?? 20);
        $offset = (int) ($this->request->getGet('offset') ?? 0);

        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $builder = $this->db->table('items');
        $builder->select('items.*');
        // false = sem escaping, entao o prefixo tem que vir escrito na mao,
        // senao o SELECT aponta pra item_quantities e o JOIN pra ospos_item_quantities.
        $builder->select('IFNULL(SUM(' . $this->db->prefixTable('item_quantities') . '.quantity), 0) AS quantity', false);
        $builder->join('item_quantities', 'item_quantities.item_id = items.item_id', 'left');
        $builder->where('items.deleted', 0);

        if ($busca !== '') {
            $builder->groupStart()
                ->like('items.name', $busca)
                ->orLike('items.item_number', $busca)
                ->orLike('items.category', $busca)
                ->groupEnd();
        }

        $builder->groupBy('items.item_id');
        $builder->orderBy('items.item_id', 'ASC');

        // total antes do limit, para a IA saber quantas paginas existem
        $total = (int) $this->db->table('items')->where('deleted', 0)->countAllResults();

        $builder->limit($limit, $offset);
        $itens = array_map([$this, 'formatar'], $builder->get()->getResult());

        return $this->ok([
            'success' => true,
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
            'itens'   => $itens,
        ]);
    }

    /** POST /api/itens */
    public function postIndex(): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('items')) {
            return $erro;
        }

        return $this->salvar(NEW_ENTRY);
    }

    /** PUT|PATCH /api/itens/{id} */
    public function putIndex(?int $itemId = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('items')) {
            return $erro;
        }

        if ($itemId === null) {
            return $this->erro('informe o id: /api/itens/{id}');
        }

        if (!$this->item->exists($itemId)) {
            return $this->erro("item nao encontrado: {$itemId}", 404);
        }

        return $this->salvar($itemId);
    }

    /** DELETE /api/itens/{id} — soft delete, igual ao PDV. */
    public function deleteIndex(?int $itemId = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('items')) {
            return $erro;
        }

        if ($itemId === null || !$this->item->exists($itemId)) {
            return $this->erro("item nao encontrado: {$itemId}", 404);
        }

        // Item::delete() -> Inventory::reset_quantity() leem o funcionario da
        // sessao. A API e JWT e nao tem sessao: injetamos o dono do token para
        // o lancamento de inventario sair com o autor certo.
        session()->set('person_id', (int) $this->claims['person_id']);

        if (!$this->item->delete($itemId)) {
            return $this->erro('nao foi possivel excluir o item');
        }

        return $this->ok(['success' => true, 'id' => $itemId, 'mensagem' => 'item excluido (soft delete)']);
    }

    /**
     * Nucleo compartilhado de criar/alterar — espelha Items::postSave().
     *
     * $itemId existe  -> alteracao parcial: campo ausente no JSON fica como esta.
     * $itemId ausente -> criacao: campo ausente recebe o default do PDV.
     */
    private function salvar(int $itemId): ResponseInterface
    {
        $c = $this->corpo();
        $novo = $itemId === NEW_ENTRY;

        // No PATCH/PUT, quem nao veio no corpo herda o valor atual do item.
        $atual = $novo ? null : $this->item->get_info($itemId);

        // campo($chave, $default) — resolve "veio / nao veio" de uma vez.
        $campo = static function (string $chave, $default = null) use ($c, $atual) {
            if (array_key_exists($chave, $c)) {
                return $c[$chave];
            }

            return $atual !== null && isset($atual->{$chave}) ? $atual->{$chave} : $default;
        };

        // Dinheiro e quantidade: a API fala JSON (ponto decimal), mas o PDV
        // interpreta numero no locale da loja (pt_BR usa virgula). Traduz antes.
        $numero = static function ($valor) {
            $s = (string) $valor;

            return str_contains($s, ',')
                ? $s                                   // ja veio no formato da loja
                : str_replace('.', ',', $s);           // JSON -> locale
        };

        $nome = trim((string) $campo('name', ''));
        if ($novo && $nome === '') {
            return $this->erro('informe name');
        }
        if (!$novo && $nome === '') {
            return $this->erro('name nao pode ficar vazio');
        }

        // item_number nao pode repetir entre itens vivos (mesma checagem do PDV)
        $itemNumber = $campo('item_number');
        $itemNumber = ($itemNumber === null || $itemNumber === '') ? null : (string) $itemNumber;
        if ($itemNumber !== null && $this->item->item_number_exists($itemNumber, $novo ? '' : (string) $itemId)) {
            return $this->erro("item_number ja existe: {$itemNumber}", 409);
        }

        $itemType = (int) $campo('item_type', ITEM);
        $receivingQuantity = parse_quantity($numero($campo('receiving_quantity', 1)));

        if ($receivingQuantity === 0.0 && $itemType !== ITEM_TEMP) {
            $receivingQuantity = 1;
        }

        $descricao = $campo('description');
        if ($descricao === null || $descricao === '') {
            // a coluna e NOT NULL; o PDV manda string vazia
            $descricao = '';
        }

        $itemData = [
            'name'                  => $nome,
            'description'           => $descricao,
            'category'              => (string) $campo('category', ''),
            'item_type'             => $itemType,
            'stock_type'            => (int) $campo('stock_type', HAS_STOCK),
            'supplier_id'           => empty($campo('supplier_id')) ? null : (int) $campo('supplier_id'),
            'item_number'           => $itemNumber,
            'cost_price'            => parse_decimals($numero($campo('cost_price', '0'))),
            'unit_price'            => parse_decimals($numero($campo('unit_price', '0'))),
            'reorder_level'         => parse_quantity($numero($campo('reorder_level', '0'))),
            'receiving_quantity'    => $receivingQuantity,
            'allow_alt_description' => !empty($campo('allow_alt_description', false)),
            'is_serialized'         => !empty($campo('is_serialized', false)),
            'qty_per_pack'          => empty($campo('qty_per_pack')) ? 1 : parse_quantity($numero($campo('qty_per_pack'))),
            'pack_name'             => empty($campo('pack_name')) ? lang('Items.default_pack_name') : $campo('pack_name'),
            'deleted'               => !empty($campo('is_deleted', false)),
            'hsn_code'              => (string) $campo('hsn_code', ''),
            'tax_category_id'       => empty($campo('tax_category_id')) ? null : (int) $campo('tax_category_id'),
        ];

        if ($itemType === ITEM_TEMP) {
            $itemData['stock_type'] = HAS_NO_STOCK;
            $itemData['receiving_quantity'] = 0;
            $itemData['reorder_level'] = 0;
        }

        $personId = (int) $this->claims['person_id'];

        $db = db_connect();
        $db->transBegin();

        $success = $this->item->save_value($itemData, $itemId);

        if ($success) {
            if ($novo) {
                $itemId = (int) $itemData['item_id'];
            }

            // impostos: so quando o cliente manda tax_names
            if (!empty($c['tax_names']) && is_array($c['tax_names'])) {
                $taxNames = array_values($c['tax_names']);
                $taxPercents = array_values((array) ($c['tax_percents'] ?? []));
                $taxes = [];

                foreach ($taxPercents as $i => $percent) {
                    $valor = parse_tax((string) $percent);
                    if (is_numeric($valor)) {
                        $taxes[] = ['name' => $taxNames[$i] ?? '', 'percent' => $valor];
                    }
                }

                $success = $success && $this->item_taxes->save_value($taxes, $itemId);
            }

            // quantidades por estoque + movimento de inventario, igual ao PDV.
            // get_undeleted_all() filtra por session('person_id'), que nao existe
            // aqui (a API e JWT): usamos os mesmos grants, mas pelo usuario do token.
            foreach ($this->locaisDoUsuario($personId) as $loc) {
                $locId = (int) $loc['location_id'];
                $chave = 'quantity_' . $locId;

                $qtd = isset($c[$chave]) ? parse_quantity($numero($c[$chave])) : null;
                if ($qtd === null) {
                    // nao veio quantidade: mantem a atual
                    continue;
                }

                if ($itemType === ITEM_TEMP) {
                    $qtd = 0.0;
                }

                $qtdAtual = $this->item_quantity->get_item_quantity($itemId, $locId);
                $qtdAtual = (float) $qtdAtual->quantity;

                if ($qtdAtual !== $qtd || $novo) {
                    $success = $success && $this->item_quantity->save_value([
                        'item_id'     => $itemId,
                        'location_id' => $locId,
                        'quantity'    => $qtd,
                    ], $itemId, $locId);

                    $success = $success && $this->inventory->insert([
                        'trans_date'      => date('Y-m-d H:i:s'),
                        'trans_items'     => $itemId,
                        'trans_user'      => $personId,
                        'trans_location'  => $locId,
                        'trans_comment'   => lang('Items.manually_editing_of_quantity'),
                        'trans_inventory' => $qtd - $qtdAtual,
                    ], false);
                }
            }
        }

        if ($success) {
            $db->transCommit();

            return $this->ok([
                'success'  => true,
                'id'       => $itemId,
                'mensagem' => $novo ? 'item criado' : 'item alterado',
            ], $novo ? 201 : 200);
        }

        $db->transRollback();

        // save_value() usa query builder cru: o motivo esta no driver, nao no model.
        $motivo = $db->error()['message'] ?? '';

        return $this->erro('erro ao salvar item' . ($motivo !== '' ? ": {$motivo}" : ''));
    }

    /**
     * Locais de estoque do usuario do token.
     *
     * Stock_location::get_undeleted_all() depende de session('person_id'), que a
     * API nao tem. Mesma consulta, mas pelo person_id vindo das claims do JWT.
     */
    private function locaisDoUsuario(int $personId): array
    {
        $builder = $this->db->table('stock_locations');
        $builder->select('stock_locations.location_id, stock_locations.location_name');
        $builder->join('permissions AS permissions', 'permissions.location_id = stock_locations.location_id');
        $builder->join('grants AS grants', 'grants.permission_id = permissions.permission_id');
        $builder->where('grants.person_id', $personId);
        $builder->like('permissions.permission_id', 'items', 'after');
        $builder->where('stock_locations.deleted', 0);

        $locais = $builder->get()->getResultArray();

        // Usuario sem grant explicito (ex.: admin criado fora do PDV) ainda
        // precisa poder lancar estoque: cai no local padrao da loja.
        if ($locais === []) {
            $locais = $this->db->table('stock_locations')
                ->select('location_id, location_name')
                ->where('deleted', 0)
                ->orderBy('location_id', 'ASC')
                ->limit(1)
                ->get()->getResultArray();
        }

        return $locais;
    }

    /** Normaliza o objeto do model para JSON estavel. */
    private function formatar(object $info): array
    {
        return [
            'item_id'          => (int) $info->item_id,
            'name'             => $info->name,
            'description'      => $info->description,
            'category'         => $info->category,
            'item_number'      => $info->item_number,
            'cost_price'       => (float) $info->cost_price,
            'unit_price'       => (float) $info->unit_price,
            'reorder_level'    => (float) $info->reorder_level,
            'receiving_quantity' => (float) $info->receiving_quantity,
            'quantity'         => (float) ($info->quantity ?? 0),
            'item_type'        => (int) $info->item_type,
            'stock_type'       => (int) $info->stock_type,
            'supplier_id'      => $info->supplier_id !== null ? (int) $info->supplier_id : null,
            'deleted'          => (bool) $info->deleted,
        ];
    }
}
