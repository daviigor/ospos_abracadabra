<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Employee;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\Item_quantity;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * /api/itens — CRUD de itens em JSON, imitando o fluxo interno do OSPOS.
 *
 * Reusa os mesmos models que app/Controllers/Items.php usa. Nao inventa
 * regra nova: Item::save_value (criar/alterar), Item::delete (soft delete +
 * zera quantidades/inventory), Item::get_info (ler), Item::item_number_exists
 * (codigo de barras nao pode repetir entre itens vivos).
 *
 * Campos aceitos = os mesmos do postSave() de Items.php.
 */
class Itens_controller extends BaseController
{
    private Item $item;
    private Item_quantity $item_quantity;
    private Inventory $inventory;

    public function __construct()
    {
        $this->item = model(Item::class);
        $this->item_quantity = model(Item_quantity::class);
        $this->inventory = model(Inventory::class);
    }

    /** GET /api/itens/:id — ler um item. */
    public function getIndex(?int $itemId = null): ResponseInterface
    {
        if ($itemId === null) {
            return $this->response->setStatusCode(400)
                ->setJSON(['success' => false, 'message' => 'informe o id: /api/itens/{id}']);
        }

        if (!$this->item->exists($itemId)) {
            return $this->response->setStatusCode(404)
                ->setJSON(['success' => false, 'message' => "item nao encontrado: {$itemId}"]);
        }

        return $this->response->setJSON(['success' => true, 'item' => $this->item->get_info($itemId)]);
    }

    /** POST /api/itens — criar item. Devolve 201. */
    public function postIndex(): ResponseInterface
    {
        $payload = $this->payload();

        if ($payload === null) {
            return $this->response->setStatusCode(400)
                ->setJSON(['success' => false, 'message' => 'corpo JSON invalido']);
        }

        $itemData = $this->normalize($payload);

        if (empty($itemData['name'])) {
            return $this->response->setStatusCode(400)
                ->setJSON(['success' => false, 'message' => 'campo obrigatorio: name']);
        }

        if (!empty($itemData['item_number']) && $this->item->item_number_exists($itemData['item_number'])) {
            return $this->response->setStatusCode(409)
                ->setJSON(['success' => false, 'message' => "codigo de barras ja cadastrado: {$itemData['item_number']}"]);
        }

        if (!$this->item->save_value($itemData, NEW_ENTRY)) {
            return $this->response->setStatusCode(500)
                ->setJSON(['success' => false, 'message' => 'falha ao criar item']);
        }

        return $this->response->setStatusCode(201)
            ->setJSON(['success' => true, 'item_id' => $itemData['item_id'], 'item' => $this->item->get_info($itemData['item_id'])]);
    }

    /** PUT|PATCH /api/itens/:id — alterar so os campos enviados. */
    public function putIndex(?int $itemId = null): ResponseInterface
    {
        if ($itemId === null || !$this->item->exists($itemId)) {
            return $this->response->setStatusCode(404)
                ->setJSON(['success' => false, 'message' => "item nao encontrado: {$itemId}"]);
        }

        $payload = $this->payload();

        if ($payload === null) {
            return $this->response->setStatusCode(400)
                ->setJSON(['success' => false, 'message' => 'corpo JSON invalido']);
        }

        $itemData = $this->normalize($payload, true);

        if ($itemData === []) {
            return $this->response->setStatusCode(400)
                ->setJSON(['success' => false, 'message' => 'nada para alterar']);
        }

        if (array_key_exists('name', $itemData) && $itemData['name'] === '') {
            return $this->response->setStatusCode(400)
                ->setJSON(['success' => false, 'message' => 'campo obrigatorio: name']);
        }

        if (!empty($itemData['item_number']) && $this->item->item_number_exists($itemData['item_number'], (string)$itemId)) {
            return $this->response->setStatusCode(409)
                ->setJSON(['success' => false, 'message' => "codigo de barras ja cadastrado em outro item: {$itemData['item_number']}"]);
        }

        if (!$this->item->save_value($itemData, $itemId)) {
            return $this->response->setStatusCode(500)
                ->setJSON(['success' => false, 'message' => 'falha ao alterar item']);
        }

        return $this->response->setJSON(['success' => true, 'item_id' => $itemId, 'item' => $this->item->get_info($itemId)]);
    }

