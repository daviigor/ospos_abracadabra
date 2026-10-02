<?php

namespace App\Controllers\Api;

use App\Models\Expense;
use App\Models\Expense_category;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Despesas e categorias de despesa em JSON — /api/despesas.
 *
 * Despesa e a saida de caixa que nao e compra de mercadoria (aluguel, energia,
 * frete...). Reusa Expense::save_value(), com a mesma validacao da tela.
 *
 *   GET    /api/despesas?search=&data_inicio=&data_fim=&categoria=&pagamento=
 *   GET    /api/despesas/{id}
 *   POST   /api/despesas
 *   PATCH  /api/despesas/{id}
 *   DELETE /api/despesas/{id}
 *   GET    /api/despesas/categorias
 *   POST   /api/despesas/categorias
 *   PATCH  /api/despesas/categorias/{id}
 *   DELETE /api/despesas/categorias/{id}
 *   GET    /api/despesas/opcoes      -> formas de pagamento
 */
class Despesas_controller extends Api_base_controller
{
    private Expense $expense;
    private Expense_category $expense_category;

    public function __construct()
    {
        parent::__construct();

        $this->expense = model(Expense::class);
        $this->expense_category = model(Expense_category::class);
    }

    /** GET /api/despesas[/{id}] */
    public function getIndex(?string $expenseId = null, ?string $acao = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('expenses')) {
            return $erro;
        }

        if ($acao === 'categorias') {
            return $this->listarCategorias();
        }
        if ($acao === 'opcoes') {
            return $this->ok([
                'success'    => true,
                'pagamentos' => $this->expense->get_payment_options(),
            ]);
        }

        if ($expenseId !== null && $expenseId !== '') {
            return $this->detalhe((int) $expenseId);
        }

        $busca = trim((string) $this->request->getGet('search'));
        $limit = max(1, min((int) ($this->request->getGet('limit') ?? 20), 100));
        $offset = max(0, (int) ($this->request->getGet('offset') ?? 0));

        // mesmos filtros que a tela de gestao manda
        $filtros = [
            'start_date'          => (string) ($this->request->getGet('data_inicio') ?? ''),
            'end_date'            => (string) ($this->request->getGet('data_fim') ?? ''),
            'expense_category_id' => (string) ($this->request->getGet('categoria') ?? ''),
            'payment_type'        => (string) ($this->request->getGet('pagamento') ?? ''),
        ];

        $total = $this->expense->get_found_rows($busca, $filtros);
        $linhas = $this->expense->search($busca, $filtros, $limit, $offset)->getResultArray();

        $despesas = [];
        foreach ($linhas as $linha) {
            $despesas[] = $this->formatar($linha);
        }

