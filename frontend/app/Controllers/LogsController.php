<?php

declare(strict_types=1);

namespace Automax\Controllers;

use Automax\Config\Database;
use Automax\Config\DatabaseException;
use Automax\Auth\AccessControl;

class LogsController
{
    private const POR_PAGINA = 20;

    public static function listar(): void
    {
        AccessControl::exigir_permissao('logs.visualizar');

        $pagina      = self::validar_int_positivo($_GET['pagina']      ?? '1') ?: 1;
        $busca       = trim($_GET['busca']       ?? '');
        $funcionario = self::validar_int_positivo($_GET['funcionario'] ?? '') ?: null;
        $data_inicio = trim($_GET['data_inicio'] ?? '');
        $data_fim    = trim($_GET['data_fim']    ?? '');

        if ($data_inicio !== '' && !self::data_valida($data_inicio)) $data_inicio = '';
        if ($data_fim    !== '' && !self::data_valida($data_fim))    $data_fim    = '';

        try {
            $db     = Database::get_instance();
            $offset = ($pagina - 1) * self::POR_PAGINA;

            [$where_sql, $params_base] = self::montar_filtros($busca, $funcionario, $data_inicio, $data_fim);

            $total = (int) ($db->query_one(
                "SELECT COUNT(*) AS total
                   FROM logs l
                   LEFT JOIN funcionarios f ON f.id_funcionario = l.id_funcionario
                  {$where_sql}",
                $params_base
            )['total'] ?? 0);

            $linhas = $db->query(
                "SELECT l.id_log,
                        l.detalhe,
                        DATE_FORMAT(l.momento_acao, '%Y-%m-%d') AS data_acao,
                        l.momento_acao AS momento_completo,
                        f.id_funcionario,
                        f.nome_funcionario,
                        f.nivel_de_acesso
                   FROM logs l
                   LEFT JOIN funcionarios f ON f.id_funcionario = l.id_funcionario
                  {$where_sql}
                  ORDER BY l.momento_acao DESC
                  LIMIT :limite OFFSET :offset",
                array_merge($params_base, [':limite' => self::POR_PAGINA, ':offset' => $offset])
            );

            $registros = array_map(fn(array $r): array => [
                'id_log'           => (int) $r['id_log'],
                'detalhe'          =>       $r['detalhe'],
                'data_acao'        =>       $r['data_acao'],
                'momento_completo' =>       $r['momento_completo'],
                'id_funcionario'   => $r['id_funcionario'] ? (int)$r['id_funcionario'] : null,
                'nome_funcionario' =>       $r['nome_funcionario'],
                'nivel_de_acesso'  =>       $r['nivel_de_acesso'],
            ], $linhas);

            self::responder_json([
                'registros'     => $registros,
                'total'         => $total,
                'pagina'        => $pagina,
                'total_paginas' => max(1, (int) ceil($total / self::POR_PAGINA)),
            ]);

        } catch (DatabaseException) {
            self::responder_erro('Erro ao consultar banco de dados.', 500);
        }
    }

    public static function funcionarios_ativos(): void
    {
        AccessControl::exigir_permissao('logs.visualizar');

        try {
            $db   = Database::get_instance();
            $rows = $db->query(
                'SELECT id_funcionario, nome_funcionario, nivel_de_acesso
                   FROM funcionarios
                  ORDER BY nome_funcionario ASC'
            );

            self::responder_json(array_map(fn(array $r): array => [
                'id'    => (int) $r['id_funcionario'],
                'nome'  =>       $r['nome_funcionario'],
                'nivel' =>       $r['nivel_de_acesso'],
            ], $rows));

        } catch (DatabaseException) {
            self::responder_erro('Erro ao consultar banco de dados.', 500);
        }
    }

    private static function montar_filtros(string $busca, ?int $funcionario, string $data_inicio, string $data_fim): array
    {
        $conds  = [];
        $params = [];

        if ($busca !== '') {
            $conds[]          = '(f.nome_funcionario LIKE :busca OR l.detalhe LIKE :busca)';
            $params[':busca'] = '%' . $busca . '%';
        }

        if ($funcionario !== null) {
            $conds[]               = 'l.id_funcionario = :funcionario';
            $params[':funcionario'] = $funcionario;
        }

        if ($data_inicio !== '') {
            $conds[]               = 'DATE(l.momento_acao) >= :data_inicio';
            $params[':data_inicio'] = $data_inicio;
        }

        if ($data_fim !== '') {
            $conds[]            = 'DATE(l.momento_acao) <= :data_fim';
            $params[':data_fim'] = $data_fim;
        }

        $where_sql = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
        return [$where_sql, $params];
    }

    private static function data_valida(string $data): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $data);
        return $d && $d->format('Y-m-d') === $data;
    }

    private static function validar_int_positivo(mixed $valor): ?int
    {
        $int = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int !== false ? $int : null;
    }

    private static function responder_json(array $dados): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    }

    private static function responder_erro(string $mensagem, int $codigo): never
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['erro' => $mensagem], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
