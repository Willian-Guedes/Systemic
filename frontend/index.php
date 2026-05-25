<?php

declare(strict_types=1);

require_once './libs/router.php';
require_once './auth_controller.php';

$router = new Router(__DIR__);

// ── Helpers ───────────────────────────────────────────────────────────────

/*
 * Serve uma página HTML com o <base href> correto.
 * Centraliza o padrão repetido em todas as rotas.
 */
function serve_page(string $base_href, string $file_path): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<base href="' . $base_href . '">';
    include $file_path;
}

/*
 * Gera as iniciais a partir do nome completo do funcionário.
 * "Administrador Automax" → "AA" | "Jonas Pereira" → "JP" | "Maria" → "M"
 */
function build_user_initials(string $nome): string
{
    $words = array_values(array_filter(explode(' ', $nome)));
    $first = mb_substr($words[0] ?? '', 0, 1, 'UTF-8');
    $last  = mb_substr(end($words) ?: '', 0, 1, 'UTF-8');
    return mb_strtoupper($first !== $last ? $first . $last : $first, 'UTF-8');
}

/*
 * Serve uma página protegida injetando os dados do usuário logado como
 * window.__session_user, para que o JS preencha o sidebar corretamente.
 *
 * Sempre chamar no lugar de serve_page() em rotas que exigem autenticação bem legal.
 */
function serve_protected_page(string $base_href, string $file_path): void
{
    AuthController::exigir_autenticacao();

    $user_data = [
        'nome'     => $_SESSION['funcionario_nome'] ?? '',
        'nivel'    => $_SESSION['nivel_de_acesso']  ?? '',
        'iniciais' => build_user_initials($_SESSION['funcionario_nome'] ?? ''),
    ];

    $safe_json = json_encode($user_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<base href="' . $base_href . '">';
    echo "<script>window.__session_user = {$safe_json};</script>";
    include $file_path;
}

/*
 * Serve a página de login injetando o flash de erro da sessão, se houver.
 *
 * O login.html escuta window.__flash_error via JS. Usar json_encode() com
 * as flags HEX garante que qualquer caractere especial na mensagem não
 * quebre o contexto <script> — prevenindo XSS mesmo que a mensagem tenha
 * aspas, barras ou tags.
 */
function serve_login_page(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $flash_error = $_SESSION['flash_error'] ?? null;
    unset($_SESSION['flash_error']);

    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<base href="/pages/login/">';

    if ($flash_error !== null) {
        $safe_json = json_encode($flash_error, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        echo "<script>window.__flash_error = {$safe_json};</script>";
    }

    include __DIR__ . '/pages/login/login.html';
}

// ── Rotas públicas ────────────────────────────────────────────────────────

$router->get('/', function () {
    serve_page('/pages/homepage/', __DIR__ . '/pages/homepage/index.html');
});

$router->get('/auth/login', function () {
    serve_login_page();
});

$router->post('/auth/login', function () {
    AuthController::handle_login();
});

$router->post('/auth/logout', function () {
    AuthController::handle_logout();
});

// ── Rotas protegidas (exigem sessão ativa) ────────────────────────────────

$router->get('/produtos', function () {
    serve_protected_page('/pages/produto/', __DIR__ . '/pages/produto/produto.html');
});

$router->get('/produto/:id', function () {
    serve_protected_page('/pages/produto/', __DIR__ . '/pages/produto/produto.html');
});

$router->get('/ordem-servico', function () {
    serve_protected_page('/pages/ordem-servico/', __DIR__ . '/pages/ordem-servico/automax-os.html');
});

// ── Placeholders ──────────────────────────────────────────────────────────

foreach (['/servicos', '/pedir', '/cadastro', '/busca'] as $rota) {
    $router->get($rota, function () use ($rota) {
        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        echo "<h2 style='font-family:sans-serif;padding:2rem'>Página <code>{$rota}</code> em construção.</h2>";
    });
}

// ── Dispatch ──────────────────────────────────────────────────────────────

try {
    $router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
} catch (Exception $e) {
    http_response_code(500);
    echo $e->getMessage();
}