<?php
declare(strict_types=1);

// frontend/api/ordem_suporte.php
// Rota: GET /api/ordem/suporte
// Retorna clientes, funcionários e veículos agrupados por cliente
// para popular os selects do modal de OS.

require_once '/var/www/html/vendor/autoload.php';

use Automax\Auth\AccessControl;
use Automax\Config\Database;
use Automax\Config\DatabaseException;

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Método não permitido.']);
    exit;
}

AccessControl::exigir_permissao('ordem_servico.visualizar');

try {
    $db = Database::get_instance();

    $clientes = $db->query_all(
        'SELECT id_cliente AS id, nome_cliente AS nome, vip
           FROM clientes
          ORDER BY nome_cliente ASC'
    );

    $funcionarios = $db->query_all(
        'SELECT id_funcionario AS id, nome_funcionario AS nome, nivel_de_acesso AS nivel
           FROM funcionarios
          ORDER BY nome_funcionario ASC'
    );

    $veiculos_raw = $db->query_all(
        "SELECT v.id_veiculo AS id,
                v.id_cliente,
                CONCAT(v.marca, ' ', v.modelo, ' ', v.ano, ' — ', v.placa) AS label
           FROM veiculos v
          ORDER BY v.id_cliente ASC, v.marca ASC"
    );

    // Agrupa veículos por id_cliente para o JS popular o select dinamicamente
    $veiculos_por_cliente = [];
    foreach ($veiculos_raw as $v) {
        $key = (string)$v['id_cliente'];
        $veiculos_por_cliente[$key][] = [
            'id'    => (int)$v['id'],
            'label' => $v['label'],
        ];
    }

    echo json_encode([
        'ok'                   => true,
        'clientes'             => $clientes,
        'funcionarios'         => $funcionarios,
        'veiculos_por_cliente' => $veiculos_por_cliente,
    ], JSON_UNESCAPED_UNICODE);

} catch (DatabaseException $e) {
    error_log('[ordem_suporte] ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['ok' => false, 'erro' => 'Serviço indisponível.']);
}