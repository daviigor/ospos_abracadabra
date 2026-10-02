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

    public function __construct()
    {
        parent::__construct();

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
            return $this->erro('informe o id: /api/itens/{id}');
        }

        if (!$this->item->exists($itemId)) {
            return $this->erro("item nao encontrado: {$itemId}", 404);
        }

        $info = $this->item->get_info($itemId);

        return $this->ok(['success' => true, 'item' => $this->formatar($info)]);
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

        if (!$this->item->delete($itemId)) {
            return $this->erro('nao foi possivel excluir o item');
        }

        return $this->ok(['success' => true, 'id' => $itemId, 'mensagem' => 'item excluido (soft delete)']);
    }

    /**
     * Nucleo compartilhado de criar/alterar — espelha Items::postSave().
     */
    private function salvar(int $itemId): ResponseInterface
    {
        $c = $this->corpo();

        $nome = trim((string) ($c['name'] ?? ''));
        if ($itemId === NEW_ENTRY && $nome === '') {
            return $this->erro('informe name');
        }

        // item_number nao pode repetir entre itens vivos (mesma checagem do PDV)
        $itemNumber = isset($c['item_number']) && $c['item_number'] !== '' ? (string) $c['item_number'] : null;
        if ($itemNumber !== null && $this->item->item_number_exists($itemNumber, $itemId === NEW_ENTRY ? '' : (string) $itemId)) {
            return $this->erro("item_number ja existe: {$itemNumber}", 409);
        }

        $itemType = isset($c['item_type']) ? (int) $c['item_type'] : ITEM;
        $receivingQuantity = isset($c['receiving_quantity']) ? parse_quantity((string) $c['receiving_quantity']) : 1.0;

        if ($receivingQuantity === 0.0 && $itemType !== ITEM_TEMP) {
            $receivingQuantity = 1;
        }

        $itemData = [
            'name'                  => $nome !== '' ? $nome : null,
            'description'           => isset($c['description']) ? $c['description'] : null,
            'category'              => $c['category'] ?? '',
            'item_type'             => $itemType,
            'stock_type'            => isset($c['stock_type']) ? (int) $c['stock_type'] : HAS_STOCK,
            'supplier_id'           => empty($c['supplier_id']) ? null : (int) $c['supplier_id'],
            'item_number'           => $itemNumber,
            'cost_price'            => parse_decimals((string) ($c['cost_price'] ?? '0')),
            'unit_price'            => parse_decimals((string) ($c['unit_price'] ?? '0')),
            'reorder_level'         => parse_quantity((string) ($c['reorder_level'] ?? '0')),
            'receiving_quantity'    => $receivingQuantity,
            'allow_alt_description' => !empty($c['allow_alt_description']),
            'is_serialized'         => !empty($c['is_serialized']),
            'qty_per_pack'          => empty($c['qty_per_pack']) ? 1 : parse_quantity((string) $c['qty_per_pack']),
            'pack_name'             => empty($c['pack_name']) ? lang('Items.default_pack_name') : $c['pack_name'],
            'low_sell_item_id'      => $itemId,
            'deleted'               => !empty($c['is_deleted']),
            'hsn_code'              => $c['hsn_code'] ?? '',
            'tax_category_id'       => empty($c['tax_category_id']) ? null : (int) $c['tax_category_id'],
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
        $novo = false;

        if ($success) {
            if ($itemId === NEW_ENTRY) {
                $itemId = (int) $itemData['item_id'];
                $novo = true;
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

            // quantidades por estoque + movimento de inventario, igual ao PDV
            foreach ($this->stock_location->get_undeleted_all()->getResultArray() as $loc) {
                $locId = (int) $loc['location_id'];
                $chave = 'quantity_' . $locId;

                $qtd = isset($c[$chave]) ? parse_quantity((string) $c[$chave]) : null;
                if ($qtd === null) {
                    // nao veio quantidade: mantem a atual
                    continue;
                }

                if ($itemType === ITEM_TEMP) {
                    $qtd = 0.0;
                }

                $atual = $this->item_quantity->get_item_quantity($itemId, $locId);

                if ((float) $atual->quantity !== $qtd || $novo) {
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
                        'trans_inventory' => $qtd - (float) $atual->quantity,
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

        return $this->erro('erro ao salvar item');
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
