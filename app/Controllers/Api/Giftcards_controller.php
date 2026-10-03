<?php

namespace App\Controllers\Api;

use App\Models\Customer;
use App\Models\Giftcard;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Cartoes presente em JSON — /api/giftcards.
 *
 * Cartao presente e saldo prepago: o cliente carrega um valor e usa como forma
 * de pagamento na venda (o PDV debita sozinho pelo Sale_lib). Aqui so se
 * administra o cartao: criar, consultar saldo, recarregar, apagar.
 *
 *   GET    /api/giftcards?search=&limit=&offset=
 *   GET    /api/giftcards/{id}
 *   GET    /api/giftcards/saldo/{numero}        -> saldo atual
 *   POST   /api/giftcards                        -> cria com valor inicial
 *   POST   /api/giftcards/{numero}/recarregar    -> soma valor ao saldo
 *   PATCH  /api/giftcards/{id}                   -> altera dados do cartao
 *   DELETE /api/giftcards/{id}                   -> apaga (soft)
 *   GET    /api/giftcards/proximo-numero         -> sugere o proximo numero
 */
class Giftcards_controller extends Api_base_controller
{
    private Giftcard $giftcard;
    private Customer $customer;

    public function __construct()
    {
        parent::__construct();

        $this->giftcard = model(Giftcard::class);
        $this->customer = model(Customer::class);
    }

    /** GET /api/giftcards[/{id}] */
    public function getIndex(?string $giftcardId = null, ?string $acao = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('giftcards')) {
            return $erro;
        }

        if ($acao === 'saldo' && $giftcardId !== null && $giftcardId !== '') {
            return $this->saldo($giftcardId);
        }
        if ($acao === 'proximo-numero') {
            return $this->proximoNumero();
        }

        if ($giftcardId !== null && $giftcardId !== '') {
            return $this->detalhe((int) $giftcardId);
        }

        $busca = trim((string) $this->request->getGet('search'));
        $limit = max(1, min((int) ($this->request->getGet('limit') ?? 20), 100));
        $offset = max(0, (int) ($this->request->getGet('offset') ?? 0));

        $db = db_connect();
        $pref = $db->getPrefix();
        $builder = $db->table($pref . 'giftcards AS giftcards');
        $builder->select('giftcards.giftcard_id, giftcards.giftcard_number, giftcards.value,
            giftcards.person_id, giftcards.record_time,
            CONCAT(people.first_name, " ", people.last_name) AS person_name', false);
        $builder->join($pref . 'people AS people', 'people.person_id = giftcards.person_id', 'left');

        if ($busca !== '') {
            $builder->groupStart()
                ->like('giftcards.giftcard_number', $busca)
                ->orLike('people.first_name', $busca)
                ->orLike('people.last_name', $busca)
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);

        $builder->orderBy('giftcards.giftcard_id', 'DESC');
        $builder->limit($limit, $offset);

        $giftcards = [];
        foreach ($builder->get()->getResultArray() as $linha) {
            $giftcards[] = $this->formatar($linha);
        }

        return $this->ok([
            'success'   => true,
            'total'     => $total,
            'limit'     => $limit,
            'offset'    => $offset,
            'giftcards' => $giftcards,
        ]);
    }