        return $this->ok([
            'success'  => true,
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'despesas' => $despesas,
        ]);
    }

    /** POST /api/despesas */
    public function postIndex(): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('expenses')) {
            return $erro;
        }

        return $this->salvar(NEW_ENTRY);
    }

    /** PATCH /api/despesas/{id} */
    public function putIndex(?int $expenseId = null, ?string $acao = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('expenses')) {
            return $erro;
        }

        [$expenseId] = $this->idEAcao((string) $expenseId, $acao);

        if ($expenseId === null || !$this->expense->exists($expenseId)) {
            return $this->erro("despesa nao encontrada: {$expenseId}", 404);
        }

        return $this->salvar($expenseId);
    }

    /** DELETE /api/despesas/{id} */
    public function deleteIndex(?int $expenseId = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('expenses')) {
            return $erro;
        }

        if ($expenseId === null || !$this->expense->exists($expenseId)) {
            return $this->erro("despesa nao encontrada: {$expenseId}", 404);
        }

        if (!$this->expense->delete_list([$expenseId])) {
            return $this->erro('nao foi possivel apagar a despesa');
        }

        return $this->ok(['success' => true, 'id' => $expenseId, 'mensagem' => 'despesa apagada']);
    }

    /** POST /api/despesas/categorias */
    public function postCategoria(): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('expenses_categories')) {
            return $erro;
        }

        return $this->salvarCategoria(NEW_ENTRY);
    }

    /** PATCH /api/despesas/categorias/{id} */
    public function putCategoria(?int $categoryId = null, ?string $acao = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('expenses_categories')) {
            return $erro;
        }

        [$categoryId] = $this->idEAcao((string) $categoryId, $acao);

        if ($categoryId === null || !$this->expense_category->exists($categoryId)) {
            return $this->erro("categoria nao encontrada: {$categoryId}", 404);
        }

        return $this->salvarCategoria($categoryId);
    }

    /** DELETE /api/despesas/categorias/{id} */
    public function deleteCategoria(?int $categoryId = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('expenses_categories')) {
            return $erro;
        }

        if ($categoryId === null || !$this->expense_category->exists($categoryId)) {
            return $this->erro("categoria nao encontrada: {$categoryId}", 404);
        }

        // a tela impede apagar categoria em uso
        $emUso = db_connect()->table('expenses')
            ->where('expense_category_id', $categoryId)
            ->where('deleted', 0)
            ->countAllResults();

        if ($emUso > 0) {
            return $this->erro("categoria em uso por {$emUso} despesa(s); nao pode ser apagada", 409);
        }

        if (!$this->expense_category->delete_list([$categoryId])) {
            return $this->erro('nao foi possivel apagar a categoria');
        }

        return $this->ok(['success' => true, 'id' => $categoryId, 'mensagem' => 'categoria apagada']);
    }

    private function detalhe(int $expenseId): ResponseInterface
    {
        if (!$this->expense->exists($expenseId)) {
            return $this->erro("despesa nao encontrada: {$expenseId}", 404);
        }

        $info = (array) $this->expense->get_info($expenseId);

        return $this->ok([
            'success' => true,
            'despesa' => $this->formatar($info),
            'pagamentos' => $this->expense->get_expense_payment($expenseId)->getResultArray(),
        ]);
    }

    private function listarCategorias(): ResponseInterface
    {
        $busca = trim((string) $this->request->getGet('search'));

        $categorias = [];
        foreach ($this->expense_category->get_all()->getResultArray() as $c) {
            $categorias[] = [
                'id'     => (int) $c['expense_category_id'],
                'nome'   => $c['category_name'],
                'descricao' => $c['category_description'] ?? null,
            ];
        }

        if ($busca !== '') {
            $min = mb_strtolower($busca);
            $categorias = array_values(array_filter(
                $categorias,
                static fn ($c) => str_contains(mb_strtolower((string) $c['nome']), $min)
            ));
        }

        return $this->ok(['success' => true, 'categorias' => $categorias]);
    }

    private function salvar(int $expenseId): ResponseInterface
    {
        $c = $this->corpo();

        $atual = $expenseId === NEW_ENTRY ? [] : (array) $this->expense->get_info($expenseId);

        $valor = $c['valor'] ?? $c['amount'] ?? null;
        if ($valor === null && $expenseId === NEW_ENTRY) {
            return $this->erro('valor da despesa e obrigatorio');
        }

        $categoriaId = (int) ($c['categoria_id'] ?? $c['expense_category_id'] ?? ($atual['expense_category_id'] ?? 0));
        if ($categoriaId <= 0) {
            return $this->erro('categoria_id e obrigatorio');
        }
        if (!$this->expense_category->exists($categoriaId)) {
            return $this->erro("categoria nao encontrada: {$categoriaId}", 404);
        }

        $fornecedorId = $c['fornecedor_id'] ?? $c['supplier_id'] ?? ($atual['supplier_id'] ?? null);

        $dados = [
            'date'                => $c['data'] ?? ($atual['date'] ?? date('Y-m-d H:i:s')),
            'supplier_id'         => ($fornecedorId === '' || $fornecedorId === null) ? null : (int) $fornecedorId,
            'supplier_tax_code'   => (string) ($c['cnpj_fornecedor'] ?? $c['supplier_tax_code'] ?? ($atual['supplier_tax_code'] ?? '')),
            'amount'              => parse_decimals($this->numeroLocale((string) ($valor ?? $atual['amount']))),
            'tax_amount'          => parse_decimals($this->numeroLocale((string) ($c['imposto'] ?? $c['tax_amount'] ?? ($atual['tax_amount'] ?? '0')))),
            'payment_type'        => (string) ($c['pagamento'] ?? $c['payment_type'] ?? ($atual['payment_type'] ?? '')),
            'expense_category_id' => $categoriaId,
            'description'         => (string) ($c['descricao'] ?? $c['description'] ?? ($atual['description'] ?? '')),
            'employee_id'         => (int) ($c['employee_id'] ?? ($atual['employee_id'] ?? $this->personId())),
            'deleted'             => (int) (bool) ($c['deleted'] ?? ($atual['deleted'] ?? 0)),
        ];

        if (!$this->expense->save_value($dados, $expenseId)) {
            return $this->erro('o PDV recusou gravar a despesa');
        }

        $novoId = $dados['expense_id'] ?? $expenseId;

        return $this->ok([
            'success'  => true,
            'id'       => (int) $novoId,
            'mensagem' => $expenseId === NEW_ENTRY ? 'despesa criada' : 'despesa alterada',
        ], $expenseId === NEW_ENTRY ? 201 : 200);
    }

    private function salvarCategoria(int $categoryId): ResponseInterface
    {
        $c = $this->corpo();

        $nome = trim((string) ($c['nome'] ?? $c['category_name'] ?? ''));
        if ($nome === '') {
            return $this->erro('nome da categoria e obrigatorio');
        }

        $atual = $categoryId === NEW_ENTRY ? [] : (array) $this->expense_category->get_info($categoryId);

        $dados = [
            'category_name'        => $nome,
            'category_description' => (string) ($c['descricao'] ?? $c['category_description'] ?? ($atual['category_description'] ?? '')),
        ];

        if (!$this->expense_category->save_value($dados, $categoryId)) {
            return $this->erro('o PDV recusou gravar a categoria');
        }

        $novoId = $dados['expense_category_id'] ?? $categoryId;

        return $this->ok([
            'success'  => true,
            'id'       => (int) $novoId,
            'mensagem' => $categoryId === NEW_ENTRY ? 'categoria criada' : 'categoria alterada',
        ], $categoryId === NEW_ENTRY ? 201 : 200);
    }

    private function formatar(array $e): array
    {
        return [
            'id'            => (int) ($e['expense_id'] ?? 0),
            'data'          => $e['date'] ?? null,
            'valor'         => isset($e['amount']) ? (float) $e['amount'] : null,
            'imposto'       => isset($e['tax_amount']) ? (float) $e['tax_amount'] : null,
            'fornecedor_id' => isset($e['supplier_id']) ? (int) $e['supplier_id'] : null,
            'fornecedor'    => $e['supplier_name'] ?? null,
            'cnpj_fornecedor' => $e['supplier_tax_code'] ?? null,
            'categoria_id'  => isset($e['expense_category_id']) ? (int) $e['expense_category_id'] : null,
            'categoria'     => $e['category_name'] ?? null,
            'pagamento'     => $e['payment_type'] ?? null,
            'descricao'     => $e['description'] ?? null,
            'funcionario_id' => isset($e['employee_id']) ? (int) $e['employee_id'] : null,
        ];
    }

    private function numeroLocale(string $valor): string
    {
        return str_contains($valor, ',') ? $valor : str_replace('.', ',', $valor);
    }
}
