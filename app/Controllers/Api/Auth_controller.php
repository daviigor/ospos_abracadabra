<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * POST /api/auth/token — troca usuario+senha por um JWT.
 *
 * A senha e a MESMA do PDV (ospos_employees, hash_version 2 / bcrypt),
 * conferida com password_verify. Nao usamos Employee::login() de proposito:
 * aquele metodo chama session->regenerate() e grava na sessao, o que faz
 * sentido no navegador e nao numa chamada de API sem cookie.
 *
 * Retorna apenas o token — nenhum dado sensivel do funcionario.
 */
class Auth_controller extends Api_base_controller
{
    public function postToken(): ResponseInterface
    {
        $corpo = $this->corpo();
        $username = (string) ($corpo['username'] ?? '');
        $password = (string) ($corpo['password'] ?? '');

        if ($username === '' || $password === '') {
            return $this->erro('informe username e password');
        }

        $db = db_connect();

        $row = $db->table('employees')
            ->select('person_id, username, password, hash_version')
            ->where('username', $username)
            ->where('deleted', 0)
            ->get(1)
            ->getRow();

        // mesma comparacao do Employee::login(), sem efeito de sessao
        $valido = false;
        if ($row !== null) {
            if ($row->hash_version === '2') {
                $valido = password_verify($password, $row->password);
            } elseif ($row->hash_version === '1') {
                // hash md5 legado: confere e ja migra para bcrypt
                if (hash_equals((string) $row->password, md5($password))) {
                    $db->table('employees')
                        ->where('person_id', $row->person_id)
                        ->update([
                            'hash_version' => 2,
                            'password' => password_hash($password, PASSWORD_DEFAULT),
                        ]);
                    $valido = true;
                }
            }
        }

        if (!$valido) {
            // mensagem generica: nao revela se o usuario existe
            return $this->naoAutorizado('usuario ou senha invalidos');
        }

        $ttl = (int) env('JWT_TTL', 43200);
        $token = $this->jwt->encode([
            'person_id' => (int) $row->person_id,
            'username' => $row->username,
        ], $ttl);

        return $this->ok([
            'success' => true,
            'token' => $token,
            'tipo' => 'Bearer',
            'expira_em' => $ttl,
        ]);
    }
}