    /** POST /api/giftcards — cria o cartao com valor inicial. */
    public function postIndex(): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('giftcards')) {
            return $erro;
        }

        $c = $this->corpo();

        $valor = $c['valor'] ?? $c['value'] ?? null;
        if ($valor === null) {
            return $this->erro('valor inicial do cartao e obrigatorio');
        }

        $numero = trim((string) ($c['numero'] ?? $c['giftcard_number'] ?? ''));
        if ($numero === '') {
            // a tela sugere o proximo numero livre
            $numero = (string) ($this->giftcard->get_max_number()->giftcard_number ?? 1) + 1;
        }

        $pessoaId = $c['cliente_id'] ?? $c['person_id'] ?? null;
        if ($pessoaId !== null && $pessoaId !== '' && (int) $pessoaId > 0
            && !$this->customer->exists((int) $pessoaId)) {
            return $this->erro("cliente nao encontrado: {$pessoaId}", 404);
        }

        $dados = [
            'record_time'     => date('Y-m-d H:i:s'),
            'giftcard_number' => $numero,
            'value'           => parse_decimals($this->numeroLocale((string) $valor)),
            'person_id'       => ($pessoaId === null || $pessoaId === '') ? null : (int) $pessoaId,
        ];

        if (!$this->giftcard->save_value($dados, NEW_ENTRY)) {
            return $this->erro('o PDV recusou criar o cartao');
        }

        return $this->ok([
            'success'  => true,
            'id'       => (int) $dados['giftcard_id'],
            'numero'   => $dados['giftcard_number'],
            'valor'    => $dados['value'],
            'mensagem' => 'cartao presente criado',
        ], 201);
    }

    /** POST /api/giftcards/{numero}/recarregar — soma valor ao saldo atual. */
    public function postRecarregar(?string $numero = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('giftcards')) {
            return $erro;
        }

        if ($numero === null || $numero === '') {
            return $this->erro('informe o numero do cartao na rota');
        }

        $giftcardId = $this->giftcard->getGiftcardId((string) $numero);
        if ($giftcardId === false) {
            return $this->erro("cartao nao encontrado: {$numero}", 404);
        }

        $c = $this->corpo();
        $valor = $c['valor'] ?? null;
        if ($valor === null) {
            return $this->erro('valor da recarga e obrigatorio');
        }

        $delta = (float) str_replace(',', '.', (string) $valor);
        if ($delta <= 0) {
            return $this->erro('valor da recarga tem que ser maior que zero');
        }

        // decrementGiftcardValue subtrai (é o debito do PDV ao pagar com cartao).
        // Recarga é o inverso: soma o delta ao saldo atual.
        $saldoAtual = (float) $this->giftcard->get_giftcard_value((string) $numero);
        $this->giftcard->update_giftcard_value((string) $numero, $saldoAtual + $delta);

        return $this->ok([
            'success'     => true,
            'id'          => $giftcardId,
            'numero'      => (string) $numero,
            'saldo_novo'  => $this->giftcard->get_giftcard_value((string) $numero),
            'mensagem'    => 'cartao recarregado',
        ]);
    }

    /** PATCH /api/giftcards/{id} — altera numero, saldo ou dono do cartao. */
    public function putIndex(?int $giftcardId = null, ?string $acao = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('giftcards')) {
            return $erro;
        }

        [$giftcardId] = $this->idEAcao((string) $giftcardId, $acao);

        if ($giftcardId === null || !$this->giftcard->exists($giftcardId)) {
            return $this->erro("cartao nao encontrado: {$giftcardId}", 404);
        }

        $c = $this->corpo();
        $atual = (array) $this->giftcard->get_info($giftcardId);

        $pessoaId = $c['cliente_id'] ?? $c['person_id'] ?? ($atual['person_id'] ?? null);
        if ($pessoaId !== null && $pessoaId !== '' && (int) $pessoaId > 0
            && !$this->customer->exists((int) $pessoaId)) {
            return $this->erro("cliente nao encontrado: {$pessoaId}", 404);
        }

        $dados = [
            'record_time'     => $atual['record_time'] ?? date('Y-m-d H:i:s'),
            'giftcard_number' => (string) ($c['numero'] ?? $c['giftcard_number'] ?? $atual['giftcard_number']),
            'value'           => parse_decimals($this->numeroLocale(
                (string) ($c['valor'] ?? $c['value'] ?? $atual['value'])
            )),
            'person_id'       => ($pessoaId === null || $pessoaId === '') ? null : (int) $pessoaId,
        ];

        if (!$this->giftcard->save_value($dados, $giftcardId)) {
            return $this->erro('o PDV recusou alterar o cartao');
        }

        return $this->ok(['success' => true, 'id' => $giftcardId, 'mensagem' => 'cartao alterado']);
    }

    /** DELETE /api/giftcards/{id} */
    public function deleteIndex(?int $giftcardId = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('giftcards')) {
            return $erro;
        }

        if ($giftcardId === null || !$this->giftcard->exists($giftcardId)) {
            return $this->erro("cartao nao encontrado: {$giftcardId}", 404);
        }

        if (!$this->giftcard->delete($giftcardId)) {
            return $this->erro('nao foi possivel apagar o cartao');
        }

        return $this->ok(['success' => true, 'id' => $giftcardId, 'mensagem' => 'cartao apagado']);
    }

    private function detalhe(int $giftcardId): ResponseInterface
    {
        if (!$this->giftcard->exists($giftcardId)) {
            return $this->erro("cartao nao encontrado: {$giftcardId}", 404);
        }

        return $this->ok([
            'success'  => true,
            'giftcard' => $this->formatar((array) $this->giftcard->get_info($giftcardId)),
        ]);
    }

    /** GET /api/giftcards/saldo/{numero} — saldo atual do cartao. */
    private function saldo(string $numero): ResponseInterface
    {
        $giftcardId = $this->giftcard->getGiftcardId($numero);
        if ($giftcardId === false) {
            return $this->erro("cartao nao encontrado: {$numero}", 404);
        }

        return $this->ok([
            'success'      => true,
            'numero'       => $numero,
            'saldo'        => $this->giftcard->get_giftcard_value($numero),
            'cliente_id'   => $this->giftcard->get_giftcard_customer($numero),
        ]);
    }

    private function proximoNumero(): ResponseInterface
    {
        $maximo = $this->giftcard->get_max_number();

        return $this->ok([
            'success'        => true,
            'proximo_numero' => (string) (($maximo->giftcard_number ?? 0) + 1),
        ]);
    }

    private function formatar(array $g): array
    {
        return [
            'id'         => (int) ($g['giftcard_id'] ?? 0),
            'numero'     => $g['giftcard_number'] ?? null,
            'valor'      => isset($g['value']) ? (float) $g['value'] : null,
            'cliente_id' => isset($g['person_id']) ? (int) $g['person_id'] : null,
            'cliente'    => $g['person_name'] ?? null,
            'registrado' => $g['record_time'] ?? null,
        ];
    }

    private function numeroLocale(string $valor): string
    {
        return str_contains($valor, ',') ? $valor : str_replace('.', ',', $valor);
    }
}
