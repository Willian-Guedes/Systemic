<?php

declare(strict_types=1);

namespace Automax\Controllers;

use Automax\Config\Database;
use Automax\Config\DatabaseException;
use Automax\Auth\AccessControl;
use Automax\Support\Logger;

/**
 * Gestão de clientes pelo gerente: listagem, cadastro, edição
 * (incluindo status VIP) e remoção. Todas as ações exigem a
 * permissão 'clientes.gerenciar', concedida apenas ao nível 'gerente'.
 *
 * Não confundir com Automax\Controllers\ClienteController, que atende
 * a área autenticada do próprio cliente (seus veículos, sua foto etc).
 */
class ClienteGerenciaController
{
    private const POR_PAGINA = 15;

    public static function listar(): void
    {
        AccessControl::exigir_permissao('clientes.gerenciar');

        $pagina = self::validar_int_positivo($_GET['pagina'] ?? '1') ?: 1;
        $busca  = trim($_GET['busca'] ?? '');
        $vip    = $_GET['vip'] ?? '';

        try {
            $db     = Database::get_instance();
            $offset = ($pagina - 1) * self::POR_PAGINA;

            [$where_sql, $params_base] = self::montar_filtros($busca, $vip);

            $total = (int) ($db->query_one(
                "SELECT COUNT(*) AS total FROM clientes {$where_sql}",
                $params_base
            )['total'] ?? 0);

            $linhas = $db->query(
                "SELECT id_cliente, nome_cliente, CPF, celular, email, vip
                   FROM clientes
                 {$where_sql}
                  ORDER BY nome_cliente ASC
                  LIMIT :limite OFFSET :offset",
                array_merge($params_base, [':limite' => self::POR_PAGINA, ':offset' => $offset])
            );

            $clientes = array_map(fn(array $r): array => self::formatar_linha($r), $linhas);

            self::responder_json([
                'clientes'      => $clientes,
                'total'         => $total,
                'pagina'        => $pagina,
                'total_paginas' => max(1, (int) ceil($total / self::POR_PAGINA)),
            ]);

        } catch (DatabaseException $e) {
            error_log('[ClienteGerenciaController] listar: ' . $e->getMessage());
            self::responder_erro('Erro ao consultar banco de dados.', 500);
        }
    }

    public static function buscar(array $params): void
    {
        AccessControl::exigir_permissao('clientes.gerenciar');

        $id = self::validar_int_positivo($params['id'] ?? '');
        if ($id === null) {
            self::responder_erro('ID inválido.', 400);
        }

        try {
            $db    = Database::get_instance();
            $linha = $db->query_one(
                'SELECT id_cliente, nome_cliente, CPF, celular, email, vip
                   FROM clientes
                  WHERE id_cliente = :id
                  LIMIT 1',
                [':id' => $id]
            );

            if (!$linha) {
                self::responder_erro('Cliente não encontrado.', 404);
            }

            self::responder_json(self::formatar_linha($linha));

        } catch (DatabaseException $e) {
            error_log('[ClienteGerenciaController] buscar: ' . $e->getMessage());
            self::responder_erro('Erro ao consultar banco de dados.', 500);
        }
    }

    public static function criar(): void
    {
        AccessControl::exigir_permissao('clientes.gerenciar');
        self::validar_csrf();

        $body = self::ler_json_body();

        $nome     = trim($body['nome']     ?? '');
        $cpf      = preg_replace('/\D/', '', $body['cpf'] ?? '');
        $celular  = trim($body['celular']  ?? '');
        $email    = strtolower(trim($body['email'] ?? ''));
        $senha    = trim($body['senha']    ?? '');
        $vip      = self::normalizar_vip($body['vip'] ?? false);

        $erro = self::validar_campos($nome, $cpf, $celular, $email, $senha, true);
        if ($erro) {
            self::responder_erro($erro, 422);
        }

        try {
            $db = Database::get_instance();

            if (self::cpf_em_uso($db, $cpf)) {
                self::responder_erro('Já existe um cliente com este CPF.', 409);
            }

            if (self::email_em_uso($db, $email)) {
                self::responder_erro('Já existe um cliente com este e-mail.', 409);
            }

            $hash = password_hash($senha, PASSWORD_BCRYPT);

            $db->execute(
                'INSERT INTO clientes (nome_cliente, CPF, celular, email, senha, vip)
                 VALUES (:nome, :cpf, :celular, :email, :senha, :vip)',
                [
                    ':nome'    => $nome,
                    ':cpf'     => $cpf,
                    ':celular' => $celular,
                    ':email'   => $email,
                    ':senha'   => $hash,
                    ':vip'     => $vip,
                ]
            );

            http_response_code(201);
            self::responder_json(['mensagem' => 'Cliente criado com sucesso.']);

            Logger::registrar("Cliente \"{$nome}\" cadastrado" . ($vip ? ' (VIP).' : '.'));

        } catch (DatabaseException $e) {
            error_log('[ClienteGerenciaController] criar: ' . $e->getMessage());
            self::responder_erro('Erro ao salvar no banco de dados.', 500);
        }
    }

