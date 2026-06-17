<?php

declare(strict_types=1);

namespace Automax\Controllers;

use Automax\Config\Database;
use Automax\Config\DatabaseException;

/**
 * Área autenticada do cliente: gerenciamento dos próprios veículos,
 * consulta dos próprios agendamentos e gestão de foto de perfil.
 *
 * Todas as operações são restritas ao id_cliente da sessão — um cliente
 * nunca recebe ou altera dados de outro cliente.
 */
class ClienteController
{
    private const AVATAR_DIR       = '/var/www/html/uploads/avatars/';
    private const AVATAR_URL       = '/uploads/avatars/';
    private const AVATAR_MAX_BYTES = 5_242_880; // 5 MB

    public static function listar_veiculos(): void
    {
        $id_cliente = self::id_cliente_sessao();

        try {
            $db = Database::get_instance();
            $veiculos = $db->query_all(
                'SELECT id_veiculo, marca, cor, ano, modelo, placa
                   FROM veiculos
                  WHERE id_cliente = :id_cliente
                  ORDER BY id_veiculo DESC',
                [':id_cliente' => $id_cliente]
            );
            self::json(200, $veiculos);
        } catch (DatabaseException $e) {
            error_log('[ClienteController] listar_veiculos: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function criar_veiculo(): void
    {
        self::validar_csrf();

        $id_cliente = self::id_cliente_sessao();
        $body       = self::ler_body();

        if ($body === null) {
            self::json(400, ['erro' => 'Corpo inválido.']);
            return;
        }

        [$marca, $modelo, $ano, $cor, $placa, $erros] = self::extrair_e_validar_veiculo($body);
        if (!empty($erros)) {
            self::json(422, ['erro' => implode(' ', $erros)]);
            return;
        }

        try {
            $db = Database::get_instance();

            if (self::placa_em_uso($db, $placa)) {
                self::json(409, ['erro' => 'Esta placa já está cadastrada no sistema.']);
                return;
            }

            $id_veiculo = $db->insert(
                'INSERT INTO veiculos (marca, cor, ano, modelo, placa, id_cliente)
                 VALUES (:marca, :cor, :ano, :modelo, :placa, :id_cliente)',
                [
                    ':marca'      => $marca,
                    ':cor'        => $cor,
                    ':ano'        => $ano,
                    ':modelo'     => $modelo,
                    ':placa'      => $placa,
                    ':id_cliente' => $id_cliente,
                ]
            );

            self::json(201, [
                'id_veiculo' => $id_veiculo,
                'marca'      => $marca,
                'cor'        => $cor,
                'ano'        => $ano,
                'modelo'     => $modelo,
                'placa'      => $placa,
            ]);
        } catch (DatabaseException $e) {
            error_log('[ClienteController] criar_veiculo: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function atualizar_veiculo(array $params): void
    {
        self::validar_csrf();

        $id_cliente = self::id_cliente_sessao();
        $id_veiculo = self::validar_id($params['id'] ?? '');

        if ($id_veiculo === false) {
            self::json(400, ['erro' => 'ID inválido.']);
            return;
        }

        $body = self::ler_body();
        if ($body === null) {
            self::json(400, ['erro' => 'Corpo inválido.']);
            return;
        }

        [$marca, $modelo, $ano, $cor, $placa, $erros] = self::extrair_e_validar_veiculo($body);
        if (!empty($erros)) {
            self::json(422, ['erro' => implode(' ', $erros)]);
            return;
        }

        try {
            $db = Database::get_instance();

            if (self::placa_em_uso($db, $placa, $id_veiculo)) {
                self::json(409, ['erro' => 'Esta placa já está cadastrada no sistema.']);
                return;
            }

            $afetados = $db->execute(
                'UPDATE veiculos
                    SET marca = :marca, cor = :cor, ano = :ano, modelo = :modelo, placa = :placa
                  WHERE id_veiculo = :id_veiculo AND id_cliente = :id_cliente',
                [
                    ':marca'      => $marca,
                    ':cor'        => $cor,
                    ':ano'        => $ano,
                    ':modelo'     => $modelo,
                    ':placa'      => $placa,
                    ':id_veiculo' => $id_veiculo,
                    ':id_cliente' => $id_cliente,
                ]
            );

            if ($afetados === 0) {
                self::json(404, ['erro' => 'Veículo não encontrado.']);
                return;
            }

            self::json(200, [
                'id_veiculo' => $id_veiculo,
                'marca'      => $marca,
                'cor'        => $cor,
                'ano'        => $ano,
                'modelo'     => $modelo,
                'placa'      => $placa,
            ]);
        } catch (DatabaseException $e) {
            error_log('[ClienteController] atualizar_veiculo: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function deletar_veiculo(array $params): void
    {
        self::validar_csrf();

        $id_cliente = self::id_cliente_sessao();
        $id_veiculo = self::validar_id($params['id'] ?? '');

        if ($id_veiculo === false) {
            self::json(400, ['erro' => 'ID inválido.']);
            return;
        }

        try {
            $db = Database::get_instance();

            $afetados = $db->execute(
                'DELETE FROM veiculos WHERE id_veiculo = :id_veiculo AND id_cliente = :id_cliente',
                [':id_veiculo' => $id_veiculo, ':id_cliente' => $id_cliente]
            );

            if ($afetados === 0) {
                self::json(404, ['erro' => 'Veículo não encontrado.']);
                return;
            }

            self::json(200, ['ok' => true]);
        } catch (DatabaseException $e) {
            error_log('[ClienteController] deletar_veiculo: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function listar_agendamentos(): void
    {
        $id_cliente = self::id_cliente_sessao();

        try {
            $db = Database::get_instance();
            $agendamentos = $db->query_all(
                'SELECT id, servico, marca, modelo, placa, data_preferida, turno, status, criado_em
                   FROM agendamentos
                  WHERE id_cliente = :id_cliente
                  ORDER BY data_preferida DESC, id DESC',
                [':id_cliente' => $id_cliente]
            );
            self::json(200, $agendamentos);
        } catch (DatabaseException $e) {
            error_log('[ClienteController] listar_agendamentos: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function perfil_get(): void
    {
        $id_cliente = self::id_cliente_sessao();

        try {
            $db  = Database::get_instance();
            $row = $db->query_one(
                'SELECT nome_cliente, email, foto_perfil FROM clientes WHERE id_cliente = :id',
                [':id' => $id_cliente]
            );

            if ($row === null) {
                self::json(404, ['erro' => 'Cliente não encontrado.']);
                return;
            }

            self::json(200, [
                'nome'     => $row['nome_cliente'],
                'email'    => $row['email'],
                'foto_url' => $row['foto_perfil']
                               ? self::AVATAR_URL . basename($row['foto_perfil'])
                               : null,
            ]);
        } catch (DatabaseException $e) {
            error_log('[ClienteController] perfil_get: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function foto_upload(): void
    {
        self::validar_csrf();

        $id_cliente = self::id_cliente_sessao();

        if (empty($_FILES['foto'])) {
            self::json(400, ['erro' => 'Arquivo não recebido.']);
            return;
        }

        if ($_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
            $erro_upload = match ($_FILES['foto']['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande. Máximo 5 MB.',
                default => 'Falha no upload. Tente novamente.',
            };
            $status = $_FILES['foto']['error'] === UPLOAD_ERR_INI_SIZE
                || $_FILES['foto']['error'] === UPLOAD_ERR_FORM_SIZE
                ? 413
                : 400;
            self::json($status, ['erro' => $erro_upload]);
            return;
        }

        $tmp  = $_FILES['foto']['tmp_name'];
        $size = $_FILES['foto']['size'];

        if ($size > self::AVATAR_MAX_BYTES) {
            self::json(413, ['erro' => 'Arquivo muito grande. Máximo 5 MB.']);
            return;
        }

        $mime             = mime_content_type($tmp);
        $tipos_permitidos = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

        if (!in_array($mime, $tipos_permitidos, true)) {
            self::json(415, ['erro' => 'Formato inválido. Use JPEG, PNG, WEBP ou GIF.']);
            return;
        }

        $imagem_src = self::carregar_imagem_por_mime($tmp, $mime);

        if ($imagem_src === null) {
            self::json(422, ['erro' => 'Não foi possível processar a imagem.']);
            return;
        }

        $imagem_final = self::redimensionar_para_quadrado($imagem_src, 256);
        imagedestroy($imagem_src);

        if (!is_dir(self::AVATAR_DIR)) {
            mkdir(self::AVATAR_DIR, 0755, true);
        }

        $nome_arquivo = $id_cliente . '.webp';
        $caminho      = self::AVATAR_DIR . $nome_arquivo;

        if (!imagewebp($imagem_final, $caminho, 85)) {
            imagedestroy($imagem_final);
            self::json(500, ['erro' => 'Falha ao salvar a imagem.']);
            return;
        }

        imagedestroy($imagem_final);

        try {
            $db = Database::get_instance();
            $db->execute(
                'UPDATE clientes SET foto_perfil = :foto WHERE id_cliente = :id',
                [':foto' => $nome_arquivo, ':id' => $id_cliente]
            );

            self::json(200, ['foto_url' => self::AVATAR_URL . $nome_arquivo . '?v=' . time()]);
        } catch (DatabaseException $e) {
            error_log('[ClienteController] foto_upload: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    public static function foto_remover(): void
    {
        self::validar_csrf();

        $id_cliente = self::id_cliente_sessao();

        try {
            $db = Database::get_instance();
            $db->execute(
                'UPDATE clientes SET foto_perfil = NULL WHERE id_cliente = :id',
                [':id' => $id_cliente]
            );

            $caminho = self::AVATAR_DIR . $id_cliente . '.webp';
            if (file_exists($caminho)) {
                unlink($caminho);
            }

            self::json(200, ['ok' => true]);
        } catch (DatabaseException $e) {
            error_log('[ClienteController] foto_remover: ' . $e->getMessage());
            self::json(503, ['erro' => 'Serviço indisponível.']);
        }
    }

    /**
     * @return array{0:string,1:string,2:string,3:string,4:string,5:string[]}
     *         [marca, modelo, ano, cor, placa, erros]
     */
    private static function extrair_e_validar_veiculo(array $data): array
    {
        $marca  = trim($data['marca']  ?? '');
        $modelo = trim($data['modelo'] ?? '');
        $ano    = trim($data['ano']    ?? '');
        $cor    = trim($data['cor']    ?? '');
        $placa  = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $data['placa'] ?? ''));

        $erros = [];

        if (strlen($marca) < 2 || strlen($marca) > 100) {
            $erros[] = 'Marca do veículo inválida.';
        }

        if (strlen($modelo) < 2 || strlen($modelo) > 100) {
            $erros[] = 'Modelo do veículo inválido.';
        }

        if (!preg_match('/^(19|20)\d{2}(\/\d{2,4})?$/', $ano)) {
            $erros[] = 'Ano do veículo inválido.';
        }

        if (strlen($cor) < 2 || strlen($cor) > 50) {
            $erros[] = 'Cor do veículo inválida.';
        }

        if (!self::placa_valida($placa)) {
            $erros[] = 'Placa inválida. Use o formato ABC-1234 ou ABC1D23.';
        }

        return [$marca, $modelo, $ano, $cor, $placa, $erros];
    }

    private static function placa_valida(string $placa): bool
    {
        $old_format      = '/^[A-Z]{3}\d{4}$/';
        $mercosul_format = '/^[A-Z]{3}\d[A-Z]\d{2}$/';
        return (bool) (preg_match($old_format, $placa) || preg_match($mercosul_format, $placa));
    }

    private static function placa_em_uso(Database $db, string $placa, ?int $excluir_id = null): bool
    {
        if ($excluir_id !== null) {
            $row = $db->query_one(
                'SELECT 1 FROM veiculos WHERE placa = :placa AND id_veiculo != :id LIMIT 1',
                [':placa' => $placa, ':id' => $excluir_id]
            );
        } else {
            $row = $db->query_one(
                'SELECT 1 FROM veiculos WHERE placa = :placa LIMIT 1',
                [':placa' => $placa]
            );
        }
        return $row !== null;
    }

    private static function carregar_imagem_por_mime(string $caminho, string $mime): \GdImage|null
    {
        return match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($caminho) ?: null,
            'image/png'  => imagecreatefrompng($caminho)  ?: null,
            'image/webp' => imagecreatefromwebp($caminho) ?: null,
            'image/gif'  => imagecreatefromgif($caminho)  ?: null,
            default      => null,
        };
    }

    private static function redimensionar_para_quadrado(\GdImage $src, int $tamanho): \GdImage
    {
        $w    = imagesx($src);
        $h    = imagesy($src);
        $lado = min($w, $h);

        $offset_x = (int) (($w - $lado) / 2);
        $offset_y = (int) (($h - $lado) / 2);

        $canvas = imagecreatetruecolor($tamanho, $tamanho);
        imagecopyresampled($canvas, $src, 0, 0, $offset_x, $offset_y, $tamanho, $tamanho, $lado, $lado);

        return $canvas;
    }

    private static function id_cliente_sessao(): int
    {
        return (int) ($_SESSION['cliente_id'] ?? 0);
    }

    private static function validar_id(mixed $raw): int|false
    {
        return filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
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
            self::json(403, ['erro' => 'Token inválido.']);
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