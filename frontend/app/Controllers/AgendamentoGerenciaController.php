<?php

declare(strict_types=1);

namespace Automax\Controllers;

use Automax\Config\Database;
use Automax\Config\DatabaseException;
use Automax\Auth\AccessControl;

class AgendamentoGerenciaController
{
    private const STATUS_VALIDOS = ['pendente', 'confirmado', 'concluido', 'cancelado'];
    private const TURNOS_VALIDOS = ['manha', 'tarde'];
    private const POR_PAGINA     = 15;

    public static function criar(): void
    {
        AccessControl::exigir_permissao('agendamentos.gerenciar');
        self::validar_csrf();

        $body  = self::ler_body();
        $erros = self::validar_criacao($body);

        if (!empty($erros)) {
            self::json(422, ['ok' => false, 'erro' => implode(' ', $erros)]);
            return;
        }

        $status = trim((string) ($body['status'] ?? '')) ?: 'confirmado';
        if (!in_array($status, self::STATUS_VALIDOS, true)) {
            $status = 'confirmado';
        }

        try {
            $db = Database::get_instance();

            $id = $db->insert(
                'INSERT INTO agendamentos
                    (nome, telefone, email, placa, marca, modelo, ano,
                     combustivel, km, servico, sintomas, descricao, data_preferida, turno, status)
                 VALUES
                    (:nome, :telefone, :email, :placa, :marca, :modelo, :ano,
                     :combustivel, :km, :servico, :sintomas, :descricao, :data_preferida, :turno, :status)',
                [
                    ':nome'           => trim($body['nome']),
                    ':telefone'       => trim($body['telefone']),
                    ':email'          => trim((string) ($body['email'] ?? '')) ?: null,
                    ':placa'          => strtoupper(trim((string) ($body['placa'] ?? ''))) ?: null,
                    ':marca'          => trim($body['marca']),
                    ':modelo'         => trim($body['modelo']),
                    ':ano'            => self::ou_null_int($body['ano'] ?? ''),
                    ':combustivel'    => trim((string) ($body['combustivel'] ?? '')) ?: null,
                    ':km'             => self::ou_null_int($body['km'] ?? ''),
                    ':servico'        => trim($body['servico']),
                    ':sintomas'       => trim((string) ($body['sintomas']  ?? '')) ?: null,
                    ':descricao'      => trim((string) ($body['descricao'] ?? '')) ?: null,
                    ':data_preferida' => $body['data_preferida'],
                    ':turno'          => trim((string) ($body['turno'] ?? '')) ?: null,
                    ':status'         => $status,
                ]
            );

            self::json(201, ['ok' => true, 'id' => $id]);

        } catch (DatabaseException $e) {
            error_log('[AgendamentosController] criar: ' . $e->getMessage());
            self::json(503, ['ok' => false, 'erro' => 'Serviço indisponível.']);
        }
    }

