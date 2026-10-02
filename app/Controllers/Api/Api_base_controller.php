<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\Jwt;
use App\Models\Employee;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base de todos os controllers da API.
 *
 * Reusa a autenticacao do proprio PDV: a senha e a mesma de ospos_employees
 * (hash_version 2 / bcrypt), validada por Employee::login(). O token JWT e
 * so um envelope para a sessao — as regras de permissao continuam vindo de
 * Employee::has_module_grant() sobre ospos_grants.
 *
 * Fluxo:
 *   1. POST /api/auth/token  {username, password}  -> {token, expira_em}
 *   2. demais rotas: header  Authorization: Bearer <token>
 */
abstract class Api_base_controller extends BaseController
{
    protected Jwt $jwt;
    protected Employee $employee;

    /** Claims do token valido (person_id, username). */
    protected array $claims = [];

    public function __construct()
    {
        $this->jwt = new Jwt();
        $this->employee = model(Employee::class);
    }

    /**
     * Autentica a requisicao pelo Bearer token. Retorna Response 401 se falhar,
     * ou null se estiver tudo certo.
     */
    protected function autenticar(): ?ResponseInterface
    {
        $header = $this->request->getHeaderLine('Authorization');

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return $this->naoAutorizado('token ausente');
        }

        $claims = $this->jwt->decode(trim($m[1]));
        if ($claims === null) {
            return $this->naoAutorizado('token invalido ou expirado');
        }

        // o funcionario ainda existe e nao foi deletado?
        $personId = (int) ($claims['person_id'] ?? 0);
        if ($personId <= 0 || !$this->employee->exists($personId)) {
            return $this->naoAutorizado('funcionario inexistente');
        }

        $this->claims = $claims;

        return null;
    }

    /**
     * Exige um grant de modulo (mesma tabela ospos_grants do PDV).
     * Use 'items' para itens, 'sales' para vendas, 'customers' para clientes.
     */
    protected function exigirGrant(string $moduleId): ?ResponseInterface
    {
        $personId = (int) ($this->claims['person_id'] ?? 0);

        if (!$this->employee->has_module_grant($moduleId, $personId)) {
            return $this->response->setStatusCode(403)
                ->setJSON(['success' => false, 'message' => "sem permissao: {$moduleId}"]);
        }

        return null;
    }

    /**
     * pessoa logada no token.
     *
     * Os models do PDV leem o funcionario de session('person_id'), que nao
     * existe na API (JWT). Sempre que um model reusado precisar disso, injete
     * este id na sessao antes de chamar, pelo helper abaixo.
     */
    protected function personId(): int
    {
        return (int) ($this->claims['person_id'] ?? 0);
    }

    /**
     * Injeta o dono do token na sessao so durante o callback.
     *
     * Varios models do PDV (Item::delete, Inventory::reset_quantity,
     * Sale::save, libraries de carrinho) escrevem o autor do lancamento lendo
     * get_logged_in_employee_info(). Sem isto, eles quebram com "property on
     * bool" porque a API nao tem sessao. Enxertamos o person_id do token,
     * rodamos, e restauramos o valor anterior.
     */
    protected function comSessaoDoToken(callable $fn)
    {
        $sessao = session();
        $chave = 'person_id';
        $tinha = $sessao->has($chave);
        $antes = $tinha ? $sessao->get($chave) : null;

        $sessao->set($chave, $this->personId());

        try {
            return $fn();
        } finally {
            if ($tinha) {
                $sessao->set($chave, $antes);
            } else {
                $sessao->remove($chave);
            }
        }
    }

    /**
     * Resolve o id de rota aceitando tanto /api/x/{id} quanto /api/x/{id}/criar
     * e /api/x/{id}/alterar, que e como algumas telas do PDV separam as acoes.
     *
     * Devolve [id, acao] — acao e 'index', 'criar' ou 'alterar'.
     *
     * @return array{0: int|null, 1: string}
     */
    protected function idEAcao(?string $id = null, ?string $acao = null): array
    {
        // Rota no formato antigo: /api/x/{id} — a acao vem como 2o parametro.
        $candidato = $id ?? $acao;

        if ($candidato !== null && ctype_digit((string) $candidato)) {
            return [(int) $candidato, 'index'];
        }

        return [null, 'index'];
    }

    protected function naoAutorizado(string $msg): ResponseInterface
    {
        return $this->response->setStatusCode(401)
            ->setJSON(['success' => false, 'message' => $msg]);
    }

    protected function ok(array $dados, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON($dados);
    }

    protected function erro(string $msg, int $status = 400): ResponseInterface
    {
        return $this->response->setStatusCode($status)
            ->setJSON(['success' => false, 'message' => $msg]);
    }

    /**
     * Corpo JSON da requisicao, com fallback para form-post.
     *
     * @return array<string, mixed>
     */
    protected function corpo(): array
    {
        $json = $this->request->getJSON(true);

        return is_array($json) ? $json : $this->request->getPost();
    }
}
