<?php

declare(strict_types=1);

namespace Automax\Controllers;

use Automax\Config\Database;
use Automax\Config\DatabaseException;
use Automax\Auth\AccessControl;

/**
 * Converte um agendamento (pedido feito pelo cliente ou pela recepção)
 * em uma ordem de serviço de verdade.
 *
 * O agendamento guarda os dados soltos (nome, telefone, placa avulsa),
 * sem ligação com `clientes`/`veiculos`. Esta classe tenta casar esses
 * dados soltos com um cliente e veículo já cadastrados; quando não
 * consegue com segurança, devolve o que já sabe e deixa o funcionário
 * completar manualmente — nunca inventa CPF/senha para criar um cliente
 * novo escondido.
 */
class AgendamentoConversaoController
{
    private const STATUS_PERMITIDOS_PARA_CHAMAR = ['pendente', 'confirmado'];

    public static function chamar_os(array $params): void
    {
        AccessControl::exigir_permissao('agendamentos.gerenciar');
        AccessControl::exigir_permissao('ordem_servico.criar');
        self::validar_csrf();

        $id_agendamento = self::validar_id($params['id'] ?? '');
        if ($id_agendamento === false) {
            self::json(400, ['ok' => false, 'erro' => 'ID inválido.']);
            return;
        }

        $body = self::ler_body();

        try {
            $db = Database::get_instance();

            $agendamento = $db->query_one(
                'SELECT * FROM agendamentos WHERE id = :id LIMIT 1',
                [':id' => $id_agendamento]
            );

            if (!$agendamento) {
                self::json(404, ['ok' => false, 'erro' => 'Agendamento não encontrado.']);
                return;
            }

            if (!in_array($agendamento['status'], self::STATUS_PERMITIDOS_PARA_CHAMAR, true)) {
                self::json(409, ['ok' => false, 'erro' => 'Este agendamento já foi tratado e não pode virar uma nova OS.']);
                return;
            }

            $id_cliente = self::resolver_cliente($db, $agendamento, $body);
            if ($id_cliente === null) {
                self::json(200, [
                    'ok'        => true,
                    'resolvido' => false,
                    'motivo'    => 'sem_cliente',
                ]);
                return;
            }

            $resolucao_veiculo = self::resolver_veiculo($db, $agendamento, $body, $id_cliente);
            if ($resolucao_veiculo['erro'] !== null) {
                self::json(422, ['ok' => false, 'erro' => $resolucao_veiculo['erro']]);
                return;
            }

            if ($resolucao_veiculo['id_veiculo'] === null) {
                self::json(200, [
                    'ok'          => true,
                    'resolvido'   => false,
                    'motivo'      => 'sem_veiculo',
                    'id_cliente'  => $id_cliente,
                ]);
                return;
            }

            $id_ordem = self::criar_ordem_e_encerrar_agendamento(
                $db,
                $agendamento,
                $id_cliente,
                $resolucao_veiculo['id_veiculo']
            );

            self::json(201, ['ok' => true, 'resolvido' => true, 'id_ordem' => $id_ordem]);

        } catch (DatabaseException $e) {
            error_log('[AgendamentoConversaoController] chamar_os: ' . $e->getMessage());
            self::json(503, ['ok' => false, 'erro' => 'Serviço indisponível.']);
        }
    }

    /**
     * Tenta descobrir o cliente dono deste agendamento, nesta ordem:
     *  1. O funcionário já escolheu manualmente (id_cliente no body);
     *  2. A placa do agendamento bate com um veículo já cadastrado
     *     (o dono do veículo é o cliente mais confiável possível);
     *  3. O e-mail do agendamento bate com um cliente já cadastrado.
     * Não encontrando nada, devolve null para o front pedir a escolha manual.
     */
    private static function resolver_cliente(Database $db, array $agendamento, array $body): ?int
    {
        $id_informado = self::validar_id($body['id_cliente'] ?? '');
        if ($id_informado !== false) {
            $existe = $db->query_one(
                'SELECT id_cliente FROM clientes WHERE id_cliente = :id LIMIT 1',
                [':id' => $id_informado]
            );
            return $existe ? $id_informado : null;
        }

        $placa = trim((string) ($agendamento['placa'] ?? ''));
        if ($placa !== '') {
            $veiculo = $db->query_one(
                'SELECT id_cliente FROM veiculos WHERE placa = :placa LIMIT 1',
                [':placa' => $placa]
            );
            if ($veiculo) {
                return (int) $veiculo['id_cliente'];
            }
        }

        $email = trim((string) ($agendamento['email'] ?? ''));
        if ($email !== '') {
            $cliente = $db->query_one(
                'SELECT id_cliente FROM clientes WHERE email = :email LIMIT 1',
                [':email' => $email]
            );
            if ($cliente) {
                return (int) $cliente['id_cliente'];
            }
        }

        return null;
    }

