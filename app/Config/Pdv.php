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

    public function __construct()
    {
        parent::__construct();

        $env = env('ITEMS_TAX_NAME_REQUIRED', null);
        if ($env !== null) {
            $this->tax_name_required = filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }
    }
}
