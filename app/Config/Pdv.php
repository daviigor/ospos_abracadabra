<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Ajustes locais de PDV sobre o OSPOS.
 *
 * O OSPOS valida o "nome do imposto" (tax_name) como obrigatório no servidor,
 * o que trava o cadastro de produto em uso real no Brasil. Aqui essa checagem
 * pode ser desligada sem mexer no CRUD nem no formulário.
 */
class Pdv extends BaseConfig
{
    /**
     * Exigir nome de imposto ao salvar produto (single e bulk update).
     *
     * false = não valida tax_names (padrão).
     * true  = comportamento original do OSPOS.
     *
     * Chave no .env: ITEMS_TAX_NAME_REQUIRED
     */
    public bool $tax_name_required = false;

    /**
     * Segredo que assina os JWT da API. Chave no .env: JWT_SECRET
     *
     * Sem valor aqui de proposito: quem define e o .env, e Jwt.php le de la.
     * O default so evita erro em ambiente sem .env.
     */
    public string $jwt_secret = '';

    /**
     * Validade do token da API, em segundos. Chave no .env: JWT_TTL
     * Default: 43200 (12 horas).
     */
    public int $jwt_ttl = 43200;

    public function __construct()
    {
        parent::__construct();

        $env = env('ITEMS_TAX_NAME_REQUIRED', null);
        if ($env !== null) {
            $this->tax_name_required = filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }

        $this->jwt_secret = (string) env('JWT_SECRET', '');
        $this->jwt_ttl = (int) env('JWT_TTL', 43200);
    }
}
