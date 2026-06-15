<?php

declare(strict_types=1);

require_once '/var/www/html/vendor/autoload.php';

use Automax\Http\Router;
use Automax\Auth\AccessControl;
use Automax\Controllers\AuthController;
use Automax\Controllers\CadastroController;
use Automax\Controllers\ProdutoController;
use Automax\Controllers\FornecedorController;
use Automax\Controllers\EstoqueController;
use Automax\Controllers\FuncionariosController;
use Automax\Controllers\ProdutoNotFoundException;
use Automax\Config\DatabaseException;

$router = new Router(__DIR__);

function serve_page(string $base_href, string $file_path): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data: https://placehold.co; connect-src 'self' https://cdn.jsdelivr.net");
    echo '<base href="' . $base_href . '">';
    include $file_path;
}

function build_user_initials(string $nome): string
{
    $words = array_values(array_filter(explode(' ', $nome)));
    $first = mb_substr($words[0] ?? '', 0, 1, 'UTF-8');
    $last  = mb_substr(end($words) ?: '', 0, 1, 'UTF-8');
    return mb_strtoupper($first !== $last ? $first . $last : $first, 'UTF-8');
}

function serve_protected_page(string $base_href, string $file_path): void
{
    AuthController::exigir_autenticacao();

    $nivel = $_SESSION['nivel_de_acesso'] ?? '';

    $user_data = [
        'nome'       => $_SESSION['funcionario_nome'] ?? '',
        'nivel'      => $nivel,
        'iniciais'   => build_user_initials($_SESSION['funcionario_nome'] ?? ''),
        'permissoes' => AccessControl::permissoes_do_nivel($nivel),
        'csrf_token' => $_SESSION['csrf_token'] ?? '',
    ];

    $safe_json = json_encode($user_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data: https://placehold.co; connect-src 'self' https://cdn.jsdelivr.net");
    echo '<base href="' . $base_href . '">';
    echo "<script>window.__session_user = {$safe_json};</script>";
    include $file_path;
}

function serve_login_page(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $flash_error = $_SESSION['flash_error'] ?? null;
    unset($_SESSION['flash_error']);

    $safe_token = json_encode($_SESSION['csrf_token'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<base href="/pages/login/">';
    echo "<script>window.__csrf_token = {$safe_token};</script>";

    if ($flash_error !== null) {
        $safe_json = json_encode($flash_error, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        echo "<script>window.__flash_error = {$safe_json};</script>";
    }

    include __DIR__ . '/pages/login/login.html';
}

// Rotas públicas

$router->get('/', function () {
    serve_page('/pages/homepage/', __DIR__ . '/pages/homepage/index.html');
});

$router->get('/auth/login', function () {
    serve_login_page();
});

$router->post('/auth/login', function () {
    AuthController::validate_csrf_token();
    AuthController::handle_login();
});

$router->post('/auth/logout', function () {
    AuthController::validate_csrf_token();
    AuthController::handle_logout();
});

// Rotas protegidas

$router->get('/produtos', function () {
    serve_protected_page('/pages/produtos/', __DIR__ . '/pages/produtos/produtos.html');
});

$router->get('/produto/:id', function (array $params) {
    AuthController::exigir_autenticacao();

    $id_produto = filter_var($params['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id_produto === false) {
        http_response_code(400);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<p style="font-family:sans-serif;padding:2rem">ID de produto inválido.</p>';
        return;
    }

    $safe_id   = json_encode($id_produto);
    $safe_user = json_encode([
        'nome'       => $_SESSION['funcionario_nome'] ?? '',
        'nivel'      => $_SESSION['nivel_de_acesso']  ?? '',
        'iniciais'   => build_user_initials($_SESSION['funcionario_nome'] ?? ''),
        'permissoes' => AccessControl::permissoes_do_nivel($_SESSION['nivel_de_acesso'] ?? ''),
        'csrf_token' => $_SESSION['csrf_token'] ?? '',
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data: https://placehold.co; connect-src 'self' https://cdn.jsdelivr.net");
    echo '<base href="/pages/produto/">';
    echo "<script>window.__produto_id = {$safe_id}; window.__session_user = {$safe_user};</script>";
    include __DIR__ . '/pages/produto/produto.html';
});

$router->get('/ordem-servico', function () {
    AccessControl::exigir_permissao('ordem_servico.visualizar');
    serve_protected_page('/pages/ordem-servico/', __DIR__ . '/pages/ordem-servico/automax-os.html');
});

$router->get('/fornecedores', function () {
    AccessControl::exigir_permissao('estoque.visualizar');
    serve_protected_page('/pages/fornecedores/', __DIR__ . '/pages/fornecedores/fornecedores.html');
});

$router->get('/funcionarios', function () {
    AccessControl::exigir_permissao('funcionarios.visualizar');
    serve_protected_page('/pages/funcionarios/', __DIR__ . '/pages/funcionarios/funcionarios.html');
});

$router->get('/estoque', function () {
    AccessControl::exigir_permissao('estoque.visualizar');
    serve_protected_page('/pages/estoque/', __DIR__ . '/pages/estoque/estoque.html');
});

// API de produtos

$router->get('/api/produto', function () {
    include __DIR__ . '/api/produto.php';
});

$router->get('/api/produtos', function () {
    include __DIR__ . '/api/produtos.php';
});

// API de estoque (CRUD de produtos para gestão interna)

$router->get('/api/estoque', function () {
    EstoqueController::listar();
});

$router->post('/api/estoque', function () {
    EstoqueController::criar();
});

$router->get('/api/estoque/:id', function (array $params) {
    EstoqueController::buscar($params);
});

$router->put('/api/estoque/:id', function (array $params) {
    EstoqueController::atualizar($params);
});

$router->patch('/api/estoque/:id/stock', function (array $params) {
    EstoqueController::ajustar_stock($params);
});

$router->delete('/api/estoque/:id', function (array $params) {
    EstoqueController::deletar($params);
});

// API de fornecedores

$router->get('/api/fornecedores', function () {
    FornecedorController::listar();
});

$router->post('/api/fornecedores', function () {
    FornecedorController::criar();
});

$router->patch('/api/fornecedores/:id', function (array $params) {
    FornecedorController::atualizar($params);
});

$router->delete('/api/fornecedores/:id', function (array $params) {
    FornecedorController::deletar($params);
});

// API de funcionários

$router->get('/api/funcionarios', function () {
    FuncionariosController::listar();
});

$router->get('/api/funcionarios/:id', function (array $params) {
    FuncionariosController::buscar($params);
});

$router->post('/api/funcionarios', function () {
    FuncionariosController::criar();
});

$router->put('/api/funcionarios/:id', function (array $params) {
    FuncionariosController::atualizar($params);
});

$router->delete('/api/funcionarios/:id', function (array $params) {
    FuncionariosController::deletar($params);
});

// Rotas de cadastro

$router->get('/cadastro', function () {
    CadastroController::handle_page();
});

$router->post('/cadastro/criar', function () {
    AuthController::validate_csrf_token();
    CadastroController::handle_criar();
});

foreach (['/servicos', '/pedir'] as $rota) {
    $router->get($rota, function () use ($rota) {
        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        echo "<h2 style='font-family:sans-serif;padding:2rem'>Página <code>{$rota}</code> em construção.</h2>";
    });
}

$router->get('/busca', function () {
    serve_page('/pages/busca/', __DIR__ . '/pages/busca/busca.html');
});

$router->get('/api/busca', function () {
    include __DIR__ . '/api/busca.php';
});

// Dispatch

try {
    $router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
} catch (Exception $e) {
    http_response_code(500);
    echo $e->getMessage();
}