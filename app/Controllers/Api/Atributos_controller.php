<?php

namespace App\Controllers\Api;

use App\Models\Attribute;
use App\Models\Item;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Atributos do PDV em JSON — /api/atributos.
 *
 * Uma definicao (nome, unidade, tipo, flags) agrupa valores; cada valor e
 * ligado a um item. Reusa Attribute.php inteiro, sem regra nova.
 *
 *   GET    /api/atributos                         -> catalogo de definicoes
 *   GET    /api/atributos/{item_id}?definicao=7   -> valores de um item
 *   GET    /api/atributos/{item_id}/sugerir?definicao=7&termo=az  -> sugestoes
 *   POST   /api/atributos                         -> cria definicao
 *   PATCH  /api/atributos/{id}                    -> altera definicao
 *   DELETE /api/atributos/{id}                    -> apaga definicao (soft)
 *   POST   /api/atributos/valores                 -> grava valor num item
 *   DELETE /api/atributos/valores/{id}            -> apaga valor
 */
class Atributos_controller extends Api_base_controller
{
    private Attribute $attribute;
    private Item $item;

    public function __construct()
    {
        parent::__construct();

        $this->attribute = model(Attribute::class);
        $this->item = model(Item::class);
    }

    /** GET /api/atributos[/{item_id}] — catalogo ou valores de um item. */
    public function getIndex(?string $itemId = null, ?string $acao = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('attributes')) {
            return $erro;
        }

        // /api/atributos/{item_id}/sugerir
        if ($acao === 'sugerir' && $itemId !== null) {
            return $this->sugerir((int) $itemId);
        }

        // sem item: catalogo de definicoes, que e o que a tela de cadastro usa
        if ($itemId === null || $itemId === '') {
            $busca = trim((string) $this->request->getGet('search'));
            $definicoes = $this->attribute->search($busca, 100)->getResultArray();

            return $this->ok([
                'success'    => true,
                'definicoes' => array_map([$this, 'formatarDefinicao'], $definicoes),
                'tipos'      => DEFINITION_TYPES,
                'flags'      => [
                    'itens'      => Attribute::SHOW_IN_ITEMS,
                    'vendas'     => Attribute::SHOW_IN_SALES,
                    'recebimentos' => Attribute::SHOW_IN_RECEIVINGS,
                ],
            ]);
        }

        $itemId = (int) $itemId;
        if (!$this->item->exists($itemId)) {
            return $this->erro("item nao encontrado: {$itemId}", 404);
        }

        $definicaoId = (int) ($this->request->getGet('definicao') ?? 0);

        $linhas = $definicaoId > 0
            ? $this->attribute->get_link_values($itemId, 'items', $itemId, $definicaoId)->getResultArray()
            : $this->attribute->get_attributes_by_item($itemId);

