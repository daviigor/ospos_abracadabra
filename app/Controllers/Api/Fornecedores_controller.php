<?php

namespace App\Controllers\Api;

use App\Models\Supplier;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Fornecedores do PDV em JSON — /api/fornecedores.
 *
 * Mesmo padrao dos clientes: pessoa em ospos_people + dados em
 * ospos_suppliers, gravados juntos por Supplier::save_supplier().
 * A API nao escolhe categoria: se vier, usa; senao herda/manter o que existe.
 *
 *   GET    /api/fornecedores?search=&limit=&offset=&categoria=
 *   GET    /api/fornecedores/{id}
 *   POST   /api/fornecedores
 *   PATCH  /api/fornecedores/{id}
 *   DELETE /api/fornecedores/{id}
 *   GET    /api/fornecedores/categorias
 */
class Fornecedores_controller extends Api_base_controller
{
    private Supplier $supplier;

    public function __construct()
    {
        parent::__construct();

        $this->supplier = model(Supplier::class);
    }

    /** GET /api/fornecedores[/{id}] */
    public function getIndex(?string $supplierId = null, ?string $acao = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('suppliers')) {
            return $erro;
        }

        if ($acao === 'categorias') {
            $categorias = [];
            foreach ($this->supplier->get_categories() as $tipo => $nome) {
                $categorias[] = ['tipo' => (int) $tipo, 'nome' => $nome];
            }

            return $this->ok(['success' => true, 'categorias' => $categorias]);
        }

        if ($supplierId !== null && $supplierId !== '') {
            return $this->detalhe((int) $supplierId);
        }

        $busca = trim((string) $this->request->getGet('search'));
        $limit = max(1, min((int) ($this->request->getGet('limit') ?? 20), 100));
        $offset = max(0, (int) ($this->request->getGet('offset') ?? 0));
        $categoria = (int) ($this->request->getGet('categoria') ?? GOODS_SUPPLIER);

        $total = $busca === ''
            ? $this->supplier->get_total_rows()
            : $this->supplier->get_found_rows($busca);

        $fornecedores = [];
        $linhas = $busca === ''
            ? $this->supplier->get_all($limit, $offset, $categoria)->getResultArray()
            : $this->supplier->search($busca, $limit, $offset)->getResultArray();

        foreach ($linhas as $linha) {
            $fornecedores[] = $this->formatar($linha);
        }