    public static function atualizar(array $params): void
    {
        AccessControl::exigir_permissao('clientes.gerenciar');
        self::validar_csrf();

        $id = self::validar_int_positivo($params['id'] ?? '');
        if ($id === null) {
            self::responder_erro('ID inválido.', 400);
        }

        $body = self::ler_json_body();

        $nome    = trim($body['nome']    ?? '');
        $cpf     = preg_replace('/\D/', '', $body['cpf'] ?? '');
        $celular = trim($body['celular'] ?? '');
        $email   = strtolower(trim($body['email'] ?? ''));
        $senha   = trim($body['senha']   ?? '');
        $vip     = self::normalizar_vip($body['vip'] ?? false);

        $erro = self::validar_campos($nome, $cpf, $celular, $email, $senha, false);
        if ($erro) {
            self::responder_erro($erro, 422);
        }

        try {
            $db = Database::get_instance();

            $existe = $db->query_one(
                'SELECT id_cliente FROM clientes WHERE id_cliente = :id LIMIT 1',
                [':id' => $id]
            );

            if (!$existe) {
                self::responder_erro('Cliente não encontrado.', 404);
            }

            if (self::cpf_em_uso($db, $cpf, $id)) {
                self::responder_erro('Este CPF já está em uso por outro cliente.', 409);
            }

            if (self::email_em_uso($db, $email, $id)) {
                self::responder_erro('Este e-mail já está em uso por outro cliente.', 409);
            }

            if ($senha !== '') {
                $hash = password_hash($senha, PASSWORD_BCRYPT);
                $db->execute(
                    'UPDATE clientes
                        SET nome_cliente = :nome, CPF = :cpf, celular = :celular,
                            email = :email, senha = :senha, vip = :vip
                      WHERE id_cliente = :id',
                    [
                        ':nome' => $nome, ':cpf' => $cpf, ':celular' => $celular,
                        ':email' => $email, ':senha' => $hash, ':vip' => $vip, ':id' => $id,
                    ]
                );
            } else {
                $db->execute(
                    'UPDATE clientes
                        SET nome_cliente = :nome, CPF = :cpf, celular = :celular,
                            email = :email, vip = :vip
                      WHERE id_cliente = :id',
                    [
                        ':nome' => $nome, ':cpf' => $cpf, ':celular' => $celular,
                        ':email' => $email, ':vip' => $vip, ':id' => $id,
                    ]
                );
            }

            self::responder_json(['mensagem' => 'Cliente atualizado com sucesso.']);

            Logger::registrar("Cliente \"{$nome}\" atualizado.");

        } catch (DatabaseException $e) {
            error_log('[ClienteGerenciaController] atualizar: ' . $e->getMessage());
            self::responder_erro('Erro ao atualizar no banco de dados.', 500);
        }
    }

    public static function deletar(array $params): void
    {
        AccessControl::exigir_permissao('clientes.gerenciar');
        self::validar_csrf();

        $id = self::validar_int_positivo($params['id'] ?? '');
        if ($id === null) {
            self::responder_erro('ID inválido.', 400);
        }

        try {
            $db = Database::get_instance();

            $existe = $db->query_one(
                'SELECT nome_cliente FROM clientes WHERE id_cliente = :id LIMIT 1',
                [':id' => $id]
            );

            if (!$existe) {
                self::responder_erro('Cliente não encontrado.', 404);
            }

            // Cliente com ordens de serviço no histórico não pode ser removido:
            // a FK id_cliente em `ordem` e `historico_ordems` é ON DELETE CASCADE,
            // então excluir o cliente apagaria silenciosamente todo o histórico
            // de serviços dele. Preferimos bloquear e deixar o gerente decidir.
            if (self::possui_ordens(bd: $db, id_cliente: $id)) {
                self::responder_erro(
                    'Este cliente possui ordens de serviço registradas e não pode ser removido.',
                    409
                );
            }

            $db->execute('DELETE FROM clientes WHERE id_cliente = :id', [':id' => $id]);

            Logger::registrar("Cliente \"{$existe['nome_cliente']}\" removido.");

            self::responder_json(['mensagem' => 'Cliente removido com sucesso.']);

        } catch (DatabaseException $e) {
            error_log('[ClienteGerenciaController] deletar: ' . $e->getMessage());
            self::responder_erro('Erro ao remover do banco de dados.', 500);
        }
    }

    private static function possui_ordens(Database $bd, int $id_cliente): bool
    {
        $linha = $bd->query_one(
            'SELECT 1 FROM ordem WHERE id_cliente = :id LIMIT 1',
            [':id' => $id_cliente]
        );
        return $linha !== null;
    }

