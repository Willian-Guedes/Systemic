<?php

declare(strict_types=1);

namespace Automax\Controllers;

use Automax\Config\Database;
use Automax\Config\DatabaseException;
use Automax\Auth\AccessControl;
use Automax\Support\Logger;

/**
 * CRUD do estoque técnico interno (tabela `pecas`).
 *
 * Peça != Produto: peça é o item físico controlado internamente (nome,
 * quantidade, tipo livre, fornecedor), sem preço nem imagem — isso só
 * existe quando a peça é publicada como Produto na vitrine (ver
 * ProdutoEstoqueController::publicar_de_peca).
 */
class PecasController
{
    public static function listar(): void
    {
        AccessControl::exigir_permissao('estoque.visualizar');

        $por_pagina = 15;
        $pagina     = self::validar_int_positivo($_GET['pagina'] ?? '1') ?: 1;
        $busca      = trim($_GET['busca'] ?? '');
        $id_fornecedor = self::validar_int_positivo($_GET['fornecedor'] ?? '');

        try {
            $db     = Database::get_instance();
            $offset = ($pagina - 1) * $por_pagina;

            [$where_sql, $params_base] = self::montar_filtros($busca, $id_fornecedor);

            $total = (int) ($db->query_one(
                "SELECT COUNT(*) AS total FROM pecas p {$where_sql}",
                $params_base
            )['total'] ?? 0);

            $params_paginado = array_merge($params_base, [
                ':limite' => $por_pagina,
                ':offset' => $offset,
            ]);

            $linhas = $db->query(
                "SELECT p.id_peca, p.nome_peca, p.quantidade, p.tipo,
                        p.id_fornecedor, f.nome_fornecedor
                   FROM pecas p
                   JOIN fornecedores f ON f.id_fornecedor = p.id_fornecedor
                 {$where_sql}
                  ORDER BY p.nome_peca ASC
                  LIMIT :limite OFFSET :offset",
                $params_paginado
            );

            self::json(200, [
                'pecas'      => array_map(self::formatar_linha(...), $linhas),
                'total'      => $total,
                'pagina'     => $pagina,
                'por_pagina' => $por_pagina,
                'paginas'    => (int) ceil($total / $por_pagina) ?: 1,
            ]);
        } catch (DatabaseException $e) {
            error_log('[PecasController] listar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    /**
     * Busca simples e leve, sem paginação, usada pelo autocomplete da
     * Ordem de Serviço e pelo seletor "Publicar da peça" do modal de
     * Produto. Devolve no máximo 8 resultados.
     */
    public static function buscar_rapido(): void
    {
        AccessControl::exigir_permissao('estoque.visualizar');

        $termo = trim($_GET['q'] ?? '');
        if (mb_strlen($termo) < 2) {
            self::json(200, ['pecas' => []]);
            return;
        }

        try {
            $db     = Database::get_instance();
            $linhas = $db->query(
                'SELECT p.id_peca, p.nome_peca, p.quantidade, p.tipo,
                        p.id_fornecedor, f.nome_fornecedor
                   FROM pecas p
                   JOIN fornecedores f ON f.id_fornecedor = p.id_fornecedor
                  WHERE p.nome_peca LIKE :termo
                  ORDER BY p.nome_peca ASC
                  LIMIT 8',
                [':termo' => '%' . $termo . '%']
            );

            self::json(200, ['pecas' => array_map(self::formatar_linha(...), $linhas)]);
        } catch (DatabaseException $e) {
            error_log('[PecasController] buscar_rapido: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function buscar(array $params): void
    {
        AccessControl::exigir_permissao('estoque.visualizar');

        $id = self::validar_int_positivo($params['id'] ?? '');
        if ($id === false) {
            self::json(400, ['erro' => 'ID inválido.']);
            return;
        }

        try {
            $db   = Database::get_instance();
            $peca = $db->query_one(
                'SELECT p.id_peca, p.nome_peca, p.quantidade, p.tipo,
                        p.id_fornecedor, f.nome_fornecedor
                   FROM pecas p
                   JOIN fornecedores f ON f.id_fornecedor = p.id_fornecedor
                  WHERE p.id_peca = :id
                  LIMIT 1',
                [':id' => $id]
            );

            if ($peca === null) {
                self::json(404, ['erro' => 'Peça não encontrada.']);
                return;
            }

            self::json(200, self::formatar_linha($peca));
        } catch (DatabaseException $e) {
            error_log('[PecasController] buscar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function criar(): void
    {
        AccessControl::exigir_permissao('estoque.editar');
        self::validar_csrf();

        $body = self::ler_body();
        if ($body === null) {
            self::json(400, ['erro' => 'Corpo da requisição inválido.']);
            return;
        }

        $erros = self::validar_campos($body);
        if (!empty($erros)) {
            self::json(422, ['erro' => implode(' ', $erros)]);
            return;
        }

        try {
            $db = Database::get_instance();

            if (!self::fornecedor_existe($db, (int) $body['id_fornecedor'])) {
                self::json(422, ['erro' => 'Fornecedor selecionado não existe.']);
                return;
            }

            $id = $db->insert(
                'INSERT INTO pecas (nome_peca, quantidade, tipo, id_fornecedor)
                 VALUES (:nome_peca, :quantidade, :tipo, :id_fornecedor)',
                self::extrair_params($body)
            );

            Logger::registrar("Peça \"{$body['nome_peca']}\" cadastrada no estoque interno.");

            self::json(201, ['id_peca' => $id, 'mensagem' => 'Peça cadastrada com sucesso.']);
        } catch (DatabaseException $e) {
            error_log('[PecasController] criar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function atualizar(array $params): void
    {
        AccessControl::exigir_permissao('estoque.editar');
        self::validar_csrf();

        $id = self::validar_int_positivo($params['id'] ?? '');
        if ($id === false) {
            self::json(400, ['erro' => 'ID inválido.']);
            return;
        }

        $body = self::ler_body();
        if ($body === null) {
            self::json(400, ['erro' => 'Corpo da requisição inválido.']);
            return;
        }

        $erros = self::validar_campos($body);
        if (!empty($erros)) {
            self::json(422, ['erro' => implode(' ', $erros)]);
            return;
        }

        try {
            $db = Database::get_instance();

            if (!self::fornecedor_existe($db, (int) $body['id_fornecedor'])) {
                self::json(422, ['erro' => 'Fornecedor selecionado não existe.']);
                return;
            }

            $rows = $db->execute(
                'UPDATE pecas
                    SET nome_peca = :nome_peca, quantidade = :quantidade,
                        tipo = :tipo, id_fornecedor = :id_fornecedor
                  WHERE id_peca = :id',
                array_merge(self::extrair_params($body), [':id' => $id])
            );

            if ($rows === 0) {
                self::json(404, ['erro' => 'Peça não encontrada.']);
                return;
            }

            Logger::registrar("Peça \"{$body['nome_peca']}\" atualizada (id #{$id}).");

            self::json(200, ['mensagem' => 'Peça atualizada com sucesso.']);
        } catch (DatabaseException $e) {
            error_log('[PecasController] atualizar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function ajustar_quantidade(array $params): void
    {
        AccessControl::exigir_permissao('estoque.editar');
        self::validar_csrf();

        $id = self::validar_int_positivo($params['id'] ?? '');
        if ($id === false) {
            self::json(400, ['erro' => 'ID inválido.']);
            return;
        }

        $body  = self::ler_body();
        $delta = filter_var($body['delta'] ?? null, FILTER_VALIDATE_INT);
        if ($delta === false || $delta === null) {
            self::json(422, ['erro' => 'Campo "delta" deve ser um inteiro.']);
            return;
        }

        try {
            $db    = Database::get_instance();
            $atual = $db->query_one(
                'SELECT nome_peca, quantidade FROM pecas WHERE id_peca = :id LIMIT 1',
                [':id' => $id]
            );

            if ($atual === null) {
                self::json(404, ['erro' => 'Peça não encontrada.']);
                return;
            }

            $nova_quantidade = (int) $atual['quantidade'] + $delta;
            if ($nova_quantidade < 0) {
                self::json(422, ['erro' => 'Quantidade não pode ser negativa.']);
                return;
            }

            $db->execute(
                'UPDATE pecas SET quantidade = :quantidade WHERE id_peca = :id',
                [':quantidade' => $nova_quantidade, ':id' => $id]
            );

            $sinal = $delta > 0 ? '+' : '';
            Logger::registrar("Quantidade de \"{$atual['nome_peca']}\" ajustada ({$sinal}{$delta}) — novo total: {$nova_quantidade}.");

            self::json(200, ['quantidade' => $nova_quantidade, 'mensagem' => 'Quantidade ajustada.']);
        } catch (DatabaseException $e) {
            error_log('[PecasController] ajustar_quantidade: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function deletar(array $params): void
    {
        AccessControl::exigir_permissao('estoque.editar');
        self::validar_csrf();

        $id = self::validar_int_positivo($params['id'] ?? '');
        if ($id === false) {
            self::json(400, ['erro' => 'ID inválido.']);
            return;
        }

        try {
            $db   = Database::get_instance();
            $peca = $db->query_one('SELECT nome_peca FROM pecas WHERE id_peca = :id LIMIT 1', [':id' => $id]);

            $tem_produto_publicado = $db->query_one(
                'SELECT 1 FROM produto_peca_origem WHERE id_peca = :id LIMIT 1',
                [':id' => $id]
            );

            if ($tem_produto_publicado !== null) {
                self::json(409, ['erro' => 'Esta peça tem produtos publicados na vitrine vinculados a ela. Remova os produtos antes de excluir a peça.']);
                return;
            }

            $rows = $db->execute('DELETE FROM pecas WHERE id_peca = :id', [':id' => $id]);

            if ($rows === 0) {
                self::json(404, ['erro' => 'Peça não encontrada.']);
                return;
            }

            Logger::registrar("Peça \"{$peca['nome_peca']}\" removida do estoque interno.");

            self::json(200, ['mensagem' => 'Peça removida do estoque.']);
        } catch (DatabaseException $e) {
            error_log('[PecasController] deletar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    private static function formatar_linha(array $r): array
    {
        return [
            'id'              => (int) $r['id_peca'],
            'nome'            => $r['nome_peca'],
            'quantidade'      => (int) $r['quantidade'],
            'tipo'            => $r['tipo'],
            'id_fornecedor'   => (int) $r['id_fornecedor'],
            'nome_fornecedor' => $r['nome_fornecedor'],
        ];
    }

    private static function montar_filtros(string $busca, int|false $id_fornecedor): array
    {
        $condicoes = [];
        $params    = [];

        if ($busca !== '') {
            $condicoes[] = 'p.nome_peca LIKE :busca';
            $params[':busca'] = '%' . $busca . '%';
        }

        if ($id_fornecedor !== false) {
            $condicoes[] = 'p.id_fornecedor = :fornecedor';
            $params[':fornecedor'] = $id_fornecedor;
        }

        $where_sql = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';
        return [$where_sql, $params];
    }

    private static function validar_campos(array $body): array
    {
        $erros = [];

        $nome = trim($body['nome_peca'] ?? '');
        if ($nome === '') {
            $erros[] = 'Nome da peça é obrigatório.';
        } elseif (mb_strlen($nome) > 255) {
            $erros[] = 'Nome deve ter no máximo 255 caracteres.';
        }

        $quantidade = filter_var($body['quantidade'] ?? null, FILTER_VALIDATE_INT);
        if ($quantidade === false || $quantidade === null || $quantidade < 0) {
            $erros[] = 'Quantidade deve ser um inteiro não-negativo.';
        }

        $id_fornecedor = filter_var($body['id_fornecedor'] ?? null, FILTER_VALIDATE_INT);
        if ($id_fornecedor === false || $id_fornecedor === null || $id_fornecedor <= 0) {
            $erros[] = 'Fornecedor é obrigatório.';
        }

        $tipo = trim($body['tipo'] ?? '');
        if (mb_strlen($tipo) > 100) {
            $erros[] = 'Tipo deve ter no máximo 100 caracteres.';
        }

        return $erros;
    }

    private static function extrair_params(array $body): array
    {
        $tipo = trim($body['tipo'] ?? '');

        return [
            ':nome_peca'     => trim($body['nome_peca']),
            ':quantidade'    => (int) $body['quantidade'],
            ':tipo'          => $tipo !== '' ? $tipo : null,
            ':id_fornecedor' => (int) $body['id_fornecedor'],
        ];
    }

    private static function fornecedor_existe(Database $db, int $id_fornecedor): bool
    {
        return $db->query_one(
            'SELECT 1 FROM fornecedores WHERE id_fornecedor = :id LIMIT 1',
            [':id' => $id_fornecedor]
        ) !== null;
    }

    private static function validar_int_positivo(mixed $valor): int|false
    {
        return filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    private static function ler_body(): ?array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function validar_csrf(): void
    {
        $token_header  = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $token_session = $_SESSION['csrf_token']       ?? '';

        if (!hash_equals($token_session, $token_header)) {
            self::json(403, ['erro' => 'Token CSRF inválido.']);
            exit;
        }
    }

    private static function json(int $status, mixed $data): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
