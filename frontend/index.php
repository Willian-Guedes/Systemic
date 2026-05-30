<?php

declare(strict_types=1);

require_once './libs/router.php';
require_once './libs/AccessControl.php';
require_once './auth_controller.php';
require_once './ProdutoController.php';
require_once './cadastro_controller.php';

$router = new Router(__DIR__);

// ── Helpers ───────────────────────────────────────────────────────────────

/*
 * Serve uma página HTML com o <base href> correto.
 * Centraliza o padrão repetido em todas as rotas públicas.
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
 * window.__session_user, para que o JS preencha o sidebar e aplique
 * as restrições de UI sem fazer uma segunda requisição ao servidor.
 *
 * O campo `permissoes` é um array de strings (ex: ['ordem_servico.visualizar']).
 * O JS usa can.editar para decidir o que mostrar — mas a proteção real
 * continua sendo feita pelo PHP em cada rota via AccessControl::exigir_permissao.
 */
function serve_protected_page(string $base_href, string $file_path): void
{
    AuthController::exigir_autenticacao();

    $nivel = $_SESSION['nivel_de_acesso'] ?? '';

    $user_data = [
        'nome'       => $_SESSION['funcionario_nome'] ?? '',
        'nivel'      => $nivel,
        'iniciais'   => build_user_initials($_SESSION['funcionario_nome'] ?? ''),
        'permissoes' => AccessControl::permissoes_do_nivel($nivel),
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
    AccessControl::exigir_permissao('ordem_servico.visualizar');
    serve_protected_page('/pages/ordem-servico/', __DIR__ . '/pages/ordem-servico/automax-os.html');
});

// ── Rotas de clientes (descomentar ao criar a página) ─────────────────────
// $router->get('/clientes', function () {
//     AccessControl::exigir_permissao('clientes.visualizar');
//     serve_protected_page('/pages/clientes/', __DIR__ . '/pages/clientes/index.html');
// });

// ── Rotas de estoque (descomentar ao criar a página) ──────────────────────
// $router->get('/estoque', function () {
//     AccessControl::exigir_permissao('estoque.visualizar');
//     serve_protected_page('/pages/estoque/', __DIR__ . '/pages/estoque/index.html');
// });

// ── Placeholders ──────────────────────────────────────────────────────────
 
/*
 * GET /api/produto?id=:id
 * Retorna JSON com { produto, relacionados }.
 * O produto.js consome este endpoint ao carregar /produto/:id.
 */
$router->get('/api/produto', function () {
    include __DIR__ . '/api/produto.php';
});
// ── Rotas de cadastro de cliente ─────────────────────────────────────────

$router->get('/cadastro', function () {
    CadastroController::handle_page();
});

$router->post('/cadastro/criar', function () {
    CadastroController::handle_criar();
});

foreach (['/servicos', '/pedir'] as $rota) {
    $router->get($rota, function () use ($rota) {
        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        echo "<h2 style='font-family:sans-serif;padding:2rem'>Página <code>{$rota}</code> em construção.</h2>";
    });
}

/*
 * GET /busca?q=:termo&categoria=:cat&pagina=:n
 *
 * Página de resultados de busca pública (não exige login).
 * O JavaScript da página consome /api/busca para obter os dados.
 */
$router->get('/busca', function () {
    serve_page('/pages/busca/', __DIR__ . '/pages/busca/busca.html');
});

/*
 * GET /api/busca?q=:termo&categoria=:cat&pagina=:n
 *
 * API JSON de busca de produtos — pública, sem autenticação.
 * Toda a sanitização e validação ocorre dentro do endpoint.
 */
$router->get('/api/busca', function () {
    include __DIR__ . '/api/busca.php';
});

// ── Dispatch ──────────────────────────────────────────────────────────────

try {
    $router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
} catch (Exception $e) {
    http_response_code(500);
    echo $e->getMessage();
}