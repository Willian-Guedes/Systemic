<?php

declare(strict_types=1);

namespace Automax\Controllers;

use Automax\Config\Database;
use Automax\Config\DatabaseException;

class CadastroController
{
    public static function handle_page(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        $safe_token = json_encode($_SESSION['csrf_token'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<base href="/pages/cadastro/">';
        echo "<script>window.__csrf_token = {$safe_token};</script>";
        include __DIR__ . '/../../pages/cadastro/cadastro.html';
    }

    public static function handle_criar(): void
    {
        $body = self::read_json_body();

        if ($body === null) {
            self::respond(400, 'Corpo da requisiÃ§Ã£o invÃ¡lido ou ausente.');
            return;
        }

        $cliente_raw = $body['cliente'] ?? [];
        $veiculo_raw = $body['veiculo'] ?? [];

        $cliente_errors = self::validate_cliente($cliente_raw);
        $veiculo_errors = self::validate_veiculo($veiculo_raw);

        $all_errors = array_merge($cliente_errors, $veiculo_errors);
        if (!empty($all_errors)) {
            self::respond(422, implode(' ', $all_errors), ['fields' => $all_errors]);
            return;
        }

        $nome_cliente = trim($cliente_raw['nome_cliente']);
        $cpf          = preg_replace('/\D/', '', $cliente_raw['cpf']);
        $celular      = trim($cliente_raw['celular']);
        $email        = strtolower(trim($cliente_raw['email']));
        $senha        = $cliente_raw['senha'];

        $marca  = trim($veiculo_raw['marca']);
        $modelo = trim($veiculo_raw['modelo']);
        $ano    = trim($veiculo_raw['ano']);
        $cor    = trim($veiculo_raw['cor']);
        $placa  = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $veiculo_raw['placa']));

        try {
            $db = Database::get_instance();
        } catch (DatabaseException $e) {
            error_log('[CadastroController] DB connection error: ' . $e->getMessage());
            self::respond(503, 'ServiÃ§o temporariamente indisponÃ­vel. Tente novamente em instantes.');
            return;
        }

        if (self::cpf_ja_cadastrado($db, $cpf)) {
            self::respond(409, 'Este CPF jÃ¡ estÃ¡ cadastrado. Tente fazer login.');
            return;
        }

        if (self::email_ja_cadastrado($db, $email)) {
            self::respond(409, 'Este e-mail jÃ¡ estÃ¡ em uso. Tente fazer login ou recuperar a senha.');
            return;
        }

        if (self::placa_ja_cadastrada($db, $placa)) {
            self::respond(409, 'Esta placa jÃ¡ estÃ¡ cadastrada no sistema.');
            return;
        }

        $senha_hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);

        try {
            $db->begin_transaction();

            $db->execute(
                'INSERT INTO clientes (nome_cliente, CPF, celular, email, senha)
                 VALUES (:nome, :cpf, :celular, :email, :senha)',
                [
                    ':nome'    => $nome_cliente,
                    ':cpf'     => $cpf,
                    ':celular' => $celular,
                    ':email'   => $email,
                    ':senha'   => $senha_hash,
                ]
            );

            $id_cliente = (int) $db->last_insert_id();

            $db->execute(
                'INSERT INTO veiculos (marca, cor, ano, modelo, placa, id_cliente)
                 VALUES (:marca, :cor, :ano, :modelo, :placa, :id_cliente)',
                [
                    ':marca'      => $marca,
                    ':cor'        => $cor,
                    ':ano'        => $ano,
                    ':modelo'     => $modelo,
                    ':placa'      => $placa,
                    ':id_cliente' => $id_cliente,
                ]
            );

            $db->commit();

        } catch (\PDOException $e) {
            $db->rollback();

            if (self::is_duplicate_entry($e)) {
                self::respond(409, 'CPF, e-mail ou placa jÃ¡ cadastrado. Tente fazer login.');
                return;
            }

            error_log('[CadastroController] Transaction error: ' . $e->getMessage());
            self::respond(500, 'Erro ao criar a conta. Tente novamente.');
            return;
        }

