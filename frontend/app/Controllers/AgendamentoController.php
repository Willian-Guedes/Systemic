<?php

declare(strict_types=1);

namespace Automax\Controllers;

use Automax\Config\Database;
use Automax\Config\DatabaseException;
use Automax\Support\Validador;
use Automax\Support\ErroValidacao;

/**
 * Agendamento de serviços (/pedir).
 *
 * A tabela `agendamentos` (oficina_db) não possui vínculo direto por
 * chave estrangeira com `clientes` ou `veiculos` — apenas campos livres
 * de contato e do veículo, preenchidos pelo formulário público.
 *
 * Para que o cliente autenticado consiga localizar depois seus próprios
 * agendamentos (ver ClienteController::listar_agendamentos), o e-mail
 * gravado é sempre o e-mail da conta autenticada (da sessão), nunca o
 * valor digitado no formulário — isso evita que alguém veja agendamentos
 * de outra pessoa só por informar o e-mail dela.
 */
class AgendamentoController
{
    private const TURNOS_VALIDOS = ['manha', 'tarde'];

    public static function criar(): void
    {
        self::validar_csrf();

        $email_conta = trim((string) ($_SESSION['cliente_email'] ?? ''));
        $body        = self::ler_body();

        if ($body === null) {
            self::json(400, ['ok' => false, 'erro' => 'Corpo inválido.']);
            return;
        }

        try {
            $dados = self::validar_e_normalizar($body);
        } catch (ErroValidacao $e) {
            self::json(422, ['ok' => false, 'erro' => $e->getMessage()]);
            return;
        }

        try {
            $db = Database::get_instance();

            $db->execute(
                'INSERT INTO agendamentos
                    (nome, telefone, email, placa, marca, modelo, ano,
                     combustivel, km, servico, sintomas, descricao, data_preferida, turno)
                 VALUES
                    (:nome, :telefone, :email, :placa, :marca, :modelo, :ano,
                     :combustivel, :km, :servico, :sintomas, :descricao, :data_preferida, :turno)',
                [
                    ':nome'           => $dados['nome'],
                    ':telefone'       => $dados['telefone'],
                    ':email'          => $email_conta ?: null,
                    ':placa'          => $dados['placa'],
                    ':marca'          => $dados['marca'],
                    ':modelo'         => $dados['modelo'],
                    ':ano'            => $dados['ano'],
                    ':combustivel'    => $dados['combustivel'],
                    ':km'             => $dados['km'],
                    ':servico'        => $dados['servico'],
                    ':sintomas'       => $dados['sintomas'],
                    ':descricao'      => $dados['descricao'],
                    ':data_preferida' => $dados['data_preferida'],
                    ':turno'          => $dados['turno'],
                ]
            );

            self::json(201, ['ok' => true]);
        } catch (DatabaseException $e) {
            error_log('[AgendamentoController] criar: ' . $e->getMessage());
            self::json(503, ['ok' => false, 'erro' => 'Serviço indisponível. Tente novamente.']);
        }
    }

    /**
     * Valida todos os campos do formulário público de agendamento e
     * devolve os valores já normalizados, prontos para o INSERT.
     * Lança ErroValidacao no primeiro campo inválido.
     */
    private static function validar_e_normalizar(array $body): array
    {
        $nome     = Validador::texto($body['nome']     ?? null, 'Nome', 3, 255);
        $telefone = Validador::texto($body['telefone'] ?? null, 'Telefone', 8, 30);
        $marca    = Validador::texto($body['marca']    ?? null, 'Marca do veículo', 2, 100);
        $modelo   = Validador::texto($body['modelo']   ?? null, 'Modelo do veículo', 2, 100);
        $servico  = Validador::texto($body['servico']  ?? null, 'Serviço', 1, 100);

        $placa       = Validador::texto($body['placa']       ?? null, 'Placa', 1, 8, false);
        $combustivel = Validador::texto($body['combustivel'] ?? null, 'Combustível', 1, 30, false);
        $sintomas    = Validador::texto($body['sintomas']    ?? null, 'Sintomas', 1, 255, false);
        $descricao   = Validador::texto($body['descricao']   ?? null, 'Descrição', 1, 1000, false);

        // Ano do veículo: intervalo plausível evita tanto negativos quanto
        // valores absurdos (ex.: ano 99999) sendo gravados no histórico.
        $ano = Validador::inteiro($body['ano'] ?? null, 'Ano do veículo', 1900, 2100, false);
        $km  = Validador::inteiro($body['km']  ?? null, 'Quilometragem', 0, 2_000_000, false);

        $data_preferida = trim((string) ($body['data_preferida'] ?? ''));
        if (!self::data_valida($data_preferida)) {
            throw new ErroValidacao('Data preferida inválida.');
        }

        $turno = Validador::whitelist($body['turno'] ?? '', 'Turno', [...self::TURNOS_VALIDOS, '']);

        return [
            'nome'           => $nome,
            'telefone'       => $telefone,
            'placa'          => $placa !== null ? strtoupper($placa) : null,
            'marca'          => $marca,
            'modelo'         => $modelo,
            'ano'            => $ano,
            'combustivel'    => $combustivel,
            'km'             => $km,
            'servico'        => $servico,
            'sintomas'       => $sintomas,
            'descricao'      => $descricao,
            'data_preferida' => $data_preferida,
            'turno'          => $turno !== '' ? $turno : null,
        ];
    }

    private static function data_valida(string $data): bool
    {
        $partes = \DateTime::createFromFormat('Y-m-d', $data);
        return $partes !== false && $partes->format('Y-m-d') === $data;
    }

    private static function ler_body(): ?array
    {
        $raw = $GLOBALS['_test_input'] ?? file_get_contents('php://input');
        if (empty($raw)) return null;

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private static function validar_csrf(): void
    {
        $token_header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $token_sessao = $_SESSION['csrf_token'] ?? '';

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