    /** DELETE /api/itens/:id — soft delete (deleted=1) igual Item::delete(). */
    public function deleteIndex(?int $itemId = null): ResponseInterface
    {
        if ($itemId === null || !$this->item->exists($itemId, true)) {
            return $this->response->setStatusCode(404)
                ->setJSON(['success' => false, 'message' => "item nao encontrado: {$itemId}"]);
        }

        if (!$this->item->delete($itemId)) {
            return $this->response->setStatusCode(500)
                ->setJSON(['success' => false, 'message' => 'falha ao excluir item']);
        }

        return $this->response->setJSON(['success' => true, 'message' => "item excluido: {$itemId}", 'item_id' => $itemId]);
    }

    /**
     * Le o corpo JSON da requisicao. Devolve null se vazio ou malformado.
     * O OSPOS original recebe form-encoded; aqui aceitamos JSON e caixemos
     * para array (o frontend novo manda JSON).
     */
    private function payload(): ?array
    {
        $raw = $this->request->getJSON(true);

        if ($raw === null) {
            $raw = $this->request->getPost();
        }

        return is_array($raw) ? $raw : null;
    }

    /**
     * Normaliza o payload para as colunas de items, imitando postSave() de
     * Items.php. Com $partial=true (PUT/PATCH) campos ausentes ficam de fora,
     * para nao sobrescrever o que ja existe.
     */
    private function normalize(array $payload, bool $partial = false): array
    {
        $has = static fn(string $key): bool => array_key_exists($key, $payload);

        $itemType = $has('item_type') ? (int)$payload['item_type'] : ($partial ? null : ITEM);

        $receivingQuantity = $has('receiving_quantity')
            ? parse_quantity((string)$payload['receiving_quantity'])
            : ($partial ? null : 1);

        if ($receivingQuantity === 0.0 && $itemType !== ITEM_TEMP) {
            $receivingQuantity = 1;
        }

        $data = [];

        $put = static function (array &$target, string $key, mixed $value): void {
            $target[$key] = $value;
        };

        if ($has('name')) {
            $put($data, 'name', trim((string)$payload['name']));
        } elseif (!$partial) {
            $data['name'] = '';
        }

        foreach (['description', 'category', 'hsn_code', 'pack_name'] as $field) {
            if ($has($field)) {
                $data[$field] = (string)$payload[$field];
            } elseif (!$partial) {
                $data[$field] = $field === 'pack_name' ? lang('Items.default_pack_name') : '';
            }
        }

        foreach (['cost_price', 'unit_price', 'reorder_level', 'qty_per_pack'] as $field) {
            if ($has($field) && $payload[$field] !== '' && $payload[$field] !== null) {
                $parsed = parse_decimals((string)$payload[$field]);

                if (is_numeric($parsed)) {
                    $data[$field] = $parsed;
                } elseif (!$partial) {
                    $data[$field] = $field === 'qty_per_pack' ? 1 : 0;
                }
            } elseif (!$partial) {
                $data[$field] = $field === 'qty_per_pack' ? 1 : 0;
            }
        }

        foreach (['allow_alt_description', 'is_serialized'] as $field) {
            if ($has($field)) {
                $data[$field] = $payload[$field] ? 1 : 0;
            } elseif (!$partial) {
                $data[$field] = 0;
            }
        }

        if ($has('supplier_id')) {
            $data['supplier_id'] = empty($payload['supplier_id']) ? null : (int)$payload['supplier_id'];
        } elseif (!$partial) {
            $data['supplier_id'] = null;
        }

        if ($has('item_number')) {
            $data['item_number'] = empty($payload['item_number']) ? null : (string)$payload['item_number'];
        } elseif (!$partial) {
            $data['item_number'] = null;
        }

        if ($has('tax_category_id')) {
            $data['tax_category_id'] = empty($payload['tax_category_id']) ? null : (int)$payload['tax_category_id'];
        } elseif (!$partial) {
            $data['tax_category_id'] = null;
        }

        if ($has('stock_type')) {
            $data['stock_type'] = (int)$payload['stock_type'];
        } elseif (!$partial) {
            $data['stock_type'] = HAS_STOCK;
        }

        if ($has('deleted') || $has('is_deleted')) {
            $data['deleted'] = ($payload['deleted'] ?? $payload['is_deleted'] ?? false) ? 1 : 0;
        } elseif (!$partial) {
            $data['deleted'] = 0;
        }

        if ($itemType !== null) {
            $data['item_type'] = $itemType;
        }

        if ($receivingQuantity !== null) {
            $data['receiving_quantity'] = $receivingQuantity;
        }

        // ITEM_TEMP zera estoque/reorder, igual postSave().
        if (($itemType ?? null) === ITEM_TEMP) {
            $data['stock_type'] = HAS_NO_STOCK;
            $data['receiving_quantity'] = 0;
            $data['reorder_level'] = 0;
        }

        return $data;
    }
}
