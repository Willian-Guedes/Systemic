<?php

declare(strict_types=1);

namespace Automax\Controllers;

use Automax\Config\Database;
use Automax\Config\DatabaseException;
use Automax\Auth\AccessControl;

class EstoqueController
{
    private const IMAGEM_DIR      = '/var/www/html/automax/uploads/produtos/';
    private const IMAGEM_URL      = '/uploads/produtos/';
    private const IMAGEM_MAX_BYTES = 5 * 1024 * 1024;
    private const TIPOS_PERMITIDOS = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public static function listar(): void
    {
        AccessControl::exigir_permissao('estoque.visualizar');

        $por_pagina = 15;
        $pagina     = self::validar_int_positivo($_GET['pagina'] ?? '1') ?: 1;
        $busca      = trim($_GET['busca'] ?? '');
        $categoria  = trim($_GET['categoria'] ?? '');

        $categorias_validas = ['pecas', 'fluidos', 'eletrico', 'todos', ''];
        if (!in_array($categoria, $categorias_validas, true)) {
            $categoria = '';
        }

        try {
            $db     = Database::get_instance();
            $offset = ($pagina - 1) * $por_pagina;

            [$where_sql, $params_base] = self::montar_filtros($busca, $categoria);

            $total = (int) ($db->query_one(
                "SELECT COUNT(*) AS total FROM produtos {$where_sql}",
                $params_base
            )['total'] ?? 0);

            $params_paginado = array_merge($params_base, [
                ':limite' => $por_pagina,
                ':offset' => $offset,
            ]);

            $linhas = $db->query(
                "SELECT id_produto, nome, preco, stock, imagem, categoria
                   FROM produtos
                 {$where_sql}
                  ORDER BY nome ASC
                  LIMIT :limite OFFSET :offset",
                $params_paginado
            );

            $produtos = array_map(fn(array $r): array => [
                'id'        => (int)   $r['id_produto'],
                'nome'      =>         $r['nome'],
                'preco'     => (float) $r['preco'],
                'stock'     => (int)   $r['stock'],
                'imagem'    =>         $r['imagem'],
                'categoria' =>         $r['categoria'],
            ], $linhas);

            self::json(200, [
                'produtos'   => $produtos,
                'total'      => $total,
                'pagina'     => $pagina,
                'por_pagina' => $por_pagina,
                'paginas'    => (int) ceil($total / $por_pagina),
            ]);
        } catch (DatabaseException $e) {
            error_log('[EstoqueController] listar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function imagem_upload(): void
    {
        AccessControl::exigir_permissao('estoque.editar');
        self::validar_csrf();

        if (empty($_FILES['imagem'])) {
            self::json(400, ['erro' => 'Arquivo não recebido.']);
            return;
        }

        if ($_FILES['imagem']['error'] !== UPLOAD_ERR_OK) {
            $status = in_array($_FILES['imagem']['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400;
            $erro   = $status === 413 ? 'Arquivo muito grande. Máximo 5 MB.' : 'Falha no upload.';
            self::json($status, ['erro' => $erro]);
            return;
        }

        if ($_FILES['imagem']['size'] > self::IMAGEM_MAX_BYTES) {
            self::json(413, ['erro' => 'Arquivo muito grande. Máximo 5 MB.']);
            return;
        }

        $tmp  = $_FILES['imagem']['tmp_name'];
        $mime = mime_content_type($tmp);

        if (!in_array($mime, self::TIPOS_PERMITIDOS, true)) {
            self::json(415, ['erro' => 'Formato inválido. Use JPEG, PNG, WEBP ou GIF.']);
            return;
        }

        $imagem_src = self::carregar_imagem($tmp, $mime);
        if ($imagem_src === null) {
            self::json(422, ['erro' => 'Não foi possível processar a imagem.']);
            return;
        }

        if (!is_dir(self::IMAGEM_DIR) && !@mkdir(self::IMAGEM_DIR, 0755, true) && !is_dir(self::IMAGEM_DIR)) {
            self::json(500, ['erro' => 'Não foi possível criar o diretório de uploads.']);
            return;
        }

        $nome_arquivo = uniqid('prod_', true) . '.webp';
        $caminho      = self::IMAGEM_DIR . $nome_arquivo;

        if (!imagewebp($imagem_src, $caminho, 85)) {
            imagedestroy($imagem_src);
            self::json(500, ['erro' => 'Falha ao salvar a imagem.']);
            return;
        }

        imagedestroy($imagem_src);

        self::json(200, ['imagem_url' => self::IMAGEM_URL . $nome_arquivo]);
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

        $erros = self::validar_campos_produto($body);
        if (!empty($erros)) {
            self::json(422, ['erro' => implode(' ', $erros)]);
            return;
        }

        try {
            $db = Database::get_instance();
            $id = $db->insert(
                'INSERT INTO produtos (nome, preco, stock, imagem, categoria, detalhes)
                 VALUES (:nome, :preco, :stock, :imagem, :categoria, :detalhes)',
                self::extrair_params($body)
            );

            self::json(201, ['id_produto' => $id, 'mensagem' => 'Produto criado com sucesso.']);
        } catch (DatabaseException $e) {
            error_log('[EstoqueController] criar: ' . $e->getMessage());
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
            $db      = Database::get_instance();
            $produto = $db->query_one(
                'SELECT id_produto, nome, preco, stock, imagem, categoria, detalhes
                   FROM produtos
                  WHERE id_produto = :id
                  LIMIT 1',
                [':id' => $id]
            );

            if ($produto === null) {
                self::json(404, ['erro' => 'Produto não encontrado.']);
                return;
            }

            self::json(200, [
                'id'        => (int)   $produto['id_produto'],
                'nome'      =>         $produto['nome'],
                'preco'     => (float) $produto['preco'],
                'stock'     => (int)   $produto['stock'],
                'imagem'    =>         $produto['imagem'],
                'categoria' =>         $produto['categoria'],
                'detalhes'  =>         $produto['detalhes'],
            ]);
        } catch (DatabaseException $e) {
            error_log('[EstoqueController] buscar: ' . $e->getMessage());
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

        $erros = self::validar_campos_produto($body);
        if (!empty($erros)) {
            self::json(422, ['erro' => implode(' ', $erros)]);
            return;
        }

        try {
            $db   = Database::get_instance();
            $rows = $db->execute(
                'UPDATE produtos
                    SET nome = :nome, preco = :preco, stock = :stock,
                        imagem = :imagem, categoria = :categoria, detalhes = :detalhes
                  WHERE id_produto = :id',
                array_merge(self::extrair_params($body), [':id' => $id])
            );

            if ($rows === 0) {
                self::json(404, ['erro' => 'Produto não encontrado.']);
                return;
            }

            self::json(200, ['mensagem' => 'Produto atualizado com sucesso.']);
        } catch (DatabaseException $e) {
            error_log('[EstoqueController] atualizar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function ajustar_stock(array $params): void
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

        $delta = filter_var($body['delta'] ?? null, FILTER_VALIDATE_INT);
        if ($delta === false || $delta === null) {
            self::json(422, ['erro' => 'Campo "delta" deve ser um inteiro.']);
            return;
        }

        try {
            $db = Database::get_instance();

            $atual = $db->query_one(
                'SELECT stock FROM produtos WHERE id_produto = :id LIMIT 1',
                [':id' => $id]
            );

            if ($atual === null) {
                self::json(404, ['erro' => 'Produto não encontrado.']);
                return;
            }

            $novo_stock = (int) $atual['stock'] + $delta;
            if ($novo_stock < 0) {
                self::json(422, ['erro' => 'Estoque não pode ser negativo.']);
                return;
            }

            $db->execute(
                'UPDATE produtos SET stock = :stock WHERE id_produto = :id',
                [':stock' => $novo_stock, ':id' => $id]
            );

            self::json(200, ['stock' => $novo_stock, 'mensagem' => 'Estoque ajustado.']);
        } catch (DatabaseException $e) {
            error_log('[EstoqueController] ajustar_stock: ' . $e->getMessage());
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
            $rows = $db->execute(
                'DELETE FROM produtos WHERE id_produto = :id',
                [':id' => $id]
            );

            if ($rows === 0) {
                self::json(404, ['erro' => 'Produto não encontrado.']);
                return;
            }

            self::json(200, ['mensagem' => 'Produto removido do estoque.']);
        } catch (DatabaseException $e) {
            error_log('[EstoqueController] deletar: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    private static function carregar_imagem(string $path, string $mime): \GdImage|null
    {
        $fn = match ($mime) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png'  => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
            'image/gif'  => 'imagecreatefromgif',
            default      => null,
        };

        if ($fn === null) return null;

        $img = @$fn($path);
        return $img instanceof \GdImage ? $img : null;
    }

    private static function montar_filtros(string $busca, string $categoria): array
    {
        $condicoes = [];
        $params    = [];

        if ($busca !== '') {
            $condicoes[] = 'nome LIKE :busca';
            $params[':busca'] = '%' . $busca . '%';
        }

        if ($categoria !== '' && $categoria !== 'todos') {
            $condicoes[] = 'categoria = :categoria';
            $params[':categoria'] = $categoria;
        }

        $where_sql = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';
        return [$where_sql, $params];
    }

    private static function validar_campos_produto(array $body): array
    {
        $erros = [];

        $nome = trim($body['nome'] ?? '');
        if ($nome === '') {
            $erros[] = 'Nome é obrigatório.';
        } elseif (mb_strlen($nome) > 255) {
            $erros[] = 'Nome deve ter no máximo 255 caracteres.';
        }

        $preco = filter_var($body['preco'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($preco === false || $preco < 0) {
            $erros[] = 'Preço deve ser um número positivo.';
        }

        $stock = filter_var($body['stock'] ?? null, FILTER_VALIDATE_INT);
        if ($stock === false || $stock === null || $stock < 0) {
            $erros[] = 'Estoque deve ser um inteiro não-negativo.';
        }

        $categorias_validas = ['pecas', 'fluidos', 'eletrico'];
        $categoria = trim($body['categoria'] ?? '');
        if (!in_array($categoria, $categorias_validas, true)) {
            $erros[] = 'Categoria deve ser: pecas, fluidos ou eletrico.';
        }

        $imagem = trim($body['imagem'] ?? '');
        if ($imagem === '') {
            $erros[] = 'Imagem é obrigatória.';
        }

        $detalhes = trim($body['detalhes'] ?? '');
        if ($detalhes === '') {
            $erros[] = 'Detalhes são obrigatórios.';
        }

        return $erros;
    }

    private static function extrair_params(array $body): array
    {
        return [
            ':nome'      => trim($body['nome']),
            ':preco'     => (float) $body['preco'],
            ':stock'     => (int)   $body['stock'],
            ':imagem'    => trim($body['imagem']),
            ':categoria' => trim($body['categoria']),
            ':detalhes'  => trim($body['detalhes']),
        ];
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
