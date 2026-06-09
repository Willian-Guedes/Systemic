<?php
declare(strict_types=1);

use Automax\Config\Database;
use Automax\Config\DatabaseException;


use Automax\Controllers\ProdutoNotFoundException;
use Automax\Config\DatabaseException;


use Automax\Config\Database;
use Automax\Config\DatabaseException;

/*
 * Endpoint: GET /api/produtos?pagina=:n&categoria=:cat
 *
 * Lista produtos com paginaÃ§Ã£o e filtro opcional por categoria.
 * Exige autenticaÃ§Ã£o (sessÃ£o ativa).
 *
 * Respostas:
 *   200  { produtos: [...], total: int, pagina: int, por_pagina: int, paginas: int }
 *   401  { erro: "NÃ£o autenticado" }
 *   405  { erro: "MÃ©todo nÃ£o permitido" }
 *   500  { erro: "Erro interno" }
 */





header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['erro' => 'MÃ©todo nÃ£o permitido.']);
    exit;
}

AuthController::exigir_autenticacao();

$por_pagina = 12;

$pagina = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($pagina === false) {
    $pagina = 1;
}

$categorias_permitidas = ['pecas', 'fluidos', 'eletrico', 'todos'];
$categoria_raw = strtolower(trim($_GET['categoria'] ?? 'todos'));
$categoria = in_array($categoria_raw, $categorias_permitidas, true) ? $categoria_raw : 'todos';

try {
    $db     = Database::get_instance();
    $offset = ($pagina - 1) * $por_pagina;

    $where_sql  = $categoria !== 'todos' ? 'WHERE categoria = :categoria' : '';
    $params_base = $categoria !== 'todos' ? [':categoria' => $categoria] : [];

    $total = (int) ($db->query_one(
        "SELECT COUNT(*) AS total FROM produtos {$where_sql}",
        $params_base
    )['total'] ?? 0);

    $params_rows = array_merge($params_base, [
        ':limite' => $por_pagina,
        ':offset' => $offset,
    ]);

    $linhas = $db->query(
        "SELECT id_produto, nome, preco, stock, imagem, categoria
           FROM produtos
         {$where_sql}
          ORDER BY id_produto DESC
          LIMIT :limite OFFSET :offset",
        $params_rows
    );

    $produtos = array_map(fn(array $r): array => [
        'id'        => (int)   $r['id_produto'],
        'nome'      =>         $r['nome'],
        'preco'     => (float) $r['preco'],
        'stock'     => (int)   $r['stock'],
        'imagem'    =>         $r['imagem'],
        'categoria' =>         $r['categoria'],
    ], $linhas);

    http_response_code(200);
    echo json_encode([
        'produtos'   => $produtos,
        'total'      => $total,
        'pagina'     => $pagina,
        'por_pagina' => $por_pagina,
        'paginas'    => (int) ceil($total / $por_pagina),
    ], JSON_UNESCAPED_UNICODE);

} catch (DatabaseException $e) {
    error_log('[API produtos] DatabaseException: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno. Tente novamente mais tarde.']);
} catch (Throwable $e) {
    error_log('[API produtos] Throwable: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno inesperado.']);
}
