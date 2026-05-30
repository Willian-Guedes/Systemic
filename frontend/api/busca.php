<?php

declare(strict_types=1);

/*
 * Endpoint público: GET /api/busca?q=:termo&categoria=:cat&pagina=:n
 *
 * Busca produtos na tabela `produtos` sem exigir autenticação,
 * pois o resultado é exibido na página inicial (pré-login).
 *
 * Segurança:
 *  - Parâmetros sanitizados e validados antes de qualquer uso
 *  - Apenas prepared statements com parâmetros nomeados (zero SQL injection)
 *  - Paginação server-side (nenhum dado extra é exposto)
 *  - Cabeçalhos anti-clickjacking e anti-sniff incluídos
 *  - Campos retornados são explicitamente enumerados (sem SELECT *)
 *
 * Respostas:
 *   200  { resultados: [...], total: int, pagina: int, por_pagina: int }
 *   400  { erro: "..." }
 *   405  { erro: "Método não permitido" }
 *   500  { erro: "Erro interno" }
 */

require_once __DIR__ . '/../database.php';

// ── Cabeçalhos de segurança ────────────────────────────────────────────────

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

// ── Apenas GET ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['erro' => 'Método não permitido.']);
    exit;
}

// ── Helpers ────────────────────────────────────────────────────────────────

function responder(int $codigo, array $payload): never
{
    http_response_code($codigo);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Validação e sanitização dos parâmetros ─────────────────────────────────

// Termo de busca: strip tags, trim, limite de 100 chars
$termo_raw = $_GET['q'] ?? '';
$termo     = mb_substr(trim(strip_tags($termo_raw)), 0, 100, 'UTF-8');

if ($termo === '') {
    responder(400, ['erro' => 'Informe um termo de busca.']);
}

// Categoria: lista de valores permitidos (whitelist)
$categorias_permitidas = ['pecas', 'fluidos', 'eletrico', 'todos'];
$categoria_raw = strtolower(trim($_GET['categoria'] ?? 'todos'));
$categoria     = in_array($categoria_raw, $categorias_permitidas, true)
    ? $categoria_raw
    : 'todos';

// Paginação: página >= 1, por_pagina fixo em 12
$pagina_raw = $_GET['pagina'] ?? '1';
$pagina     = filter_var($pagina_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($pagina === false) {
    $pagina = 1;
}

$por_pagina = 12;
$offset     = ($pagina - 1) * $por_pagina;

// ── Monta query com prepared statements ───────────────────────────────────

/*
 * Busca por LIKE nos campos nome e detalhes.
 * O % é adicionado aqui em PHP, nunca vindo do usuário direto,
 * para evitar qualquer possibilidade de injeção via parâmetro.
 */
$like = '%' . $termo . '%';

$params_count = [':like_nome' => $like, ':like_det' => $like];
$params_rows  = [':like_nome' => $like, ':like_det' => $like];

$where_categoria = '';
if ($categoria !== 'todos') {
    $where_categoria        = ' AND categoria = :categoria';
    $params_count[':categoria'] = $categoria;
    $params_rows[':categoria']  = $categoria;
}

$sql_count = "
    SELECT COUNT(*) AS total
      FROM produtos
     WHERE (nome LIKE :like_nome OR detalhes LIKE :like_det)
    {$where_categoria}
";

$sql_rows = "
    SELECT id_produto, nome, preco, imagem, categoria, detalhes
      FROM produtos
     WHERE (nome LIKE :like_nome OR detalhes LIKE :like_det)
    {$where_categoria}
     ORDER BY nome ASC
     LIMIT :limite OFFSET :offset
";

$params_rows[':limite']  = $por_pagina;
$params_rows[':offset']  = $offset;

// ── Executa ────────────────────────────────────────────────────────────────

try {
    $db      = Database::get_instance();
    $total   = (int) ($db->query_one($sql_count, $params_count)['total'] ?? 0);
    $linhas  = $db->query($sql_rows, $params_rows);

    // Formata os dados: converte preço para float e garante tipos corretos
    $resultados = array_map(function (array $row): array {
        return [
            'id'        => (int)   $row['id_produto'],
            'nome'      =>         $row['nome'],
            'preco'     => (float) $row['preco'],
            'imagem'    =>         $row['imagem'],
            'categoria' =>         $row['categoria'],
            'detalhes'  =>         mb_substr($row['detalhes'], 0, 120, 'UTF-8'),
        ];
    }, $linhas);

    responder(200, [
        'resultados' => $resultados,
        'total'      => $total,
        'pagina'     => $pagina,
        'por_pagina' => $por_pagina,
    ]);

} catch (DatabaseException $e) {
    error_log('[API busca] DatabaseException: ' . $e->getMessage());
    responder(500, ['erro' => 'Erro interno. Tente novamente mais tarde.']);
} catch (Throwable $e) {
    error_log('[API busca] Throwable: ' . $e->getMessage());
    responder(500, ['erro' => 'Erro interno inesperado.']);
}
