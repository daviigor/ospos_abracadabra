<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setDefaultController('Login');

$routes->get('/', 'Login::index');
$routes->get('login', 'Login::index');
$routes->post('login', 'Login::index');
$routes->post('migrate', 'Login::migrate');

// ── API JSON (JWT proprio; nao usa sessao nem CSRF) ──────────────────
$routes->post('api/auth/token', 'Api\Auth_controller::postToken');

$routes->get('api/itens/(:num)', 'Api\Itens_controller::getIndex/$1');
$routes->get('api/itens', 'Api\Itens_controller::getIndex');
$routes->post('api/itens', 'Api\Itens_controller::postIndex');
$routes->put('api/itens/(:num)', 'Api\Itens_controller::putIndex/$1');
$routes->patch('api/itens/(:num)', 'Api\Itens_controller::putIndex/$1');
$routes->delete('api/itens/(:num)', 'Api\Itens_controller::deleteIndex/$1');

$routes->get('api/vendas/(:num)', 'Api\Vendas_controller::getIndex/$1');
$routes->get('api/vendas', 'Api\Vendas_controller::getIndex');
$routes->post('api/vendas', 'Api\Vendas_controller::postIndex');
$routes->delete('api/vendas/(:num)', 'Api\Vendas_controller::deleteIndex/$1');

$routes->get('api/atributos/(:segment)/sugerir', 'Api\Atributos_controller::getIndex/$1/sugerir');
$routes->get('api/atributos/valores', 'Api\Atributos_controller::getIndex');
$routes->post('api/atributos/valores', 'Api\Atributos_controller::postValor');
$routes->delete('api/atributos/valores', 'Api\Atributos_controller::deleteValor');
$routes->get('api/atributos/(:num)', 'Api\Atributos_controller::getIndex/$1');
$routes->get('api/atributos', 'Api\Atributos_controller::getIndex');
$routes->post('api/atributos', 'Api\Atributos_controller::postIndex');
$routes->patch('api/atributos/(:num)', 'Api\Atributos_controller::putIndex/$1');
$routes->delete('api/atributos/(:num)', 'Api\Atributos_controller::deleteIndex/$1');

$routes->get('api/clientes/(:num)/sugerir', 'Api\Clientes_controller::getIndex/$1/sugerir');
$routes->get('api/clientes/(:num)', 'Api\Clientes_controller::getIndex/$1');
$routes->get('api/clientes', 'Api\Clientes_controller::getIndex');
$routes->post('api/clientes', 'Api\Clientes_controller::postIndex');
$routes->patch('api/clientes/(:num)', 'Api\Clientes_controller::putIndex/$1');
$routes->delete('api/clientes/(:num)', 'Api\Clientes_controller::deleteIndex/$1');

$routes->get('api/fornecedores/categorias', 'Api\Fornecedores_controller::getIndex/0/categorias');
$routes->get('api/fornecedores/(:num)', 'Api\Fornecedores_controller::getIndex/$1');
$routes->get('api/fornecedores', 'Api\Fornecedores_controller::getIndex');
$routes->post('api/fornecedores', 'Api\Fornecedores_controller::postIndex');
$routes->patch('api/fornecedores/(:num)', 'Api\Fornecedores_controller::putIndex/$1');
$routes->delete('api/fornecedores/(:num)', 'Api\Fornecedores_controller::deleteIndex/$1');

$routes->get('api/recebimentos/opcoes', 'Api\Recebimentos_controller::getIndex/0/opcoes');
$routes->get('api/recebimentos/(:num)', 'Api\Recebimentos_controller::getIndex/$1');
$routes->get('api/recebimentos', 'Api\Recebimentos_controller::getIndex');
$routes->post('api/recebimentos', 'Api\Recebimentos_controller::postIndex');
$routes->delete('api/recebimentos/(:num)', 'Api\Recebimentos_controller::deleteIndex/$1');

