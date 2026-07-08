<?php

declare(strict_types=1);

namespace Automax\Controllers;

use Automax\Config\Database;
use Automax\Config\DatabaseException;
use Automax\Auth\AccessControl;

class OrdemController
{
    // ─── Helpers ─────────────────────────────────────────────

    private static function json(int $status, mixed $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private static function ler_body(): ?array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) return null;
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private static function validar_csrf(): void
    {
        $token_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $token_sessao = $_SESSION['csrf_token']       ?? '';
        if (!$token_sessao || !hash_equals($token_sessao, $token_header)) {
            self::json(403, ['erro' => 'Token inválido.']);
            exit;
        }
    }

    private static function validar_id(mixed $raw): int|false
    {
        return filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    // Retorna array de peças [{nome, qtd, valor}] de uma OS
    private static function buscar_pecas(Database $db, int $id_ordem): array
    {
        return $db->query_all(
            'SELECT nome_peca AS nome, quantidade_trocas AS qtd, valor_unitario AS valor
               FROM ordem_pecas
              WHERE id_ordem = :id
              ORDER BY id_ordem_peca ASC',
            [':id' => $id_ordem]
        );
    }

    // Substitui todas as peças de uma OS (chamar dentro de transaction)
    private static function salvar_pecas(Database $db, int $id_ordem, array $pecas): void
    {
        $db->execute('DELETE FROM ordem_pecas WHERE id_ordem = :id', [':id' => $id_ordem]);

        foreach ($pecas as $p) {
            $nome  = trim((string)($p['nome']  ?? ''));
            $qtd   = max(1, (int)($p['qtd']    ?? 1));
            $valor = max(0.0, (float)($p['valor'] ?? 0));

            if ($nome === '') continue;

            $db->execute(
                'INSERT INTO ordem_pecas (id_ordem, nome_peca, quantidade_trocas, valor_unitario)
                 VALUES (:id_ordem, :nome, :qtd, :valor)',
                [
                    ':id_ordem' => $id_ordem,
                    ':nome'     => $nome,
                    ':qtd'      => $qtd,
                    ':valor'    => $valor,
                ]
            );
        }
    }

    private static function id_funcionario_sessao(): ?int
    {
        $id = $_SESSION['funcionario_id'] ?? null;
        return $id !== null ? (int)$id : null;
    }

    private static function registrar_log(Database $db, ?int $id_funcionario, string $detalhe): void
    {
        try {
            $db->execute(
                'INSERT INTO logs (id_funcionario, detalhe) VALUES (:id_funcionario, :detalhe)',
                [':id_funcionario' => $id_funcionario, ':detalhe' => $detalhe]
            );
        } catch (\Throwable $e) {
            error_log('[OrdemController] registrar_log: ' . $e->getMessage());
        }
    }

    // ─── GET /api/ordens ─────────────────────────────────────

    public static function listar(): void
    {
        AccessControl::exigir_permissao('ordem_servico.visualizar');

        try {
            $db = Database::get_instance();

            $ordens = $db->query_all(
                "SELECT o.id_ordem,
                        o.id_funcionario,
                        o.id_cliente,
                        o.id_veiculo,
                        o.tipo_ordem,
                        o.diagnostico,
                        DATE_FORMAT(o.abertura,   '%Y-%m-%d') AS abertura,
                        DATE_FORMAT(o.prazo,      '%Y-%m-%d') AS prazo,
                        DATE_FORMAT(o.fechamento, '%Y-%m-%d') AS fechamento,
                        o.conclusao_ordem,
                        o.mao_de_obra,
                        o.orcamento,
                        o.status
                   FROM ordem o
                  ORDER BY o.id_ordem DESC"
            );

            foreach ($ordens as &$os) {
                $os['id_ordem']       = (int)$os['id_ordem'];
                $os['id_funcionario'] = $os['id_funcionario'] !== null ? (string)$os['id_funcionario'] : null;
                $os['id_cliente']     = (string)$os['id_cliente'];
                $os['id_veiculo']     = (int)$os['id_veiculo'];
                $os['mao_de_obra']    = $os['mao_de_obra'] !== null ? (float)$os['mao_de_obra'] : 0.0;
                $os['orcamento']      = $os['orcamento']   !== null ? (float)$os['orcamento']   : 0.0;
                $os['pecas']          = self::buscar_pecas($db, $os['id_ordem']);
            }
            unset($os);

            self::json(200, ['ok' => true, 'ordens' => $ordens]);

        } catch (DatabaseException $e) {
            error_log('[OrdemController] listar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    // ─── POST /api/ordens ────────────────────────────────────

    public static function criar(): void
    {
        AccessControl::exigir_permissao('ordem_servico.criar');
        self::validar_csrf();

        $body = self::ler_body();
        if ($body === null) { self::json(400, ['erro' => 'Corpo inválido.']); return; }

        $id_funcionario = isset($body['id_funcionario']) && $body['id_funcionario'] !== ''
            ? (int)$body['id_funcionario'] : null;
        $id_cliente = (int)($body['id_cliente'] ?? 0);
        $id_veiculo = (int)($body['id_veiculo'] ?? 0);
        $tipo_ordem = trim((string)($body['tipo_ordem'] ?? ''));
        $abertura   = trim((string)($body['abertura']   ?? ''));
        $prazo      = trim((string)($body['prazo']      ?? ''));

        if (!$id_cliente || !$id_veiculo || !$tipo_ordem || !$abertura || !$prazo) {
            self::json(422, ['erro' => 'Campos obrigatórios ausentes.']); return;
        }

        $db = null;
        try {
            $db = Database::get_instance();
            $db->begin_transaction();

            $id_ordem = $db->insert(
                "INSERT INTO ordem
                    (id_funcionario, id_cliente, id_veiculo, tipo_ordem,
                     diagnostico, abertura, prazo, fechamento, conclusao_ordem,
                     mao_de_obra, orcamento, status)
                 VALUES
                    (:id_funcionario, :id_cliente, :id_veiculo, :tipo_ordem,
                     :diagnostico, :abertura, :prazo, NULL, NULL,
                     :mao_de_obra, :orcamento, 'aberta')",
                [
                    ':id_funcionario' => $id_funcionario,
                    ':id_cliente'     => $id_cliente,
                    ':id_veiculo'     => $id_veiculo,
                    ':tipo_ordem'     => $tipo_ordem,
                    ':diagnostico'    => trim((string)($body['diagnostico'] ?? '')) ?: null,
                    ':abertura'       => $abertura,
                    ':prazo'          => $prazo,
                    ':mao_de_obra'    => isset($body['mao_de_obra']) && $body['mao_de_obra'] !== ''
                                            ? (float)$body['mao_de_obra'] : null,
                    ':orcamento'      => isset($body['orcamento']) && $body['orcamento'] !== ''
                                            ? (float)$body['orcamento'] : null,
                ]
            );

            if (!empty($body['pecas']) && is_array($body['pecas'])) {
                self::salvar_pecas($db, $id_ordem, $body['pecas']);
            }

            $id_func = self::id_funcionario_sessao();
            self::registrar_log($db, $id_func, "OS #{$id_ordem} criada — tipo: {$tipo_ordem} | cliente: {$id_cliente}");

            $db->commit();
            self::json(201, ['ok' => true, 'id_ordem' => $id_ordem]);

        } catch (DatabaseException $e) {
            $db?->rollback();
            error_log('[OrdemController] criar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    // ─── PATCH /api/ordens/:id ───────────────────────────────

    public static function atualizar(array $params): void
    {
        AccessControl::exigir_permissao('ordem_servico.editar');
        self::validar_csrf();

        $id_ordem = self::validar_id($params['id'] ?? '');
        if ($id_ordem === false) { self::json(400, ['erro' => 'ID inválido.']); return; }

        $body = self::ler_body();
        if ($body === null) { self::json(400, ['erro' => 'Corpo inválido.']); return; }

        $id_funcionario = isset($body['id_funcionario']) && $body['id_funcionario'] !== ''
            ? (int)$body['id_funcionario'] : null;
        $id_cliente = (int)($body['id_cliente'] ?? 0);
        $id_veiculo = (int)($body['id_veiculo'] ?? 0);
        $tipo_ordem = trim((string)($body['tipo_ordem'] ?? ''));
        $abertura   = trim((string)($body['abertura']   ?? ''));
        $prazo      = trim((string)($body['prazo']      ?? ''));

        if (!$id_cliente || !$id_veiculo || !$tipo_ordem || !$abertura || !$prazo) {
            self::json(422, ['erro' => 'Campos obrigatórios ausentes.']); return;
        }

        $db = null;
        try {
            $db = Database::get_instance();
            $db->begin_transaction();

            $afetados = $db->execute(
                'UPDATE ordem SET
                    id_funcionario  = :id_funcionario,
                    id_cliente      = :id_cliente,
                    id_veiculo      = :id_veiculo,
                    tipo_ordem      = :tipo_ordem,
                    diagnostico     = :diagnostico,
                    abertura        = :abertura,
                    prazo           = :prazo,
                    fechamento      = :fechamento,
                    conclusao_ordem = :conclusao_ordem,
                    mao_de_obra     = :mao_de_obra,
                    orcamento       = :orcamento
                  WHERE id_ordem = :id_ordem',
                [
                    ':id_funcionario'  => $id_funcionario,
                    ':id_cliente'      => $id_cliente,
                    ':id_veiculo'      => $id_veiculo,
                    ':tipo_ordem'      => $tipo_ordem,
                    ':diagnostico'     => trim((string)($body['diagnostico']     ?? '')) ?: null,
                    ':abertura'        => $abertura,
                    ':prazo'           => $prazo,
                    ':fechamento'      => trim((string)($body['fechamento']      ?? '')) ?: null,
                    ':conclusao_ordem' => trim((string)($body['conclusao_ordem'] ?? '')) ?: null,
                    ':mao_de_obra'     => isset($body['mao_de_obra']) && $body['mao_de_obra'] !== ''
                                             ? (float)$body['mao_de_obra'] : null,
                    ':orcamento'       => isset($body['orcamento']) && $body['orcamento'] !== ''
                                             ? (float)$body['orcamento'] : null,
                    ':id_ordem'        => $id_ordem,
                ]
            );

            if ($afetados === 0) {
                $db->rollback();
                self::json(404, ['erro' => 'OS não encontrada.']); return;
            }

            self::salvar_pecas($db, $id_ordem, $body['pecas'] ?? []);

            $db->commit();
            self::json(200, ['ok' => true]);

        } catch (DatabaseException $e) {
            $db?->rollback();
            error_log('[OrdemController] atualizar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    // ─── PATCH /api/ordens/:id/fechar ───────────────────────

    public static function fechar(array $params): void
    {
        AccessControl::exigir_permissao('ordem_servico.fechar');
        self::validar_csrf();

        $id_ordem = self::validar_id($params['id'] ?? '');
        if ($id_ordem === false) { self::json(400, ['erro' => 'ID inválido.']); return; }

        $body = self::ler_body();
        if ($body === null) { self::json(400, ['erro' => 'Corpo inválido.']); return; }

        $fechamento      = trim((string)($body['fechamento']      ?? '')) ?: date('Y-m-d');
        $conclusao_ordem = trim((string)($body['conclusao_ordem'] ?? '')) ?: null;
        $mao_de_obra     = (float)($body['mao_de_obra'] ?? 0);
        $orcamento       = (float)($body['orcamento']   ?? 0);

        $db = null;
        try {
            $db = Database::get_instance();
            $db->begin_transaction();

            $afetados = $db->execute(
                "UPDATE ordem SET
                    status          = 'concluida',
                    fechamento      = :fechamento,
                    conclusao_ordem = :conclusao_ordem,
                    mao_de_obra     = :mao_de_obra,
                    orcamento       = :orcamento
                  WHERE id_ordem = :id_ordem
                    AND status NOT IN ('concluida', 'cancelada')",
                [
                    ':fechamento'      => $fechamento,
                    ':conclusao_ordem' => $conclusao_ordem,
                    ':mao_de_obra'     => $mao_de_obra,
                    ':orcamento'       => $orcamento,
                    ':id_ordem'        => $id_ordem,
                ]
            );

            if ($afetados === 0) {
                $db->rollback();
                self::json(404, ['erro' => 'OS não encontrada ou já finalizada.']); return;
            }

            if (!empty($body['pecas']) && is_array($body['pecas'])) {
                self::salvar_pecas($db, $id_ordem, $body['pecas']);
            }

            $id_func = self::id_funcionario_sessao();
            self::registrar_log($db, $id_func, "OS #{$id_ordem} concluída — fechamento: {$fechamento}");

            $db->commit();
            self::json(200, ['ok' => true]);

        } catch (DatabaseException $e) {
            $db?->rollback();
            error_log('[OrdemController] fechar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    // ─── DELETE /api/ordens/:id ──────────────────────────────

    public static function deletar(array $params): void
    {
        AccessControl::exigir_permissao('ordem_servico.excluir');
        self::validar_csrf();

        $id_ordem = self::validar_id($params['id'] ?? '');
        if ($id_ordem === false) { self::json(400, ['erro' => 'ID inválido.']); return; }

        try {
            $db = Database::get_instance();

            $afetados = $db->execute(
                'DELETE FROM ordem WHERE id_ordem = :id',
                [':id' => $id_ordem]
            );

            if ($afetados === 0) {
                self::json(404, ['erro' => 'OS não encontrada.']); return;
            }

            $id_func = self::id_funcionario_sessao();
            self::registrar_log($db, $id_func, "OS #{$id_ordem} removida.");

            self::json(200, ['ok' => true]);

        } catch (DatabaseException $e) {
            error_log('[OrdemController] deletar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }
}
