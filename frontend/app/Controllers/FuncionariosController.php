<?php

declare(strict_types=1);

namespace Automax\Controllers;

use Automax\Config\Database;
use Automax\Config\DatabaseException;
use Automax\Auth\AccessControl;
use Automax\Support\Logger;

class FuncionariosController
{
    private const NIVEIS_VALIDOS = ['gerente', 'mecanico', 'recepcao'];
    private const POR_PAGINA     = 15;

    public static function listar(): void
    {
        AccessControl::exigir_permissao('funcionarios.visualizar');

        $pagina = self::validar_int_positivo($_GET['pagina'] ?? '1') ?: 1;
        $busca  = trim($_GET['busca']  ?? '');
        $nivel  = trim($_GET['nivel']  ?? '');

        if ($nivel !== '' && !in_array($nivel, self::NIVEIS_VALIDOS, true)) {
            $nivel = '';
        }

        try {
            $db     = Database::get_instance();
            $offset = ($pagina - 1) * self::POR_PAGINA;

            [$where_sql, $params_base] = self::montar_filtros($busca, $nivel);

            $total = (int) ($db->query_one(
                "SELECT COUNT(*) AS total FROM funcionarios {$where_sql}",
                $params_base
            )['total'] ?? 0);

            $linhas = $db->query(
                "SELECT id_funcionario, nome_funcionario, email, nivel_de_acesso
                   FROM funcionarios
                 {$where_sql}
                  ORDER BY nome_funcionario ASC
                  LIMIT :limite OFFSET :offset",
                array_merge($params_base, [':limite' => self::POR_PAGINA, ':offset' => $offset])
            );

            $funcionarios = array_map(fn(array $r): array => [
                'id'    => (int) $r['id_funcionario'],
                'nome'  =>       $r['nome_funcionario'],
                'email' =>       $r['email'],
                'nivel' =>       $r['nivel_de_acesso'],
            ], $linhas);

            self::responder_json([
                'funcionarios'  => $funcionarios,
                'total'         => $total,
                'pagina'        => $pagina,
                'total_paginas' => max(1, (int) ceil($total / self::POR_PAGINA)),
            ]);

        } catch (DatabaseException $e) {
            self::responder_erro('Erro ao consultar banco de dados.', 500);
        }
    }

    public static function buscar(array $params): void
    {
        AccessControl::exigir_permissao('funcionarios.visualizar');

        $id = self::validar_int_positivo($params['id'] ?? '');
        if ($id === null) {
            self::responder_erro('ID inválido.', 400);
        }

        try {
            $db   = Database::get_instance();
            $linha = $db->query_one(
                'SELECT id_funcionario, nome_funcionario, email, nivel_de_acesso
                   FROM funcionarios
                  WHERE id_funcionario = :id
                  LIMIT 1',
                [':id' => $id]
            );

            if (!$linha) {
                self::responder_erro('Funcionário não encontrado.', 404);
            }

            self::responder_json([
                'id'    => (int) $linha['id_funcionario'],
                'nome'  =>       $linha['nome_funcionario'],
                'email' =>       $linha['email'],
                'nivel' =>       $linha['nivel_de_acesso'],
            ]);

        } catch (DatabaseException $e) {
            self::responder_erro('Erro ao consultar banco de dados.', 500);
        }
    }

    public static function criar(): void
    {
        AccessControl::exigir_permissao('funcionarios.gerenciar');
        self::validar_csrf();

        $body = self::ler_json_body();

        $nome  = trim($body['nome']  ?? '');
        $email = trim($body['email'] ?? '');
        $nivel = trim($body['nivel'] ?? '');
        $senha = trim($body['senha'] ?? '');

        $erro = self::validar_campos($nome, $email, $nivel, $senha, true);
        if ($erro) {
            self::responder_erro($erro, 422);
        }

        try {
            $db = Database::get_instance();

            $existe = $db->query_one(
                'SELECT id_funcionario FROM funcionarios WHERE email = :email LIMIT 1',
                [':email' => $email]
            );

            if ($existe) {
                self::responder_erro('Já existe um funcionário com este e-mail.', 409);
            }

            $hash = password_hash($senha, PASSWORD_BCRYPT);

            $db->execute(
                'INSERT INTO funcionarios (nome_funcionario, email, nivel_de_acesso, senha)
                 VALUES (:nome, :email, :nivel, :senha)',
                [':nome' => $nome, ':email' => $email, ':nivel' => $nivel, ':senha' => $hash]
            );

            Logger::registrar("Funcionário \"{$nome}\" cadastrado — nível: {$nivel}.");

            http_response_code(201);
            self::responder_json(['mensagem' => 'Funcionário criado com sucesso.']);

        } catch (DatabaseException $e) {
            self::responder_erro('Erro ao salvar no banco de dados.', 500);
        }
    }

