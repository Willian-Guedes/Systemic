<?php

declare(strict_types=1);

namespace Automax\Support;

use Automax\Config\Database;

/**
 * Registra ações de funcionários na tabela `logs`, para a tela de
 * auditoria em /logs. Usado por todos os controllers que alteram dados
 * (criar, atualizar, excluir, login, logout etc).
 *
 * Uma falha ao gravar o log nunca deve derrubar a ação principal do
 * usuário, então qualquer erro aqui é apenas registrado no error_log.
 */
class Logger
{
    public static function funcionario_atual(): ?int
    {
        $id = $_SESSION['funcionario_id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    public static function registrar(string $detalhe, ?int $id_funcionario = null): void
    {
        $id_funcionario ??= self::funcionario_atual();

        try {
            Database::get_instance()->execute(
                'INSERT INTO logs (id_funcionario, detalhe) VALUES (:id_funcionario, :detalhe)',
                [':id_funcionario' => $id_funcionario, ':detalhe' => $detalhe]
            );
        } catch (\Throwable $e) {
            error_log('[Logger] registrar: ' . $e->getMessage());
        }
    }
}
