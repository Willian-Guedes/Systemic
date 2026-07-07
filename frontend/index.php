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
use Automax\Controllers\ClienteController;
use Automax\Controllers\AgendamentoController;
use Automax\Controllers\AgendamentoGerenciaController;
use Automax\Controllers\LogsController;
use Automax\Controllers\OrdemController;
use Automax\Controllers\ProdutoNotFoundException;
use Automax\Config\DatabaseException;

$router = new Router(__DIR__);

function serve_page(string $base_href, string $file_path): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data: blob: https://placehold.co; connect-src 'self' https://cdn.jsdelivr.net");
    echo '<base href="' . $base_href . '">';
    include $file_path;
}

function serve_page_with_optional_session(string $base_href, string $file_path): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_secure'   => true,
            'cookie_samesite' => 'Strict',
        ]);
    }

    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data: blob: https://placehold.co; connect-src 'self' https://cdn.jsdelivr.net");

    echo '<base href="' . $base_href . '">';

    $autenticado = !empty($_SESSION['tipo_usuario']) && !empty($_SESSION['cliente_id']);

    if ($autenticado) {
        $user_data = [
            'tipo'       => 'cliente',
            'nome'       => $_SESSION['cliente_nome'] ?? '',
            'iniciais'   => build_user_initials($_SESSION['cliente_nome'] ?? ''),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
        ];
        $safe_json = json_encode($user_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        echo "<script>window.__session_user = {$safe_json};</script>";
    } else {
        echo "<script>window.__session_user = null;</script>";
    }

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

    $tipo = $_SESSION['tipo_usuario'] ?? 'funcionario';

    if ($tipo === 'cliente') {
        $user_data = [
            'tipo'       => 'cliente',
            'nome'       => $_SESSION['cliente_nome'] ?? '',
            'email'      => $_SESSION['cliente_email'] ?? '',
            'iniciais'   => build_user_initials($_SESSION['cliente_nome'] ?? ''),
            'permissoes' => AccessControl::permissoes_do_nivel('cliente'),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
        ];
    } else {
        $nivel = $_SESSION['nivel_de_acesso'] ?? '';
        $user_data = [
            'tipo'       => 'funcionario',
            'nome'       => $_SESSION['funcionario_nome'] ?? '',
            'nivel'      => $nivel,
            'iniciais'   => build_user_initials($_SESSION['funcionario_nome'] ?? ''),
            'permissoes' => AccessControl::permissoes_do_nivel($nivel),
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
        ];
    }

    $safe_json = json_encode($user_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data: blob: https://placehold.co; connect-src 'self' https://cdn.jsdelivr.net");
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
    serve_page_with_optional_session('/pages/homepage/', __DIR__ . '/pages/homepage/index.html');
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

$router->get('/busca', function () {
    serve_page('/pages/busca/', __DIR__ . '/pages/busca/busca.html');
});

$router->get('/servicos', function () {
    serve_page('/pages/servicos/', __DIR__ . '/pages/servicos/servicos.html');
});

$router->get('/cadastro', function () {
    CadastroController::handle_page();
});

$router->post('/cadastro/criar', function () {
    AuthController::validate_csrf_token();
    CadastroController::handle_criar();
});

$router->get('/logs', function () {
    AccessControl::exigir_permissao('logs.visualizar');
    serve_protected_page('/pages/logs/', __DIR__ . '/pages/logs/logs.html');
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

    $tipo  = $_SESSION['tipo_usuario'] ?? 'funcionario';
    $nome  = $tipo === 'cliente' ? ($_SESSION['cliente_nome'] ?? '') : ($_SESSION['funcionario_nome'] ?? '');
    $nivel = $_SESSION['nivel_de_acesso'] ?? ($tipo === 'cliente' ? 'cliente' : '');

    $safe_id   = json_encode($id_produto);
    $safe_user = json_encode([
        'tipo'       => $tipo,
        'nome'       => $nome,
        'nivel'      => $nivel,
        'iniciais'   => build_user_initials($nome),
        'permissoes' => AccessControl::permissoes_do_nivel($nivel),
        'csrf_token' => $_SESSION['csrf_token'] ?? '',
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data: blob: https://placehold.co; connect-src 'self' https://cdn.jsdelivr.net");
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

$router->get('/estoque', function () {
    AccessControl::exigir_permissao('estoque.visualizar');
    serve_protected_page('/pages/estoque/', __DIR__ . '/pages/estoque/estoque.html');
});

$router->get('/funcionarios', function () {
    AccessControl::exigir_permissao('funcionarios.visualizar');
    serve_protected_page('/pages/funcionarios/', __DIR__ . '/pages/funcionarios/funcionarios.html');
});

$router->get('/agendamentos', function () {
    AccessControl::exigir_permissao('agendamentos.visualizar');
    serve_protected_page('/pages/agendamentos/', __DIR__ . '/pages/agendamentos/agendamentos.html');
});

$router->get('/painel', function () {
    AccessControl::exigir_cliente();
    serve_protected_page('/pages/painel/', __DIR__ . '/pages/painel/painel.html');
});

$router->get('/pedir', function () {
    AccessControl::exigir_cliente();
    serve_protected_page('/pages/pedir/', __DIR__ . '/pages/pedir/pedir.html');
});

$router->get('/carrinho', function () {
    AccessControl::exigir_cliente();
    serve_protected_page('/pages/carrinho/', __DIR__ . '/pages/carrinho/carrinho.html');
});
 


// API de ordens de serviço

$router->get('/api/ordens', function () {
    OrdemController::listar();
});

$router->post('/api/ordens', function () {
    OrdemController::criar();
});

$router->patch('/api/ordens/:id', function (array $params) {
    OrdemController::atualizar($params);
});

$router->patch('/api/ordens/:id/fechar', function (array $params) {
    OrdemController::fechar($params);
});

$router->delete('/api/ordens/:id', function (array $params) {
    OrdemController::deletar($params);
});

$router->get('/api/ordem/suporte', function () {
    include __DIR__ . '/api/ordem_suporte.php';
});

// API de catálogo da Flowgate (proxy)

$router->get('/api/flowgate/pecas', function () {
    include __DIR__ . '/api/flowgate_pecas.php';
});

// API de produtos

$router->get('/api/produto', function () {
    include __DIR__ . '/api/produto.php';
});

$router->get('/api/produtos', function () {
    include __DIR__ . '/api/produtos.php';
});

$router->get('/api/busca', function () {
    include __DIR__ . '/api/busca.php';
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

// API de estoque

$router->get('/api/estoque', function () {
    EstoqueController::listar();
});

$router->post('/api/estoque/imagem', function () {
    EstoqueController::imagem_upload();
});

$router->get('/api/estoque/:id', function (array $params) {
    EstoqueController::buscar($params);
});

$router->post('/api/estoque', function () {
    EstoqueController::criar();
});

$router->patch('/api/estoque/:id', function (array $params) {
    EstoqueController::atualizar($params);
});

$router->patch('/api/estoque/:id/stock', function (array $params) {
    EstoqueController::ajustar_stock($params);
});

$router->delete('/api/estoque/:id', function (array $params) {
    EstoqueController::deletar($params);
});

// API de logs

$router->get('/api/logs', function () {
    LogsController::listar();
});

$router->get('/api/logs/funcionarios', function () {
    LogsController::funcionarios_ativos();
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

$router->patch('/api/funcionarios/:id', function (array $params) {
    FuncionariosController::atualizar($params);
});

$router->delete('/api/funcionarios/:id', function (array $params) {
    FuncionariosController::deletar($params);
});

// API de veículos e agendamentos (área do cliente)

$router->get('/api/veiculos', function () {
    AccessControl::exigir_cliente();
    ClienteController::listar_veiculos();
});

$router->post('/api/veiculos', function () {
    AccessControl::exigir_cliente();
    ClienteController::criar_veiculo();
});

$router->patch('/api/veiculos/:id', function (array $params) {
    AccessControl::exigir_cliente();
    ClienteController::atualizar_veiculo($params);
});

$router->delete('/api/veiculos/:id', function (array $params) {
    AccessControl::exigir_cliente();
    ClienteController::deletar_veiculo($params);
});

$router->get('/api/agendamentos', function () {
    AccessControl::exigir_cliente();
    ClienteController::listar_agendamentos();
});

$router->post('/api/agendamento', function () {
    AccessControl::exigir_cliente();
    AgendamentoController::criar();
});

// API de agendamentos (gestão interna — recepção/gerência)

$router->get('/api/agendamentos/gerenciar', function () {
    AgendamentoGerenciaController::listar();
});

$router->post('/api/agendamentos/gerenciar', function () {
    AgendamentoGerenciaController::criar();
});

$router->patch('/api/agendamentos/:id/status', function (array $params) {
    AgendamentoGerenciaController::atualizar_status($params);
});

$router->delete('/api/agendamentos/:id', function (array $params) {
    AgendamentoGerenciaController::deletar($params);
});

// API de perfil

$router->get('/api/perfil', function () {
    AccessControl::exigir_cliente();
    ClienteController::perfil_get();
});

$router->post('/api/perfil/foto', function () {
    AccessControl::exigir_cliente();
    ClienteController::foto_upload();
});

$router->delete('/api/perfil/foto', function () {
    AccessControl::exigir_cliente();
    ClienteController::foto_remover();
});

// Servir imagens de produtos salvas em disco

$router->get('/uploads/produtos/:arquivo', function (array $params) {
    $nome = basename($params['arquivo'] ?? '');

    if (!preg_match('/^prod_[a-f0-9._]+\.webp$/', $nome)) {
        http_response_code(404);
        exit;
    }

    $caminho = '/var/www/html/automax/uploads/produtos/' . $nome;

    if (!file_exists($caminho)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($caminho);
    exit;
});

// Servir avatares salvos em disco legal

$router->get('/uploads/avatars/:arquivo', function (array $params) {
    $nome = basename($params['arquivo'] ?? '');

    if (!preg_match('/^\d+\.webp$/', $nome)) {
        http_response_code(404);
        exit;
    }

    $caminho = '/var/www/html/automax/uploads/avatars/' . $nome;

    if (!file_exists($caminho)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: image/webp');
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($caminho);
    exit;
});

// Dispatch

try {
    $router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
} catch (Exception $e) {
    http_response_code(500);
    echo $e->getMessage();
}