        return $this->ok([
            'success' => true,
            'item_id' => $itemId,
            'valores' => array_map([$this, 'formatarValor'], $linhas),
        ]);
    }

    /** POST /api/atributos — nova definicao. */
    public function postIndex(): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('attributes')) {
            return $erro;
        }

        return $this->salvarDefinicao(NEW_ENTRY);
    }

    /** PATCH /api/atributos/{id} — altera a definicao. */
    public function putIndex(?string $definitionId = null, ?string $acao = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('attributes')) {
            return $erro;
        }

        if ($definitionId === null || !ctype_digit((string) $definitionId)) {
            return $this->erro('informe o id da definicao na rota');
        }
        if (!$this->attribute->exists((int) $definitionId)) {
            return $this->erro("definicao nao encontrada: {$definitionId}", 404);
        }

        return $this->salvarDefinicao((int) $definitionId);
    }

    /** DELETE /api/atributos/{id} — apaga a definicao (soft delete no PDV). */
    public function deleteIndex(?string $definitionId = null): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('attributes')) {
            return $erro;
        }

        if ($definitionId === null || !$this->attribute->exists((int) $definitionId)) {
            return $this->erro("definicao nao encontrada: {$definitionId}", 404);
        }

        $this->attribute->deleteDefinition((int) $definitionId);

        return $this->ok(['success' => true, 'id' => (int) $definitionId, 'mensagem' => 'definicao apagada']);
    }

    /** POST /api/atributos/valores — grava o valor de um atributo num item. */
    public function postValor(): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('attributes')) {
            return $erro;
        }

        $c = $this->corpo();

        $valor = trim((string) ($c['valor'] ?? ''));
        $definicaoId = (int) ($c['definicao_id'] ?? 0);
        $itemId = (int) ($c['item_id'] ?? 0);
        $atributoId = (int) ($c['atributo_id'] ?? 0);

        if ($valor === '') {
            return $this->erro('valor do atributo e obrigatorio');
        }
        if ($definicaoId <= 0 || !$this->attribute->exists($definicaoId)) {
            return $this->erro("definicao nao encontrada: {$definicaoId}", 404);
        }
        if ($itemId <= 0 || !$this->item->exists($itemId)) {
            return $this->erro("item nao encontrado: {$itemId}", 404);
        }

        // mesma validacao de tipo da tela: salva o valor com o tipo da definicao
        $tipo = (string) $this->attribute->getAttributeInfo($definicaoId)->definition_type;

        $attributeId = $this->attribute->saveAttributeValue(
            $valor,
            $definicaoId,
            $itemId,
            $atributoId > 0 ? $atributoId : false,
            $tipo
        );

        if (!$attributeId) {
            return $this->erro('o PDV recusou gravar o valor');
        }

        return $this->ok([
            'success'      => true,
            'attribute_id' => $attributeId,
            'item_id'      => $itemId,
            'definicao_id' => $definicaoId,
            'mensagem'     => 'valor gravado',
        ], 201);
    }

    /**
     * DELETE /api/atributos/valores — apaga um valor de dropdown.
     *
     * A tela identifica o valor pelo par (valor, definicao_id), nao por id
     * interno, porque o mesmo texto pode existir em varias definicoes.
     */
    public function deleteValor(): ResponseInterface
    {
        if ($erro = $this->autenticar()) {
            return $erro;
        }
        if ($erro = $this->exigirGrant('attributes')) {
            return $erro;
        }

        $c = $this->corpo();

        $valor = trim((string) ($c['valor'] ?? ''));
        $definicaoId = (int) ($c['definicao_id'] ?? 0);

        if ($valor === '') {
            return $this->erro('informe valor e definicao_id');
        }
        if ($definicaoId <= 0 || !$this->attribute->exists($definicaoId)) {
            return $this->erro("definicao nao encontrada: {$definicaoId}", 404);
        }

        if (!$this->attribute->deleteDropdownAttributeValue($valor, $definicaoId)) {
            return $this->erro("valor nao encontrado nesta definicao: {$valor}", 404);
        }

        return $this->ok(['success' => true, 'valor' => $valor, 'mensagem' => 'valor apagado']);
    }

    /** GET /api/atributos/{item_id}/sugerir — valores ja usados nesta definicao. */
    private function sugerir(int $itemId): ResponseInterface
    {
        if (!$this->item->exists($itemId)) {
            return $this->erro("item nao encontrado: {$itemId}", 404);
        }

        $definicaoId = (int) ($this->request->getGet('definicao') ?? 0);
        $termo = trim((string) $this->request->getGet('termo'));

        if ($definicaoId <= 0 || !$this->attribute->exists($definicaoId)) {
            return $this->erro("definicao nao encontrada: {$definicaoId}", 404);
        }

        return $this->ok([
            'success'     => true,
            'item_id'     => $itemId,
            'definicao_id' => $definicaoId,
            'sugestoes'   => $this->attribute->get_suggestions($definicaoId, $termo),
        ]);
    }

    /** Cria/altera definicao — mesma validacao do postSaveDefinition da tela. */
    private function salvarDefinicao(int $definitionId): ResponseInterface
    {
        $c = $this->corpo();

        $nome = trim((string) ($c['nome'] ?? ''));
        $unidade = trim((string) ($c['unidade'] ?? ''));

        if ($nome === '') {
            return $this->erro('nome da definicao e obrigatorio');
        }

        // flags vem como lista de bits ([1,2]) ou bitmask crua, como na tela
        $flags = $c['flags'] ?? 0;
        if (is_array($flags)) {
            $flags = array_reduce($flags, static fn ($acc, $f) => $acc | (int) $f, 0);
        } else {
            $flags = (int) $flags;
        }

        // definicao pai (agrupamento) — mesma checagem do validateDefinitionGroup
        $grupoId = $c['grupo_id'] ?? null;
        $grupo = null;
        if ($grupoId !== null && $grupoId !== '' && (int) $grupoId !== 0) {
            $grupo = (int) $grupoId;
            if ($grupo <= 0 || !$this->attribute->exists($grupo)
                || $this->attribute->getAttributeInfo($grupo)->definition_type !== GROUP) {
                return $this->erro('grupo_id invalido: tem que ser uma definicao do tipo grupo');
            }
        }

        $dados = [
            'definition_name'  => $nome,
            'definition_unit'  => $unidade !== '' ? $unidade : null,
            'definition_flags' => $flags,
            'definition_fk'    => $grupo,
        ];

        $tipo = (string) ($c['tipo'] ?? '');
        if ($tipo !== '') {
            if (!in_array($tipo, DEFINITION_TYPES, true)) {
                return $this->erro('tipo invalido: use ' . implode(', ', DEFINITION_TYPES));
            }
            $dados['definition_type'] = $tipo;
        } elseif ($definitionId === NEW_ENTRY) {
            return $this->erro('tipo e obrigatorio na criacao: use ' . implode(', ', DEFINITION_TYPES));
        }

        if (!$this->attribute->saveDefinition($dados, $definitionId)) {
            return $this->erro('o PDV recusou gravar a definicao');
        }

        $novoId = $dados['definition_id'] ?? $definitionId;

        // na criacao a tela aceita uma lista inicial de valores (dropdown)
        $valores = $c['valores'] ?? null;
        if ($definitionId === NEW_ENTRY && is_array($valores)) {
            foreach ($valores as $valor) {
                if (is_string($valor) && $valor !== '') {
                    $this->attribute->saveAttributeValue($valor, (int) $novoId);
                }
            }
        }

        return $this->ok([
            'success'  => true,
            'id'       => (int) $novoId,
            'mensagem' => $definitionId === NEW_ENTRY ? 'definicao criada' : 'definicao alterada',
        ], $definitionId === NEW_ENTRY ? 201 : 200);
    }

    private function formatarDefinicao(array $d): array
    {
        return [
            'definition_id'    => (int) $d['definition_id'],
            'nome'             => $d['definition_name'] ?? null,
            'unidade'          => $d['definition_unit'] ?? null,
            'flags'            => (int) ($d['definition_flags'] ?? 0),
            'tipo'             => $d['definition_type'] ?? null,
            'grupo_id'         => isset($d['definition_fk']) ? (int) $d['definition_fk'] : null,
        ];
    }

    private function formatarValor(array $v): array
    {
        return [
            'attribute_id' => (int) ($v['attribute_id'] ?? 0),
            'item_id'      => (int) ($v['item_id'] ?? 0),
            'definicao_id' => (int) ($v['definition_id'] ?? 0),
            'valor'        => $v['attribute_value'] ?? ($v['definition_value'] ?? null),
            'nome'         => $v['definition_name'] ?? null,
            'unidade'      => $v['definition_unit'] ?? null,
        ];
    }
}