    public static function atualizar(array $params): void
    {
        AccessControl::exigir_permissao('funcionarios.gerenciar');
        self::validar_csrf();

        $id = self::validar_int_positivo($params['id'] ?? '');
        if ($id === null) {
            self::responder_erro('ID inválido.', 400);
        }

        $body = self::ler_json_body();

        $nome  = trim($body['nome']  ?? '');
        $email = trim($body['email'] ?? '');
        $nivel = trim($body['nivel'] ?? '');
        $senha = trim($body['senha'] ?? '');

        $erro = self::validar_campos($nome, $email, $nivel, $senha, false);
        if ($erro) {
            self::responder_erro($erro, 422);
        }

        try {
            $db = Database::get_instance();

            $existe = $db->query_one(
                'SELECT id_funcionario FROM funcionarios WHERE id_funcionario = :id LIMIT 1',
                [':id' => $id]
            );

            if (!$existe) {
                self::responder_erro('Funcionário não encontrado.', 404);
            }

            $email_duplicado = $db->query_one(
                'SELECT id_funcionario FROM funcionarios WHERE email = :email AND id_funcionario != :id LIMIT 1',
                [':email' => $email, ':id' => $id]
            );

            if ($email_duplicado) {
                self::responder_erro('Este e-mail já está em uso por outro funcionário.', 409);
            }

            if ($senha !== '') {
                $hash = password_hash($senha, PASSWORD_BCRYPT);
                $db->execute(
                    'UPDATE funcionarios
                        SET nome_funcionario = :nome, email = :email, nivel_de_acesso = :nivel, senha = :senha
                      WHERE id_funcionario = :id',
                    [':nome' => $nome, ':email' => $email, ':nivel' => $nivel, ':senha' => $hash, ':id' => $id]
                );
            } else {
                $db->execute(
                    'UPDATE funcionarios
                        SET nome_funcionario = :nome, email = :email, nivel_de_acesso = :nivel
                      WHERE id_funcionario = :id',
                    [':nome' => $nome, ':email' => $email, ':nivel' => $nivel, ':id' => $id]
                );
            }

            self::responder_json(['mensagem' => 'Funcionário atualizado com sucesso.']);

            Logger::registrar("Funcionário \"{$nome}\" atualizado — nível: {$nivel}.");

        } catch (DatabaseException $e) {
            self::responder_erro('Erro ao atualizar no banco de dados.', 500);
        }
    }

    public static function deletar(array $params): void
    {
        AccessControl::exigir_permissao('funcionarios.gerenciar');
        self::validar_csrf();

        $id = self::validar_int_positivo($params['id'] ?? '');
        if ($id === null) {
            self::responder_erro('ID inválido.', 400);
        }

        $id_sessao = (int) ($_SESSION['funcionario_id'] ?? 0);
        if ($id === $id_sessao) {
            self::responder_erro('Você não pode remover a sua própria conta.', 403);
        }

        try {
            $db = Database::get_instance();

            $existe = $db->query_one(
                'SELECT nome_funcionario FROM funcionarios WHERE id_funcionario = :id LIMIT 1',
                [':id' => $id]
            );

            if (!$existe) {
                self::responder_erro('Funcionário não encontrado.', 404);
            }

            $db->execute(
                'DELETE FROM funcionarios WHERE id_funcionario = :id',
                [':id' => $id]
            );

            Logger::registrar("Funcionário \"{$existe['nome_funcionario']}\" removido.");

            self::responder_json(['mensagem' => 'Funcionário removido com sucesso.']);

        } catch (DatabaseException $e) {
            self::responder_erro('Erro ao remover do banco de dados.', 500);
        }
    }

    private static function montar_filtros(string $busca, string $nivel): array
    {
        $condicoes = [];
        $params    = [];

        if ($busca !== '') {
            $condicoes[] = '(nome_funcionario LIKE :busca_nome OR email LIKE :busca_email)';
            $termo_busca = '%' . $busca . '%';
            $params[':busca_nome']  = $termo_busca;
            $params[':busca_email'] = $termo_busca;
        }
        
        if ($nivel !== '') {
            $condicoes[] = 'nivel_de_acesso = :nivel';
            $params[':nivel'] = $nivel;
        }

        $where_sql = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';
        return [$where_sql, $params];
    }

    private static function validar_campos(
        string $nome,
        string $email,
        string $nivel,
        string $senha,
        bool   $senha_obrigatoria
    ): ?string {
        if ($nome === '')  return 'O nome é obrigatório.';
        if ($email === '') return 'O e-mail é obrigatório.';
        if ($nivel === '') return 'O nível de acesso é obrigatório.';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Formato de e-mail inválido.';
        }

        if (!in_array($nivel, self::NIVEIS_VALIDOS, true)) {
            return 'Nível de acesso inválido.';
        }

        if ($senha_obrigatoria && $senha === '') {
            return 'A senha é obrigatória.';
        }

        if ($senha !== '' && strlen($senha) < 8) {
            return 'A senha deve ter no mínimo 8 caracteres.';
        }

        return null;
    }

    private static function validar_csrf(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $body         = self::ler_json_body();
        $token_sessao = $_SESSION['csrf_token']   ?? '';
        $token_body   = $body['csrf_token']        ?? '';

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

        $raw   = file_get_contents('php://input');
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