    private static function validar_criacao(array $body): array
    {
        $erros = [];

        if (empty(trim($body['nome']     ?? ''))) $erros[] = 'Nome é obrigatório.';
        if (empty(trim($body['telefone'] ?? ''))) $erros[] = 'Telefone é obrigatório.';
        if (empty(trim($body['marca']    ?? ''))) $erros[] = 'Marca do veículo é obrigatória.';
        if (empty(trim($body['modelo']   ?? ''))) $erros[] = 'Modelo do veículo é obrigatório.';
        if (empty(trim($body['servico']  ?? ''))) $erros[] = 'Serviço é obrigatório.';

        $email = trim((string) ($body['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erros[] = 'E-mail inválido.';
        }

        $data_preferida = $body['data_preferida'] ?? '';
        $partes = \DateTime::createFromFormat('Y-m-d', (string) $data_preferida);
        if (!$partes || $partes->format('Y-m-d') !== $data_preferida) {
            $erros[] = 'Data preferida inválida.';
        }

        $turno = trim((string) ($body['turno'] ?? ''));
        if ($turno !== '' && !in_array($turno, self::TURNOS_VALIDOS, true)) {
            $erros[] = 'Turno inválido.';
        }

        return $erros;
    }

    public static function listar(): void
    {
        AccessControl::exigir_permissao('agendamentos.visualizar');

        $pagina = self::validar_int_positivo($_GET['pagina'] ?? '1') ?: 1;
        $busca  = trim($_GET['busca']  ?? '');
        $status = trim($_GET['status'] ?? '');

        if ($status !== '' && !in_array($status, self::STATUS_VALIDOS, true)) {
            $status = '';
        }

        try {
            $db     = Database::get_instance();
            $offset = ($pagina - 1) * self::POR_PAGINA;

            [$where_sql, $params_base] = self::montar_filtros($busca, $status);

            $total = (int) ($db->query_one(
                "SELECT COUNT(*) AS total FROM agendamentos {$where_sql}",
                $params_base
            )['total'] ?? 0);

            $linhas = $db->query(
                "SELECT id, nome, telefone, email, placa, marca, modelo, ano,
                        servico, sintomas, descricao, data_preferida, turno,
                        status, criado_em
                   FROM agendamentos
                 {$where_sql}
                  ORDER BY FIELD(status, 'pendente', 'confirmado', 'concluido', 'cancelado'),
                           data_preferida ASC, id DESC
                  LIMIT :limite OFFSET :offset",
                array_merge($params_base, [':limite' => self::POR_PAGINA, ':offset' => $offset])
            );

            self::json(200, [
                'ok'            => true,
                'agendamentos'  => $linhas,
                'total'         => $total,
                'pagina'        => $pagina,
                'total_paginas' => max(1, (int) ceil($total / self::POR_PAGINA)),
            ]);

        } catch (DatabaseException $e) {
            error_log('[AgendamentosController] listar: ' . $e->getMessage());
            self::json(503, ['ok' => false, 'erro' => 'Serviço indisponível.']);
        }
    }

    public static function atualizar_status(array $params): void
    {
        AccessControl::exigir_permissao('agendamentos.gerenciar');
        self::validar_csrf();

        $id = self::validar_id($params['id'] ?? '');
        if ($id === false) {
            self::json(400, ['ok' => false, 'erro' => 'ID inválido.']);
            return;
        }

        $body   = self::ler_body();
        $status = trim((string) ($body['status'] ?? ''));

        if (!in_array($status, self::STATUS_VALIDOS, true)) {
            self::json(422, ['ok' => false, 'erro' => 'Status inválido.']);
            return;
        }

        try {
            $db = Database::get_instance();

            $afetados = $db->execute(
                'UPDATE agendamentos SET status = :status WHERE id = :id',
                [':status' => $status, ':id' => $id]
            );

            if ($afetados === 0) {
                self::json(404, ['ok' => false, 'erro' => 'Agendamento não encontrado.']);
                return;
            }

            self::json(200, ['ok' => true, 'status' => $status]);

        } catch (DatabaseException $e) {
            error_log('[AgendamentosController] atualizar_status: ' . $e->getMessage());
            self::json(503, ['ok' => false, 'erro' => 'Serviço indisponível.']);
        }
    }

    public static function deletar(array $params): void
    {
        AccessControl::exigir_permissao('agendamentos.gerenciar');
        self::validar_csrf();

        $id = self::validar_id($params['id'] ?? '');
        if ($id === false) {
            self::json(400, ['ok' => false, 'erro' => 'ID inválido.']);
            return;
        }

        try {
            $db = Database::get_instance();

            $afetados = $db->execute('DELETE FROM agendamentos WHERE id = :id', [':id' => $id]);

            if ($afetados === 0) {
                self::json(404, ['ok' => false, 'erro' => 'Agendamento não encontrado.']);
                return;
            }

            self::json(200, ['ok' => true]);

        } catch (DatabaseException $e) {
            error_log('[AgendamentosController] deletar: ' . $e->getMessage());
            self::json(503, ['ok' => false, 'erro' => 'Serviço indisponível.']);
        }
    }

    private static function montar_filtros(string $busca, string $status): array
    {
        $condicoes = [];
        $params    = [];

        if ($busca !== '') {
            $condicoes[] = '(nome LIKE :busca OR placa LIKE :busca OR servico LIKE :busca)';
            $params[':busca'] = "%{$busca}%";
        }

        if ($status !== '') {
            $condicoes[] = 'status = :status';
            $params[':status'] = $status;
        }

        $where_sql = $condicoes ? ('WHERE ' . implode(' AND ', $condicoes)) : '';
        return [$where_sql, $params];
    }

    private static function ou_null_int(mixed $valor): ?int
    {
        return $valor !== '' && $valor !== null ? (int) $valor : null;
    }

    private static function validar_int_positivo(mixed $valor): ?int
    {
        $resultado = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $resultado === false ? null : $resultado;
    }

    private static function validar_id(mixed $raw): int|false
    {
        return filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    private static function ler_body(): array
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : [];
    }

    private static function validar_csrf(): void
    {
        $token_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $token_sessao = $_SESSION['csrf_token']       ?? '';

        if (!$token_sessao || !hash_equals($token_sessao, $token_header)) {
            self::json(403, ['ok' => false, 'erro' => 'Token inválido.']);
            exit;
        }
    }

    private static function json(int $status, mixed $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
