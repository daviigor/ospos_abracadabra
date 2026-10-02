<?php

namespace App\Controllers\Api;

use App\Models\Customer;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Clientes do PDV em JSON — /api/clientes.
 *
 * Cliente e uma pessoa (ospos_people) mais os dados comerciais de
 * ospos_customers. Reusa Customer::save_customer(), que grava as duas tabelas
 * na mesma transacao, com a mesma validacao da tela.
 *
 *   GET    /api/clientes?search=&limit=&offset=   -> lista/busca
 *   GET    /api/clientes/{id}                     -> ficha + estatisticas
 *   POST   /api/clientes                          -> cria
 *   PATCH  /api/clientes/{id}                     -> altera
 *   DELETE /api/clientes/{id}                     -> apaga (soft)
 *   GET    /api/clientes/{id}/sugerir?termo=      -> autocomplete
 */
class Clientes_controller extends Api_base_controller
{
    private Customer $customer;

    public function __construct()
    {
        parent::__construct();

        $this->customer = model(Customer::class);
    }

    /** GET /api/clientes[/{id}] */
    public function getIndex(?int $customerId = null, ?string $acao = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('customers')) {
            return $erro;
        }

        if ($customerId !== null && $acao === 'sugerir') {
            return $this->ok([
                'success'    => true,
                'sugestoes'  => $this->customer->get_search_suggestions(
                    trim((string) $this->request->getGet('termo'))
                ),
            ]);
        }

        if ($customerId !== null) {
            return $this->detalhe($customerId);
        }

        $busca = trim((string) $this->request->getGet('search'));
        $limit = max(1, min((int) ($this->request->getGet('limit') ?? 20), 100));
        $offset = max(0, (int) ($this->request->getGet('offset') ?? 0));

        $total = $busca === ''
            ? $this->customer->get_total_rows()
            : $this->customer->get_found_rows($busca);

        $clientes = [];
        $linhas = $busca === ''
            ? $this->customer->get_all($limit, $offset)->getResultArray()
            : $this->customer->search($busca, $limit, $offset)->getResultArray();

        foreach ($linhas as $linha) {
            $clientes[] = $this->formatar($linha);
        }