    private static function formatar_linha(array $r): array
    {
        return [
            'id'       => (int) $r['id_cliente'],
            'nome'     =>       $r['nome_cliente'],
            'cpf'      =>       $r['CPF'],
            'celular'  =>       $r['celular'],
            'email'    =>       $r['email'],
            'vip'      => (bool) $r['vip'],
        ];
    }

    private static function montar_filtros(string $busca, string $vip): array
    {
        $condicoes = [];
        $params    = [];

        if ($busca !== '') {
            $condicoes[] = '(nome_cliente LIKE :busca_nome OR email LIKE :busca_email OR CPF LIKE :busca_cpf)';
            $termo_busca = '%' . $busca . '%';
            $params[':busca_nome']  = $termo_busca;
            $params[':busca_email'] = $termo_busca;
            $params[':busca_cpf']   = $termo_busca;
        }

        if ($vip === '1' || $vip === '0') {
            $condicoes[] = 'vip = :vip';
            $params[':vip'] = (int) $vip;
        }

        $where_sql = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';
        return [$where_sql, $params];
    }

    private static function validar_campos(
        string $nome,
        string $cpf,
        string $celular,
        string $email,
        string $senha,
        bool   $senha_obrigatoria
    ): ?string {
        if (strlen($nome) < 3 || strlen($nome) > 255) {
            return 'Nome completo inválido (3–255 caracteres).';
        }

        if (!self::cpf_valido($cpf)) {
            return 'CPF inválido.';
        }

        $cel_digits = preg_replace('/\D/', '', $celular);
        if (strlen($cel_digits) < 10 || strlen($cel_digits) > 11) {
            return 'Número de celular inválido.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            return 'Formato de e-mail inválido.';
        }

        if ($senha_obrigatoria && $senha === '') {
            return 'A senha é obrigatória.';
        }

        if ($senha !== '' && strlen($senha) < 8) {
            return 'A senha deve ter no mínimo 8 caracteres.';
        }

        return null;
    }

    private static function cpf_valido(string $cpf): bool
    {
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1+$/', $cpf)) {
            return false;
        }

        $calc_digit = function (string $slice, int $factor): int {
            $sum = 0;
            for ($i = 0; $i < strlen($slice); $i++) {
                $sum += (int) $slice[$i] * ($factor - $i);
            }
            $rest = ($sum * 10) % 11;
            return $rest >= 10 ? 0 : $rest;
        };

        $d1 = $calc_digit(substr($cpf, 0, 9), 10);
        $d2 = $calc_digit(substr($cpf, 0, 10), 11);

        return $d1 === (int) $cpf[9] && $d2 === (int) $cpf[10];
    }

    private static function cpf_em_uso(Database $db, string $cpf, ?int $excluir_id = null): bool
    {
        if ($excluir_id !== null) {
            $row = $db->query_one(
                'SELECT 1 FROM clientes WHERE CPF = :cpf AND id_cliente != :id LIMIT 1',
                [':cpf' => $cpf, ':id' => $excluir_id]
            );
        } else {
            $row = $db->query_one('SELECT 1 FROM clientes WHERE CPF = :cpf LIMIT 1', [':cpf' => $cpf]);
        }
        return $row !== null;
    }

    private static function email_em_uso(Database $db, string $email, ?int $excluir_id = null): bool
    {
        if ($excluir_id !== null) {
            $row = $db->query_one(
                'SELECT 1 FROM clientes WHERE email = :email AND id_cliente != :id LIMIT 1',
                [':email' => $email, ':id' => $excluir_id]
            );
        } else {
            $row = $db->query_one('SELECT 1 FROM clientes WHERE email = :email LIMIT 1', [':email' => $email]);
        }
        return $row !== null;
    }

    private static function normalizar_vip(mixed $valor): int
    {
        return $valor === true || $valor === 1 || $valor === '1' ? 1 : 0;
    }

    private static function validar_csrf(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $body         = self::ler_json_body();
        $token_sessao = $_SESSION['csrf_token'] ?? '';
        $token_body   = $body['csrf_token']     ?? '';

        if (!$token_sessao || !hash_equals($token_sessao, $token_body)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['erro' => 'Requisição inválida.']);
            exit;
        }
    }

    private static function ler_json_body(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $raw   = $GLOBALS['_test_input'] ?? file_get_contents('php://input');
        $cache = json_decode($raw ?: '{}', true) ?? [];
        return $cache;
    }

    private static function validar_int_positivo(mixed $valor): ?int
    {
        $int = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int !== false ? $int : null;
    }

    private static function responder_json(array $dados): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    }

    private static function responder_erro(string $mensagem, int $codigo): never
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['erro' => $mensagem], JSON_UNESCAPED_UNICODE);
        exit;
    }
}