    /**
     * Tenta descobrir o veículo do agendamento, nesta ordem:
     *  1. O funcionário já escolheu um veículo existente (id_veiculo no body);
     *  2. O funcionário cadastrou um veículo novo (veiculo_novo no body);
     *  3. A placa do agendamento bate com um veículo já cadastrado,
     *     desde que esse veículo pertença ao cliente já resolvido.
     * Não encontrando nada, devolve id_veiculo nulo (sem erro) para o
     * front pedir os dados que faltam.
     */
    private static function resolver_veiculo(Database $db, array $agendamento, array $body, int $id_cliente): array
    {
        $id_informado = self::validar_id($body['id_veiculo'] ?? '');
        if ($id_informado !== false) {
            $pertence = $db->query_one(
                'SELECT id_veiculo FROM veiculos WHERE id_veiculo = :id AND id_cliente = :id_cliente LIMIT 1',
                [':id' => $id_informado, ':id_cliente' => $id_cliente]
            );
            return $pertence
                ? ['id_veiculo' => $id_informado, 'erro' => null]
                : ['id_veiculo' => null, 'erro' => 'Este veículo não pertence ao cliente selecionado.'];
        }

        if (!empty($body['veiculo_novo']) && is_array($body['veiculo_novo'])) {
            return self::criar_veiculo_novo($db, $body['veiculo_novo'], $id_cliente);
        }

        $placa = trim((string) ($agendamento['placa'] ?? ''));
        if ($placa !== '') {
            $veiculo = $db->query_one(
                'SELECT id_veiculo FROM veiculos WHERE placa = :placa AND id_cliente = :id_cliente LIMIT 1',
                [':placa' => $placa, ':id_cliente' => $id_cliente]
            );
            if ($veiculo) {
                return ['id_veiculo' => (int) $veiculo['id_veiculo'], 'erro' => null];
            }
        }

        return ['id_veiculo' => null, 'erro' => null];
    }

    private static function criar_veiculo_novo(Database $db, array $dados, int $id_cliente): array
    {
        $marca  = trim((string) ($dados['marca']  ?? ''));
        $modelo = trim((string) ($dados['modelo'] ?? ''));
        $cor    = trim((string) ($dados['cor']    ?? ''));
        $ano    = trim((string) ($dados['ano']    ?? ''));
        $placa  = strtoupper(trim((string) ($dados['placa'] ?? '')));

        if ($marca === '' || $modelo === '' || $cor === '' || $placa === '') {
            return ['id_veiculo' => null, 'erro' => 'Marca, modelo, cor e placa são obrigatórios para cadastrar o veículo.'];
        }

        $placa_em_uso = $db->query_one(
            'SELECT id_veiculo FROM veiculos WHERE placa = :placa LIMIT 1',
            [':placa' => $placa]
        );
        if ($placa_em_uso) {
            return ['id_veiculo' => null, 'erro' => 'Esta placa já está cadastrada em outro veículo.'];
        }

        $id_veiculo = $db->insert(
            'INSERT INTO veiculos (marca, cor, ano, modelo, placa, id_cliente)
             VALUES (:marca, :cor, :ano, :modelo, :placa, :id_cliente)',
            [
                ':marca'      => $marca,
                ':cor'        => $cor,
                ':ano'        => $ano !== '' ? $ano : '—',
                ':modelo'     => $modelo,
                ':placa'      => $placa,
                ':id_cliente' => $id_cliente,
            ]
        );

        return ['id_veiculo' => $id_veiculo, 'erro' => null];
    }

    private static function criar_ordem_e_encerrar_agendamento(
        Database $db,
        array $agendamento,
        int $id_cliente,
        int $id_veiculo
    ): int {
        $db->begin_transaction();

        try {
            $hoje  = date('Y-m-d');
            $prazo = max($hoje, (string) $agendamento['data_preferida']);

            $id_ordem = $db->insert(
                "INSERT INTO ordem
                    (id_funcionario, id_cliente, id_veiculo, tipo_ordem,
                     diagnostico, abertura, prazo, fechamento, conclusao_ordem,
                     mao_de_obra, orcamento, status)
                 VALUES
                    (:id_funcionario, :id_cliente, :id_veiculo, :tipo_ordem,
                     :diagnostico, :abertura, :prazo, NULL, NULL,
                     NULL, NULL, 'aberta')",
                [
                    ':id_funcionario' => self::id_funcionario_sessao(),
                    ':id_cliente'     => $id_cliente,
                    ':id_veiculo'     => $id_veiculo,
                    ':tipo_ordem'     => $agendamento['servico'],
                    ':diagnostico'    => self::montar_diagnostico_inicial($agendamento),
                    ':abertura'       => $hoje,
                    ':prazo'          => $prazo,
                ]
            );

            $db->execute(
                "UPDATE agendamentos SET status = 'em_atendimento' WHERE id = :id",
                [':id' => (int) $agendamento['id']]
            );

            self::registrar_log(
                $db,
                self::id_funcionario_sessao(),
                "OS #{$id_ordem} criada a partir do agendamento #{$agendamento['id']}"
            );

            $db->commit();
            return $id_ordem;

        } catch (DatabaseException $e) {
            $db->rollback();
            throw $e;
        }
    }

    private static function montar_diagnostico_inicial(array $agendamento): ?string
    {
        $partes = [];

        if (!empty($agendamento['sintomas'])) {
            $partes[] = "Sintomas relatados: {$agendamento['sintomas']}";
        }
        if (!empty($agendamento['descricao'])) {
            $partes[] = "Descrição do cliente: {$agendamento['descricao']}";
        }
        if (!empty($agendamento['km'])) {
            $partes[] = "KM informado no agendamento: {$agendamento['km']}";
        }

        return $partes ? implode("\n", $partes) : null;
    }

    private static function id_funcionario_sessao(): ?int
    {
        $id = $_SESSION['funcionario_id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    private static function registrar_log(Database $db, ?int $id_funcionario, string $detalhe): void
    {
        try {
            $db->execute(
                'INSERT INTO logs (id_funcionario, detalhe) VALUES (:id_funcionario, :detalhe)',
                [':id_funcionario' => $id_funcionario, ':detalhe' => $detalhe]
            );
        } catch (\Throwable $e) {
            error_log('[AgendamentoConversaoController] registrar_log: ' . $e->getMessage());
        }
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