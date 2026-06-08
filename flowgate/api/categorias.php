<?php

declare(strict_types=1);

/*
 * Endpoint: GET /api/categorias
 *
 * Lista todas as categorias de peças disponíveis.
 * Usado pelo cliente para popular filtros de busca.
 * Requer autenticação via X-Flowgate-Key.
 *
 * Respostas:
 *   200  { categorias: [...] }
 *   401  { erro: "..." }
 *   405  { erro: "..." }
 *   500  { erro: "..." }
 */

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../libs/ApiAuth.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['erro' => 'Método não permitido.']);
    exit;
}

ApiAuth::exigir();

try {
    $db   = Database::get_instance();
    $rows = $db->query('SELECT id_categoria, slug, nome FROM categorias ORDER BY nome ASC');

    $categorias = array_map(fn(array $r): array => [
        'id'   => (int) $r['id_categoria'],
        'slug' => $r['slug'],
        'nome' => $r['nome'],
    ], $rows);

    http_response_code(200);
    echo json_encode(['categorias' => $categorias], JSON_UNESCAPED_UNICODE);

} catch (DatabaseException $e) {
    error_log('[Flowgate /api/categorias] DB: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno. Tente novamente.']);
} catch (\Throwable $e) {
    error_log('[Flowgate /api/categorias] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno inesperado.']);
}