        return $this->ok([
            'success'  => true,
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'clientes' => $clientes,
        ]);
    }

    /** POST /api/clientes */
    public function postIndex(): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('customers')) {
            return $erro;
        }

        return $this->salvar(NEW_ENTRY);
    }

    /** PATCH /api/clientes/{id} */
    public function putIndex(?int $customerId = null, ?string $acao = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('customers')) {
            return $erro;
        }

        [$customerId] = $this->idEAcao((string) $customerId, $acao);

        if ($customerId === null || !$this->customer->exists($customerId)) {
            return $this->erro("cliente nao encontrado: {$customerId}", 404);
        }

        return $this->salvar($customerId);
    }

    /** DELETE /api/clientes/{id} */
    public function deleteIndex(?int $customerId = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('customers')) {
            return $erro;
        }

        if ($customerId === null || !$this->customer->exists($customerId)) {
            return $this->erro("cliente nao encontrado: {$customerId}", 404);
        }

        if (!$this->customer->delete($customerId)) {
            return $this->erro('nao foi possivel apagar o cliente');
        }

        return $this->ok(['success' => true, 'id' => $customerId, 'mensagem' => 'cliente apagado']);
    }

    private function detalhe(int $customerId): ResponseInterface
    {
        if (!$this->customer->exists($customerId)) {
            return $this->erro("cliente nao encontrado: {$customerId}", 404);
        }

        $info = (array) $this->customer->get_info($customerId);
        $stats = $this->customer->get_stats($customerId);

        return $this->ok([
            'success'      => true,
            'cliente'      => $this->formatar($info),
            'estatisticas' => $stats === null ? null : (array) $stats,
        ]);
    }

    /**
     * Cria/altera cliente. Grava people + customers na mesma transacao pelo
     * save_customer() do PDV — nao ha INSERT proprio aqui.
     */
    private function salvar(int $customerId): ResponseInterface
    {
        $c = $this->corpo();

        // no PATCH so valida nome se ele veio no corpo; senao herda o que ja existe
        $atual = $customerId === NEW_ENTRY ? [] : (array) $this->customer->get_info($customerId);

        $primeiroNome = trim((string) ($c['nome'] ?? $c['first_name'] ?? ($atual['first_name'] ?? '')));
        $sobrenome = trim((string) ($c['sobrenome'] ?? $c['last_name'] ?? ($atual['last_name'] ?? '')));

        if ($primeiroNome === '') {
            return $this->erro('nome (razao social) e obrigatorio');
        }

        $email = strtolower(trim((string) ($c['email'] ?? ($atual['email'] ?? ''))));
        if ($email !== '' && $this->customer->check_email_exists($email, (string) $customerId)) {
            return $this->erro("e-mail ja cadastrado: {$email}", 409);
        }

        $personData = [
            'first_name'   => $primeiroNome,
            'last_name'    => $sobrenome,
            'gender'       => (int) ($c['gender'] ?? ($atual['gender'] ?? 0)),
            'email'        => $email,
            'phone_number' => (string) ($c['telefone'] ?? $c['phone_number'] ?? ($atual['phone_number'] ?? '')),
            'address_1'    => (string) ($c['endereco'] ?? $c['address_1'] ?? ($atual['address_1'] ?? '')),
            'address_2'    => (string) ($c['complemento'] ?? $c['address_2'] ?? ($atual['address_2'] ?? '')),
            'city'         => (string) ($c['cidade'] ?? $c['city'] ?? ($atual['city'] ?? '')),
            'state'        => (string) ($c['estado'] ?? $c['state'] ?? ($atual['state'] ?? '')),
            'zip'          => (string) ($c['cep'] ?? $c['zip'] ?? ($atual['zip'] ?? '')),
            'country'      => (string) ($c['pais'] ?? $c['country'] ?? ($atual['country'] ?? '')),
            'comments'     => (string) ($c['observacoes'] ?? $c['comments'] ?? ($atual['comments'] ?? '')),
        ];

        $accountNumber = trim((string) ($c['conta'] ?? $c['account_number'] ?? ($atual['account_number'] ?? '')));
        if ($accountNumber !== '' && $this->customer->check_account_number_exists($accountNumber, (string) $customerId)) {
            return $this->erro("numero de conta ja cadastrado: {$accountNumber}", 409);
        }

        // desconto aceita "10" ou "10,5"; o PDV guarda em float
        $desconto = $c['desconto'] ?? $c['discount'] ?? ($atual['discount'] ?? 0);
        $desconto = is_string($desconto) ? $this->numeroLocale($desconto) : $desconto;

        $customerData = [
            'consent'           => (bool) ($c['consentimento'] ?? $c['consent'] ?? ($atual['consent'] ?? false)),
            'account_number'    => $accountNumber === '' ? null : $accountNumber,
            'tax_id'            => trim((string) ($c['cnpj'] ?? $c['tax_id'] ?? ($atual['tax_id'] ?? ''))),
            'company_name'      => trim((string) ($c['fantasia'] ?? $c['company_name'] ?? ($atual['company_name'] ?? ''))) ?: null,
            'discount'          => parse_decimals((string) $desconto),
            'discount_type'     => (int) ($c['desconto_tipo'] ?? $c['discount_type'] ?? ($atual['discount_type'] ?? PERCENT)),
            'package_id'        => $c['package_id'] ?? ($atual['package_id'] ?? null),
            'taxable'           => (bool) ($c['tributavel'] ?? $c['taxable'] ?? ($atual['taxable'] ?? false)),
            'date'              => $atual['date'] ?? date('Y-m-d H:i:s'),
            'employee_id'       => (int) ($c['employee_id'] ?? ($atual['employee_id'] ?? $this->personId())),
            'sales_tax_code_id' => $c['sales_tax_code_id'] ?? ($atual['sales_tax_code_id'] ?? null),
        ];

        if (!$this->customer->save_customer($personData, $customerData, $customerId)) {
            return $this->erro('o PDV recusou gravar o cliente');
        }

        $novoId = $customerData['person_id'] ?? $customerId;

        return $this->ok([
            'success'  => true,
            'id'       => (int) $novoId,
            'mensagem' => $customerId === NEW_ENTRY ? 'cliente criado' : 'cliente alterado',
        ], $customerId === NEW_ENTRY ? 201 : 200);
    }

    private function formatar(array $p): array
    {
        return [
            'id'          => (int) ($p['person_id'] ?? $p['customer_id'] ?? 0),
            'nome'        => $p['first_name'] ?? null,
            'sobrenome'   => $p['last_name'] ?? null,
            'fantasia'    => $p['company_name'] ?? null,
            'cnpj'        => $p['tax_id'] ?? null,
            'email'       => $p['email'] ?? null,
            'telefone'    => $p['phone_number'] ?? null,
            'endereco'    => $p['address_1'] ?? null,
            'cidade'      => $p['city'] ?? null,
            'estado'      => $p['state'] ?? null,
            'cep'         => $p['zip'] ?? null,
            'desconto'    => isset($p['discount']) ? (float) $p['discount'] : null,
            'desconto_tipo' => isset($p['discount_type']) ? (int) $p['discount_type'] : null,
            'pontos'      => isset($p['points']) ? (float) $p['points'] : null,
        ];
    }

    private function numeroLocale(string $valor): string
    {
        return str_contains($valor, ',') ? $valor : str_replace('.', ',', $valor);
    }
}
