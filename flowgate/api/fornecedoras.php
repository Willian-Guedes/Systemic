<?php

declare(strict_types=1);

/*
 * Endpoint: GET /api/fornecedoras
 *
 * Lista todas as fornecedoras ativas cadastradas na Flowgate.
 * Útil para que clientes da API possam filtrar o catálogo
 * de peças por fornecedora específica.
 * Requer autenticação via X-Flowgate-Key.
 *
 * Respostas:
 *   200  { fornecedoras: [...] }
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
    $rows = $db->query(
        'SELECT id_fornecedora, nome, email_contato, telefone
           FROM fornecedoras
          WHERE ativa = 1
          ORDER BY nome ASC'
    );

    $fornecedoras = array_map(fn(array $r): array => [
        'id'      => (int) $r['id_fornecedora'],
        'nome'    => $r['nome'],
        'email'   => $r['email_contato'],
        'telefone' => $r['telefone'],
    ], $rows);

    http_response_code(200);
    echo json_encode(['fornecedoras' => $fornecedoras], JSON_UNESCAPED_UNICODE);

} catch (DatabaseException $e) {
    error_log('[Flowgate /api/fornecedoras] DB: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno. Tente novamente.']);
} catch (\Throwable $e) {
    error_log('[Flowgate /api/fornecedoras] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno inesperado.']);
}