        self::respond(201, 'Cadastro realizado com sucesso.', [
            'id_cliente' => $id_cliente,
        ]);
    }

    private static function validate_cliente(array $data): array
    {
        $errors = [];

        $nome = trim($data['nome_cliente'] ?? '');
        if (strlen($nome) < 3 || strlen($nome) > 255) {
            $errors[] = 'Nome completo invÃ¡lido (3â€“255 caracteres).';
        }

        $cpf = preg_replace('/\D/', '', $data['cpf'] ?? '');
        if (!self::cpf_valido($cpf)) {
            $errors[] = 'CPF invÃ¡lido.';
        }

        $celular    = trim($data['celular'] ?? '');
        $cel_digits = preg_replace('/\D/', '', $celular);
        if (strlen($cel_digits) < 10 || strlen($cel_digits) > 11) {
            $errors[] = 'NÃºmero de celular invÃ¡lido.';
        }

        $email = trim($data['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $errors[] = 'E-mail invÃ¡lido.';
        }

        $senha = $data['senha'] ?? '';
        if (strlen($senha) < 8) {
            $errors[] = 'A senha deve ter no mÃ­nimo 8 caracteres.';
        }

        return $errors;
    }

    private static function validate_veiculo(array $data): array
    {
        $errors = [];

        $marca = trim($data['marca'] ?? '');
        if (strlen($marca) < 2 || strlen($marca) > 100) {
            $errors[] = 'Marca do veÃ­culo invÃ¡lida.';
        }

        $modelo = trim($data['modelo'] ?? '');
        if (strlen($modelo) < 2 || strlen($modelo) > 100) {
            $errors[] = 'Modelo do veÃ­culo invÃ¡lido.';
        }

        $ano = trim($data['ano'] ?? '');
        if (!preg_match('/^(19|20)\d{2}(\/\d{2,4})?$/', $ano)) {
            $errors[] = 'Ano do veÃ­culo invÃ¡lido.';
        }

        $cor = trim($data['cor'] ?? '');
        if (strlen($cor) < 2 || strlen($cor) > 50) {
            $errors[] = 'Cor do veÃ­culo invÃ¡lida.';
        }

        $placa = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $data['placa'] ?? ''));
        if (!self::placa_valida($placa)) {
            $errors[] = 'Placa invÃ¡lida. Use o formato ABC-1234 ou ABC1D23.';
        }

        return $errors;
    }

    private static function cpf_valido(string $cpf): bool
    {
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1+$/', $cpf)) {
            return false;
        }

        $calc_digit = function (string $slice, int $factor): int {
            $sum = 0;
            for ($i = 0; $i < strlen($slice); $i++) {
                $sum += (int)$slice[$i] * ($factor - $i);
            }
            $rest = ($sum * 10) % 11;
            return $rest >= 10 ? 0 : $rest;
        };

        $d1 = $calc_digit(substr($cpf, 0, 9), 10);
        $d2 = $calc_digit(substr($cpf, 0, 10), 11);

        return $d1 === (int)$cpf[9] && $d2 === (int)$cpf[10];
    }

    private static function placa_valida(string $placa): bool
    {
        $old_format      = '/^[A-Z]{3}\d{4}$/';
        $mercosul_format = '/^[A-Z]{3}\d[A-Z]\d{2}$/';
        return preg_match($old_format, $placa) || preg_match($mercosul_format, $placa);
    }

    private static function cpf_ja_cadastrado(Database $db, string $cpf): bool
    {
        return $db->query_one(
            'SELECT 1 FROM clientes WHERE CPF = :cpf LIMIT 1',
            [':cpf' => $cpf]
        ) !== null;
    }

    private static function email_ja_cadastrado(Database $db, string $email): bool
    {
        return $db->query_one(
            'SELECT 1 FROM clientes WHERE email = :email LIMIT 1',
            [':email' => $email]
        ) !== null;
    }

    private static function placa_ja_cadastrada(Database $db, string $placa): bool
    {
        return $db->query_one(
            'SELECT 1 FROM veiculos WHERE placa = :placa LIMIT 1',
            [':placa' => $placa]
        ) !== null;
    }

    private static function read_json_body(): ?array
    {
        $raw = $GLOBALS['_test_input'] ?? file_get_contents('php://input');
        if (empty($raw)) return null;

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
    
    private static function is_duplicate_entry(\PDOException $e): bool
    {
        return str_starts_with($e->getCode(), '23');
    }

    private static function respond(int $code, string $message, array $extra = []): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(
            array_merge(['message' => $message], $extra),
            JSON_UNESCAPED_UNICODE
        );
    }
}