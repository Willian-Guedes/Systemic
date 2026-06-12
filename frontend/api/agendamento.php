<?php
declare(strict_types=1);

// frontend/api/agendamento.php
// Rota: POST /api/agendamento

require_once '/var/www/html/vendor/autoload.php';

use Automax\Config\Database;

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Método não permitido.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];

$nome    = trim($data['nome']           ?? '');
$tel     = trim($data['telefone']       ?? '');
$marca   = trim($data['marca']          ?? '');
$modelo  = trim($data['modelo']         ?? '');
$servico = trim($data['servico']        ?? '');
$data_p  = trim($data['data_preferida'] ?? '');

if (!$nome || !$tel || !$marca || !$modelo || !$servico || !$data_p) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'erro' => 'Preencha todos os campos obrigatórios.']);
    exit;
}

try {
    $db = Database::get_instance();

    $db->execute(
        'INSERT INTO agendamentos
            (nome, telefone, email, placa, marca, modelo, ano, combustivel, km,
             servico, sintomas, descricao, data_preferida, turno)
         VALUES
            (:nome, :telefone, :email, :placa, :marca, :modelo, :ano, :combustivel, :km,
             :servico, :sintomas, :descricao, :data_preferida, :turno)',
        [
            ':nome'           => $nome,
            ':telefone'       => $tel,
            ':email'          => trim($data['email']       ?? '') ?: null,
            ':placa'          => strtoupper(trim($data['placa'] ?? '')) ?: null,
            ':marca'          => $marca,
            ':modelo'         => $modelo,
            ':ano'            => $data['ano']  !== '' ? (int)$data['ano']  : null,
            ':combustivel'    => trim($data['combustivel'] ?? '') ?: null,
            ':km'             => $data['km']   !== '' ? (int)$data['km']   : null,
            ':servico'        => $servico,
            ':sintomas'       => trim($data['sintomas']    ?? '') ?: null,
            ':descricao'      => trim($data['descricao']   ?? '') ?: null,
            ':data_preferida' => $data_p,
            ':turno'          => trim($data['turno']       ?? '') ?: null,
        ]
    );

    echo json_encode(['ok' => true]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'Erro interno. Tente novamente.']);
}