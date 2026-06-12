<?php

declare(strict_types=1);

namespace Automax\Controllers;

use Automax\Config\Database;
use Automax\Config\DatabaseException;
use Automax\Auth\AccessControl;

class FornecedorController
{
    public static function listar(): void
    {
        AccessControl::exigir_permissao('estoque.visualizar');

        try {
            $db = Database::get_instance();
            $fornecedores = $db->query_all(
                'SELECT id_fornecedor, nome_fornecedor, cnpj FROM fornecedores ORDER BY nome_fornecedor ASC'
            );
            self::json(200, $fornecedores);
        } catch (DatabaseException $e) {
            error_log('[FornecedorController] listar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function criar(): void
    {
        AccessControl::exigir_permissao('estoque.editar');
        self::validar_csrf();

        $body = self::ler_body();
        if ($body === null) {
            self::json(400, ['erro' => 'Corpo inválido.']);
            return;
        }

        $nome = trim($body['nome_fornecedor'] ?? '');
        $cnpj = preg_replace('/\D/', '', $body['cnpj'] ?? '');

        $erros = self::validar_campos($nome, $cnpj);
        if (!empty($erros)) {
            self::json(422, ['erro' => implode(' ', $erros)]);
            return;
        }

        $cnpj_formatado = self::formatar_cnpj($cnpj);

        try {
            $db = Database::get_instance();

            if (self::cnpj_existe($db, $cnpj)) {
                self::json(409, ['erro' => 'CNPJ já cadastrado.']);
                return;
            }

            $id = $db->insert(
                'INSERT INTO fornecedores (nome_fornecedor, cnpj) VALUES (:nome, :cnpj)',
                [':nome' => $nome, ':cnpj' => $cnpj_formatado]
            );

            self::json(201, ['id_fornecedor' => $id, 'nome_fornecedor' => $nome, 'cnpj' => $cnpj_formatado]);
        } catch (DatabaseException $e) {
            error_log('[FornecedorController] criar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function atualizar(array $params): void
    {
        AccessControl::exigir_permissao('estoque.editar');
        self::validar_csrf();

        $id = self::validar_id($params['id'] ?? '');
        if ($id === false) {
            self::json(400, ['erro' => 'ID inválido.']);
            return;
        }

        $body = self::ler_body();
        if ($body === null) {
            self::json(400, ['erro' => 'Corpo inválido.']);
            return;
        }

        $nome = trim($body['nome_fornecedor'] ?? '');
        $cnpj = preg_replace('/\D/', '', $body['cnpj'] ?? '');

        $erros = self::validar_campos($nome, $cnpj);
        if (!empty($erros)) {
            self::json(422, ['erro' => implode(' ', $erros)]);
            return;
        }

        $cnpj_formatado = self::formatar_cnpj($cnpj);

        try {
            $db = Database::get_instance();

            if (self::cnpj_existe($db, $cnpj, $id)) {
                self::json(409, ['erro' => 'CNPJ já usado por outro fornecedor.']);
                return;
            }

            $afetados = $db->execute(
                'UPDATE fornecedores SET nome_fornecedor = :nome, cnpj = :cnpj WHERE id_fornecedor = :id',
                [':nome' => $nome, ':cnpj' => $cnpj_formatado, ':id' => $id]
            );

            if ($afetados === 0) {
                self::json(404, ['erro' => 'Fornecedor não encontrado.']);
                return;
            }

            self::json(200, ['id_fornecedor' => $id, 'nome_fornecedor' => $nome, 'cnpj' => $cnpj_formatado]);
        } catch (DatabaseException $e) {
            error_log('[FornecedorController] atualizar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function deletar(array $params): void
    {
        AccessControl::exigir_permissao('estoque.editar');
        self::validar_csrf();

        $id = self::validar_id($params['id'] ?? '');
        if ($id === false) {
            self::json(400, ['erro' => 'ID inválido.']);
            return;
        }

        try {
            $db = Database::get_instance();

            $tem_pecas = $db->query_one(
                'SELECT 1 FROM pecas WHERE id_fornecedor = :id LIMIT 1',
                [':id' => $id]
            );

            if ($tem_pecas !== null) {
                self::json(409, ['erro' => 'Fornecedor possui peças vinculadas. Remova as peças antes de excluir.']);
                return;
            }

            $afetados = $db->execute(
                'DELETE FROM fornecedores WHERE id_fornecedor = :id',
                [':id' => $id]
            );

            if ($afetados === 0) {
                self::json(404, ['erro' => 'Fornecedor não encontrado.']);
                return;
            }

            self::json(200, ['ok' => true]);
        } catch (DatabaseException $e) {
            error_log('[FornecedorController] deletar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    private static function validar_campos(string $nome, string $cnpj): array
    {
        $erros = [];
        if (empty($nome)) $erros[] = 'Nome obrigatório.';
        if (strlen($nome) > 255) $erros[] = 'Nome muito longo.';
        if (strlen($cnpj) !== 14) $erros[] = 'CNPJ deve ter 14 dígitos.';
        return $erros;
    }

    private static function cnpj_existe(object $db, string $cnpj, ?int $excluir_id = null): bool
    {
        $cnpj_formatado = self::formatar_cnpj($cnpj);
        if ($excluir_id !== null) {
            $row = $db->query_one(
                'SELECT 1 FROM fornecedores WHERE cnpj = :cnpj AND id_fornecedor != :id',
                [':cnpj' => $cnpj_formatado, ':id' => $excluir_id]
            );
        } else {
            $row = $db->query_one(
                'SELECT 1 FROM fornecedores WHERE cnpj = :cnpj',
                [':cnpj' => $cnpj_formatado]
            );
        }
        return $row !== null;
    }

    private static function formatar_cnpj(string $cnpj): string
    {
        $d = preg_replace('/\D/', '', $cnpj);
        return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d);
    }

    private static function validar_id(mixed $raw): int|false
    {
        return filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    private static function ler_body(): ?array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private static function validar_csrf(): void
    {
        $token_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $token_sessao = $_SESSION['csrf_token'] ?? '';
        if (!$token_sessao || !hash_equals($token_sessao, $token_header)) {
            self::json(403, ['erro' => 'Token inválido.']);
            exit;
        }
    }

    private static function json(int $status, mixed $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}