        return $this->ok([
            'success'      => true,
            'total'        => $total,
            'limit'        => $limit,
            'offset'       => $offset,
            'fornecedores' => $fornecedores,
        ]);
    }

    /** POST /api/fornecedores */
    public function postIndex(): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('suppliers')) {
            return $erro;
        }

        return $this->salvar(NEW_ENTRY);
    }

    /** PATCH /api/fornecedores/{id} */
    public function putIndex(?int $supplierId = null, ?string $acao = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('suppliers')) {
            return $erro;
        }

        [$supplierId] = $this->idEAcao((string) $supplierId, $acao);

        if ($supplierId === null || !$this->supplier->exists($supplierId)) {
            return $this->erro("fornecedor nao encontrado: {$supplierId}", 404);
        }

        return $this->salvar($supplierId);
    }

    /** DELETE /api/fornecedores/{id} */
    public function deleteIndex(?int $supplierId = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('suppliers')) {
            return $erro;
        }

        if ($supplierId === null || !$this->supplier->exists($supplierId)) {
            return $this->erro("fornecedor nao encontrado: {$supplierId}", 404);
        }

        if (!$this->supplier->delete($supplierId)) {
            return $this->erro('nao foi possivel apagar o fornecedor');
        }

        return $this->ok(['success' => true, 'id' => $supplierId, 'mensagem' => 'fornecedor apagado']);
    }

    private function detalhe(int $supplierId): ResponseInterface
    {
        if (!$this->supplier->exists($supplierId)) {
            return $this->erro("fornecedor nao encontrado: {$supplierId}", 404);
        }

        return $this->ok([
            'success'     => true,
            'fornecedor'  => $this->formatar((array) $this->supplier->get_info($supplierId)),
        ]);
    }

    /**
     * Cria/altera fornecedor. People + suppliers na mesma transacao pelo
     * save_supplier() do PDV.
     *
     * A categoria (supplier_type) so muda se vier explicitamente: GOODS_SUPPLIER
     * e o padrao da tela de fornecedores de mercadoria.
     */
    private function salvar(int $supplierId): ResponseInterface
    {
        $c = $this->corpo();

        $atual = $supplierId === NEW_ENTRY ? [] : (array) $this->supplier->get_info($supplierId);

        $primeiroNome = trim((string) ($c['nome'] ?? $c['first_name'] ?? ($atual['first_name'] ?? '')));
        $sobrenome = trim((string) ($c['sobrenome'] ?? $c['last_name'] ?? ($atual['last_name'] ?? '')));

        if ($primeiroNome === '') {
            return $this->erro('nome (razao social) e obrigatorio');
        }

        $personData = [
            'first_name'   => $primeiroNome,
            'last_name'    => $sobrenome,
            'gender'       => (int) ($c['gender'] ?? ($atual['gender'] ?? 0)),
            'email'        => strtolower(trim((string) ($c['email'] ?? ($atual['email'] ?? '')))),
            'phone_number' => (string) ($c['telefone'] ?? $c['phone_number'] ?? ($atual['phone_number'] ?? '')),
            'address_1'    => (string) ($c['endereco'] ?? $c['address_1'] ?? ($atual['address_1'] ?? '')),
            'address_2'    => (string) ($c['complemento'] ?? $c['address_2'] ?? ($atual['address_2'] ?? '')),
            'city'         => (string) ($c['cidade'] ?? $c['city'] ?? ($atual['city'] ?? '')),
            'state'        => (string) ($c['estado'] ?? $c['state'] ?? ($atual['state'] ?? '')),
            'zip'          => (string) ($c['cep'] ?? $c['zip'] ?? ($atual['zip'] ?? '')),
            'country'      => (string) ($c['pais'] ?? $c['country'] ?? ($atual['country'] ?? '')),
            'comments'     => (string) ($c['observacoes'] ?? $c['comments'] ?? ($atual['comments'] ?? '')),
        ];

        $supplierData = [
            'company_name'  => trim((string) ($c['fantasia'] ?? $c['company_name'] ?? ($atual['company_name'] ?? ''))) ?: null,
            'agency_name'   => trim((string) ($c['agencia'] ?? $c['agency_name'] ?? ($atual['agency_name'] ?? ''))) ?: null,
            'account_number' => trim((string) ($c['conta'] ?? $c['account_number'] ?? ($atual['account_number'] ?? ''))) ?: null,
            'tax_id'        => trim((string) ($c['cnpj'] ?? $c['tax_id'] ?? ($atual['tax_id'] ?? ''))),
            'category'      => (int) ($c['categoria'] ?? $c['category'] ?? ($atual['category'] ?? GOODS_SUPPLIER)),
        ];

        if (!$this->supplier->save_supplier($personData, $supplierData, $supplierId)) {
            return $this->erro('o PDV recusou gravar o fornecedor');
        }

        $novoId = $supplierData['person_id'] ?? $supplierId;

        return $this->ok([
            'success'  => true,
            'id'       => (int) $novoId,
            'mensagem' => $supplierId === NEW_ENTRY ? 'fornecedor criado' : 'fornecedor alterado',
        ], $supplierId === NEW_ENTRY ? 201 : 200);
    }

    private function formatar(array $p): array
    {
        return [
            'id'        => (int) ($p['person_id'] ?? 0),
            'nome'      => $p['first_name'] ?? null,
            'sobrenome' => $p['last_name'] ?? null,
            'fantasia'  => $p['company_name'] ?? null,
            'cnpj'      => $p['tax_id'] ?? null,
            'email'     => $p['email'] ?? null,
            'telefone'  => $p['phone_number'] ?? null,
            'endereco'  => $p['address_1'] ?? null,
            'cidade'    => $p['city'] ?? null,
            'estado'    => $p['state'] ?? null,
            'cep'       => $p['zip'] ?? null,
            'categoria' => isset($p['category']) ? (int) $p['category'] : null,
            'categoria_nome' => isset($p['category'])
                ? $this->supplier->get_category_name((int) $p['category'])
                : null,
        ];
    }
}
