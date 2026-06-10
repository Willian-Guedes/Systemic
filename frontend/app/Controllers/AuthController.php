<?php

declare(strict_types=1);

namespace Automax\Controllers;

use Automax\Config\Database;
use Automax\Config\DatabaseException;

class AuthController
{
    public static function handle_login(): void
    {
        $email = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));
        $senha = trim($_POST['senha'] ?? '');

        if (empty($email) || empty($senha)) {
            self::redirect_with_error('/auth/login', 'Preencha o email e a senha.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::redirect_with_error('/auth/login', 'Formato de email invÃ¡lido.');
        }

        $funcionario = self::buscar_funcionario_por_email($email);

        $hash_dummy   = '$2y$12$invalido.hash.para.timing.constante.AAAAAAAAAAAAAAAAAAA';
        $hash_real    = $funcionario['senha'] ?? $hash_dummy;
        $senha_valida = password_verify($senha, $hash_real);

        if ($funcionario === null || !$senha_valida) {
            self::redirect_with_error('/auth/login', 'Email ou senha incorretos.');
        }

        self::iniciar_sessao_autenticada($funcionario);

        if (PHP_SAPI === 'cli') {
            return;
        }

        header('Location: /ordem-servico');
        exit;
    }

    public static function handle_logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];
        session_destroy();

        if (PHP_SAPI === 'cli') {
            return;
        }

        header('Location: /auth/login');
        exit;
    }

    public static function exigir_autenticacao(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['funcionario_id'])) {
            if (PHP_SAPI === 'cli') {
                return;
            }

            header('Location: /auth/login');
            exit;
        }
    }

    public static function validate_csrf_token(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token_sessao = $_SESSION['csrf_token'] ?? '';
        $token_post   = $_POST['csrf_token']    ?? '';

        if (!$token_sessao || !hash_equals($token_sessao, $token_post)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Requisição inválida.';
            exit;
        }
    }

    private static function buscar_funcionario_por_email(string $email): ?array
    {
        $db = Database::get_instance();

        return $db->query_one(
            'SELECT id_funcionario, nome_funcionario, nivel_de_acesso, senha
               FROM funcionarios
              WHERE email = :email
              LIMIT 1',
            [':email' => $email]
        );
    }

    private static function iniciar_sessao_autenticada(array $funcionario): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_secure'   => true,
                'cookie_samesite' => 'Strict',
            ]);
        }

        session_regenerate_id(true);

        $_SESSION['funcionario_id']   = $funcionario['id_funcionario'];
        $_SESSION['funcionario_nome'] = $funcionario['nome_funcionario'];
        $_SESSION['nivel_de_acesso']  = $funcionario['nivel_de_acesso'];
        $_SESSION['autenticado_em']    = time();
        $_SESSION['csrf_token']        = bin2hex(random_bytes(32));
    }

    private static function redirect_with_error(string $destino, string $mensagem): never
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['flash_error'] = $mensagem;

        if (PHP_SAPI === 'cli') {
            throw new \RuntimeException("redirect:{$destino}");
        }

        header("Location: {$destino}");
        exit;
    }
}