$routes->get('api/despesas/categorias', 'Api\Despesas_controller::getIndex/0/categorias');
$routes->post('api/despesas/categorias', 'Api\Despesas_controller::postCategoria');
$routes->patch('api/despesas/categorias/(:num)', 'Api\Despesas_controller::putCategoria/$1');
$routes->delete('api/despesas/categorias/(:num)', 'Api\Despesas_controller::deleteCategoria/$1');
$routes->get('api/despesas/opcoes', 'Api\Despesas_controller::getIndex/0/opcoes');
$routes->get('api/despesas/(:num)', 'Api\Despesas_controller::getIndex/$1');
$routes->get('api/despesas', 'Api\Despesas_controller::getIndex');
$routes->post('api/despesas', 'Api\Despesas_controller::postIndex');
$routes->patch('api/despesas/(:num)', 'Api\Despesas_controller::putIndex/$1');
$routes->delete('api/despesas/(:num)', 'Api\Despesas_controller::deleteIndex/$1');

$routes->get('api/giftcards/proximo-numero', 'Api\Giftcards_controller::getIndex/0/proximo-numero');
$routes->get('api/giftcards/saldo/(:segment)', 'Api\Giftcards_controller::getIndex/$1/saldo');
$routes->post('api/giftcards/(:segment)/recarregar', 'Api\Giftcards_controller::postRecarregar/$1');
$routes->get('api/giftcards/(:num)', 'Api\Giftcards_controller::getIndex/$1');
$routes->get('api/giftcards', 'Api\Giftcards_controller::getIndex');
$routes->post('api/giftcards', 'Api\Giftcards_controller::postIndex');
$routes->patch('api/giftcards/(:num)', 'Api\Giftcards_controller::putIndex/$1');
$routes->delete('api/giftcards/(:num)', 'Api\Giftcards_controller::deleteIndex/$1');

// documentacao navegavel dentro do proprio PDV
$routes->get('api', 'Api\Docs_controller::getIndex');
$routes->get('mcp', 'Api\Docs_controller::getMcp');

$routes->add('no_access/index/(:segment)', 'No_access::index/$1');
$routes->add('no_access/index/(:segment)/(:segment)', 'No_access::index/$1/$2');

$routes->add('reports/summary_(:any)/(:any)/(:any)', 'Reports::Summary_$1/$2/$3/$4');
$routes->add('reports/summary_expenses_categories', 'Reports::date_input_only');
$routes->add('reports/summary_payments', 'Reports::date_input_only');
$routes->add('reports/summary_discounts', 'Reports::summary_discounts_input');
$routes->add('reports/summary_(:any)', 'Reports::date_input');

$routes->add('reports/graphical_(:any)/(:any)/(:any)', 'Reports::Graphical_$1/$2/$3/$4');
$routes->add('reports/graphical_summary_expenses_categories', 'Reports::date_input_only');
$routes->add('reports/graphical_summary_discounts', 'Reports::summary_discounts_input');
$routes->add('reports/graphical_(:any)', 'Reports::date_input');

$routes->add('reports/inventory_(:any)/(:any)', 'Reports::Inventory_$1/$2');
$routes->add('reports/inventory_low', 'Reports::inventory_low');
$routes->add('reports/inventory_summary', 'Reports::inventory_summary_input');
$routes->add('reports/inventory_summary/(:any)/(:any)/(:any)', 'Reports::inventory_summary/$1/$2/$3');

$routes->add('reports/detailed_(:any)/(:any)/(:any)/(:any)', 'Reports::Detailed_$1/$2/$3/$4');
$routes->add('reports/detailed_sales', 'Reports::date_input_sales');
$routes->add('reports/detailed_receivings', 'Reports::date_input_recv');

$routes->add('reports/specific_(:any)/(:any)/(:any)/(:any)', 'Reports::Specific_$1/$2/$3/$4');
$routes->add('reports/specific_customers', 'Reports::specific_customer_input');
$routes->add('reports/specific_employees', 'Reports::specific_employee_input');
$routes->add('reports/specific_discounts', 'Reports::specific_discount_input');
$routes->add('reports/specific_suppliers', 'Reports::specific_supplier_input');
