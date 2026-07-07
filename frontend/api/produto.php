<?php
declare(strict_types=1);

use Automax\Controllers\ProdutoController;
use Automax\Controllers\ProdutoNotFoundException;
use Automax\Controllers\AuthController;
use Automax\Config\DatabaseException;

/*
 * Endpoint: GET /api/produto?id=:id
 *
 * Retorna os dados de um único produto e sua lista de relacionados
 * (mesma categoria, excluindo o próprio produto).
 * Exige autenticação (sessão ativa).
 *
 * Respostas:
 *   200  { produto: {...}, relacionados: [...] }
 *   400  { erro: "ID de produto inválido." }
 *   401  { erro: "Não autenticado" }
 *   404  { erro: "Produto não encontrado." }
 *   405  { erro: "Método não permitido" }
 *   500  { erro: "Erro interno" }
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['erro' => 'Método não permitido.']);
    exit;
}

AuthController::exigir_autenticacao();

$id_produto = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($id_produto === false) {
    http_response_code(400);
    echo json_encode(['erro' => 'ID de produto inválido.']);
    exit;
}

try {
    $controller = new ProdutoController();

    $produto      = $controller->buscar_por_id($id_produto);
    $relacionados = $controller->buscar_relacionados($produto['categoria'], $id_produto);

    http_response_code(200);
    echo json_encode([
        'produto'      => [
            'id'        => (int)   $produto['id_produto'],
            'nome'      =>         $produto['nome'],
            'preco'     => (float) $produto['preco'],
            'stock'     => (int)   $produto['stock'],
            'imagem'    =>         $produto['imagem'],
            'categoria' =>         $produto['categoria'],
            'detalhes'  =>         $produto['detalhes'],
        ],
        'relacionados' => array_map(fn(array $r): array => [
            'id_produto' => (int)   $r['id_produto'],
            'nome'       =>         $r['nome'],
            'preco'      => (float) $r['preco'],
            'imagem'     =>         $r['imagem'],
        ], $relacionados),
    ], JSON_UNESCAPED_UNICODE);

} catch (ProdutoNotFoundException $e) {
    http_response_code(404);
    echo json_encode(['erro' => 'Produto não encontrado.']);
} catch (DatabaseException $e) {
    error_log('[API produto] DatabaseException: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno. Tente novamente mais tarde.']);
} catch (Throwable $e) {
    error_log('[API produto] Throwable: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno inesperado.']);
}