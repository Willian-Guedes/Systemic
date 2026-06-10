# ============================================================
# migrate-psr4.ps1 — Migração PSR-4 do projeto Systemic/Automax
# Execute na raiz do repositório (onde fica o composer.json):
#   .\migrate-psr4.ps1
# ============================================================
$ErrorActionPreference = 'Stop'
$root = (Get-Location).Path

Write-Host ""
Write-Host "Verificando raiz do projeto..."
if (-not (Test-Path "$root\composer.json")) {
    Write-Host "ERRO: Execute na raiz do repositorio (onde fica composer.json)." -ForegroundColor Red
    exit 1
}

$fe = "$root\frontend"


# ── 1. Criar pastas PSR-4 ─────────────────────────────────────────────────
Write-Host ""
Write-Host "1/6 - Criando estrutura de pastas PSR-4..."
New-Item -ItemType Directory -Force -Path "$fe\app\Config"      | Out-Null
New-Item -ItemType Directory -Force -Path "$fe\app\Http"        | Out-Null
New-Item -ItemType Directory -Force -Path "$fe\app\Auth"        | Out-Null
New-Item -ItemType Directory -Force -Path "$fe\app\Controllers" | Out-Null


# ── 2. Escrever novos arquivos com namespace ─────────────────────────────

Write-Host ""
Write-Host "2/6 - Escrevendo arquivos com namespace correto..."

New-Item -ItemType Directory -Force -Path '$root\frontend\app\Config' | Out-Null
Write-Host '  -> frontend/app/Config/Database.php'
@'
<?php

declare(strict_types=1);

namespace Automax\Config;

class DatabaseException extends \RuntimeException {}

class Database
{
    private static ?Database $instance = null;
    private readonly \PDO $connection;

    private function __construct()
    {
        $host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: 'db';
        $name = $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'oficina_db';
        $user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'automax';
        $pass = $_ENV['DB_PASS'] ?? getenv('DB_PASS')
            ?: throw new \RuntimeException('Variável de ambiente DB_PASS não configurada.');

        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->connection = new \PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            throw new DatabaseException('Não foi possível conectar ao banco de dados.', 0, $e);
        }
    }

    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function query_one(string $sql, array $params = []): ?array
    {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function last_insert_id(): string
    {
        return $this->connection->lastInsertId();
    }

    public function begin_transaction(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollback(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }
}

'@ | Set-Content -Path '$root\frontend\app\Config\Database.php' -Encoding UTF8

New-Item -ItemType Directory -Force -Path '$root\frontend\app\Http' | Out-Null
Write-Host '  -> frontend/app/Http/Router.php'
@'
<?php

declare(strict_types=1);

namespace Automax\Http;

class RouteException extends \RuntimeException
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

class Route
{
    private const VALID_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];
    public readonly string $path;
    public readonly string $method;
    private readonly mixed $callback;
    private array $paramNames = [];
    private string $regex;

    public function __construct(string $path, string $method, callable $callback)
    {
        if (empty($path))
            throw new RouteException("Route path can't be empty.");

        $method = strtoupper($method);
        if (!in_array($method, self::VALID_METHODS, true))
            throw new RouteException("Invalid HTTP method '$method' for route '$path'.");

        $this->path     = $path;
        $this->method   = $method;
        $this->callback = $callback;
        $this->regex    = $this->buildRegex($path);
    }

    private function buildRegex(string $path): string
    {
        $pattern = preg_replace_callback('/:([a-zA-Z_][a-zA-Z0-9_]*)/', function ($matches) {
            $this->paramNames[] = $matches[1];
            return '([^/]+)';
        }, $path);

        return '#^' . $pattern . '$#';
    }

    public function matches(string $path): bool
    {
        return (bool) preg_match($this->regex, $path);
    }

    public function extractParams(string $path): array
    {
        preg_match($this->regex, $path, $matches);
        array_shift($matches);
        return array_combine($this->paramNames, $matches) ?: [];
    }

    public function run(array $params = []): void
    {
        call_user_func($this->callback, $params);
    }
}

class Router
{
    private const MIME_TYPES = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'html'  => 'text/html',
        'json'  => 'application/json',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
    ];

    protected array $routes = [];
    private string $staticDir;
    private string $basePath;

    private static function debug_enabled(): bool
    {
        return ($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: 'false') === 'true';
    }

    public function __construct(string $staticDir = __DIR__ . '/../../pages', string $basePath = '')
    {
        $this->staticDir = rtrim($staticDir, '/');
        $this->basePath  = rtrim($basePath, '/');
    }

    private function serveStatic(string $path): bool
    {
        $filePath = $this->staticDir . $path;
        $realFile = realpath($filePath);
        $realBase = realpath($this->staticDir);

        if ($realFile === false || $realBase === false)
            return false;

        if (!str_starts_with($realFile, $realBase))
            return false;

        if (!is_file($realFile))
            return false;

        $ext     = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
        $mime    = self::MIME_TYPES[$ext] ?? 'application/octet-stream';
        $charset = str_starts_with($mime, 'text/') ? '; charset=UTF-8' : '';

        http_response_code(200);
        header("Content-Type: {$mime}{$charset}");
        header('Content-Length: ' . filesize($realFile));
        readfile($realFile);
        return true;
    }

    private function addRoute(string $path, string $method, callable $callback): self
    {
        $this->routes[] = new Route($path, $method, $callback);
        return $this;
    }

    public function get(string $path, callable $callback): self
    {
        return $this->addRoute($path, 'GET', $callback);
    }

    public function post(string $path, callable $callback): self
    {
        return $this->addRoute($path, 'POST', $callback);
    }

    public function put(string $path, callable $callback): self
    {
        return $this->addRoute($path, 'PUT', $callback);
    }

    public function patch(string $path, callable $callback): self
    {
        return $this->addRoute($path, 'PATCH', $callback);
    }

    public function delete(string $path, callable $callback): self
    {
        return $this->addRoute($path, 'DELETE', $callback);
    }

    public function dispatch(string $path, string $method): void
    {
        if (self::debug_enabled()) {
            error_log("[Router] RAW path: '$path' | method: '$method'");
            error_log("[Router] REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
            error_log("[Router] SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'N/A'));
            header('X-Debug-Path: ' . $path);
            header('X-Debug-Method: ' . $method);
        }

        $method = strtoupper($method);
        $path   = parse_url($path, PHP_URL_PATH);

        if ($this->basePath !== '' && str_starts_with($path, $this->basePath)) {
            $path = substr($path, strlen($this->basePath));
        }
        $path = rtrim($path, '/') ?: '/';

        if ($method === 'GET' && $this->serveStatic($path))
            return;

        foreach ($this->routes as $route) {
            if ($route->method === $method && $route->matches($path)) {
                $route->run($route->extractParams($path));
                return;
            }
        }

        $pathExists = array_filter($this->routes, fn(Route $r) => $r->matches($path));

        if (!empty($pathExists)) {
            http_response_code(405);
            header('Allow: ' . implode(', ', array_map(fn(Route $r) => $r->method, $pathExists)));
            echo json_encode(['error' => 'Method Not Allowed']);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Not Found']);
    }

    public function run(): void
    {
        $this->dispatch(
            $_SERVER['REQUEST_URI']    ?? '/',
            $_SERVER['REQUEST_METHOD'] ?? 'GET'
        );
    }
}

'@ | Set-Content -Path '$root\frontend\app\Http\Router.php' -Encoding UTF8

New-Item -ItemType Directory -Force -Path '$root\frontend\app\Auth' | Out-Null
Write-Host '  -> frontend/app/Auth/AccessControl.php'
@'
<?php

declare(strict_types=1);

namespace Automax\Auth;

use Automax\Controllers\AuthController;

class AccessControl
{
    private const PERMISSIONS = [
        'gerente' => [
            'ordem_servico.visualizar',
            'ordem_servico.criar',
            'ordem_servico.editar',
            'ordem_servico.fechar',
            'ordem_servico.excluir',

            'clientes.visualizar',
            'clientes.cadastrar',
            'clientes.editar',

            'estoque.visualizar',
            'estoque.editar',
        ],

        'recepcao' => [
            'ordem_servico.visualizar',
            'ordem_servico.criar',

            'clientes.visualizar',
            'clientes.cadastrar',
            'clientes.editar',
        ],

        'mecanico' => [
            'ordem_servico.visualizar',
            'ordem_servico.criar',
            'ordem_servico.editar',
            'ordem_servico.fechar',

            'estoque.visualizar',
            'estoque.editar',
        ],
    ];

    public static function exigir_permissao(string $permissao): void
    {
        AuthController::exigir_autenticacao();

        $nivel = $_SESSION['nivel_de_acesso'] ?? '';

        if (!self::nivel_tem_permissao($nivel, $permissao)) {
            http_response_code(403);
            header('Content-Type: text/html; charset=UTF-8');
            include __DIR__ . '/../../pages/errors/403.html';
            exit;
        }
    }

    public static function nivel_tem_permissao(string $nivel, string $permissao): bool
    {
        $permissoes_do_nivel = self::PERMISSIONS[$nivel] ?? [];
        return in_array($permissao, $permissoes_do_nivel, strict: true);
    }

    public static function permissoes_do_nivel(string $nivel): array
    {
        return self::PERMISSIONS[$nivel] ?? [];
    }
}

'@ | Set-Content -Path '$root\frontend\app\Auth\AccessControl.php' -Encoding UTF8

New-Item -ItemType Directory -Force -Path '$root\frontend\app\Controllers' | Out-Null
Write-Host '  -> frontend/app/Controllers/AuthController.php'
@'
<?php

declare(strict_types=1);

namespace Automax\Controllers;

use Automax\Config\Database;
use Automax\Config\DatabaseException;

class AuthController
{
    public static function handle_login(): void
    {
        $email = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));
        $senha = trim($_POST['senha'] ?? '');

        if (empty($email) || empty($senha)) {
            self::redirect_with_error('/auth/login', 'Preencha o email e a senha.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::redirect_with_error('/auth/login', 'Formato de email inválido.');
        }

        $funcionario = self::buscar_funcionario_por_email($email);

        $hash_dummy   = '$2y$12$invalido.hash.para.timing.constante.AAAAAAAAAAAAAAAAAAA';
        $hash_real    = $funcionario['senha'] ?? $hash_dummy;
        $senha_valida = password_verify($senha, $hash_real);

        if ($funcionario === null || !$senha_valida) {
            self::redirect_with_error('/auth/login', 'Email ou senha incorretos.');
        }

        self::iniciar_sessao_autenticada($funcionario);

        if (PHP_SAPI === 'cli') {
            return;
        }

        header('Location: /ordem-servico');
        exit;
    }

    public static function handle_logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];
        session_destroy();

        if (PHP_SAPI === 'cli') {
            return;
        }

        header('Location: /auth/login');
        exit;
    }

    public static function exigir_autenticacao(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['funcionario_id'])) {
            if (PHP_SAPI === 'cli') {
                return;
            }

            header('Location: /auth/login');
            exit;
        }
    }

    public static function validate_csrf_token(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token_sessao = $_SESSION['csrf_token'] ?? '';
        $token_post   = $_POST['csrf_token']    ?? '';

        if (!$token_sessao || !hash_equals($token_sessao, $token_post)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Requisição inválida.';
            exit;
        }
    }

    private static function buscar_funcionario_por_email(string $email): ?array
    {
        $db = Database::get_instance();

        return $db->query_one(
            'SELECT id_funcionario, nome_funcionario, nivel_de_acesso, senha
               FROM funcionarios
              WHERE email = :email
              LIMIT 1',
            [':email' => $email]
        );
    }

    private static function iniciar_sessao_autenticada(array $funcionario): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_httponly' => true,
                'cookie_secure'   => true,
                'cookie_samesite' => 'Strict',
            ]);
        }

        session_regenerate_id(true);

        $_SESSION['funcionario_id']   = $funcionario['id_funcionario'];
        $_SESSION['funcionario_nome'] = $funcionario['nome_funcionario'];
        $_SESSION['nivel_de_acesso']  = $funcionario['nivel_de_acesso'];
        $_SESSION['autenticado_em']    = time();
        $_SESSION['csrf_token']        = bin2hex(random_bytes(32));
    }

    private static function redirect_with_error(string $destino, string $mensagem): never
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['flash_error'] = $mensagem;

        if (PHP_SAPI === 'cli') {
            throw new \RuntimeException("redirect:{$destino}");
        }

        header("Location: {$destino}");
        exit;
    }
}

'@ | Set-Content -Path '$root\frontend\app\Controllers\AuthController.php' -Encoding UTF8

New-Item -ItemType Directory -Force -Path '$root\frontend\app\Controllers' | Out-Null
Write-Host '  -> frontend/app/Controllers/CadastroController.php'
@'
<?php

declare(strict_types=1);

namespace Automax\Controllers;

use Automax\Config\Database;
use Automax\Config\DatabaseException;

class CadastroController
{
    public static function handle_page(): void
    {
        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<base href="/pages/cadastro/">';
        include __DIR__ . '/../../pages/cadastro/cadastro.html';
    }

    public static function handle_criar(): void
    {
        $body = self::read_json_body();

        if ($body === null) {
            self::respond(400, 'Corpo da requisição inválido ou ausente.');
            return;
        }

        $cliente_raw = $body['cliente'] ?? [];
        $veiculo_raw = $body['veiculo'] ?? [];

        $cliente_errors = self::validate_cliente($cliente_raw);
        $veiculo_errors = self::validate_veiculo($veiculo_raw);

        $all_errors = array_merge($cliente_errors, $veiculo_errors);
        if (!empty($all_errors)) {
            self::respond(422, implode(' ', $all_errors), ['fields' => $all_errors]);
            return;
        }

        $nome_cliente = trim($cliente_raw['nome_cliente']);
        $cpf          = preg_replace('/\D/', '', $cliente_raw['cpf']);
        $celular      = trim($cliente_raw['celular']);
        $email        = strtolower(trim($cliente_raw['email']));
        $senha        = $cliente_raw['senha'];

        $marca  = trim($veiculo_raw['marca']);
        $modelo = trim($veiculo_raw['modelo']);
        $ano    = trim($veiculo_raw['ano']);
        $cor    = trim($veiculo_raw['cor']);
        $placa  = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $veiculo_raw['placa']));

        try {
            $db = Database::get_instance();
        } catch (DatabaseException $e) {
            error_log('[CadastroController] DB connection error: ' . $e->getMessage());
            self::respond(503, 'Serviço temporariamente indisponível. Tente novamente em instantes.');
            return;
        }

        if (self::cpf_ja_cadastrado($db, $cpf)) {
            self::respond(409, 'Este CPF já está cadastrado. Tente fazer login.');
            return;
        }

        if (self::email_ja_cadastrado($db, $email)) {
            self::respond(409, 'Este e-mail já está em uso. Tente fazer login ou recuperar a senha.');
            return;
        }

        if (self::placa_ja_cadastrada($db, $placa)) {
            self::respond(409, 'Esta placa já está cadastrada no sistema.');
            return;
        }

        $senha_hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);

        try {
            $db->begin_transaction();

            $db->execute(
                'INSERT INTO clientes (nome_cliente, CPF, celular, email, senha)
                 VALUES (:nome, :cpf, :celular, :email, :senha)',
                [
                    ':nome'    => $nome_cliente,
                    ':cpf'     => $cpf,
                    ':celular' => $celular,
                    ':email'   => $email,
                    ':senha'   => $senha_hash,
                ]
            );

            $id_cliente = (int) $db->last_insert_id();

            $db->execute(
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

            $db->commit();

        } catch (\PDOException $e) {
            $db->rollback();

            if (self::is_duplicate_entry($e)) {
                self::respond(409, 'CPF, e-mail ou placa já cadastrado. Tente fazer login.');
                return;
            }

            error_log('[CadastroController] Transaction error: ' . $e->getMessage());
            self::respond(500, 'Erro ao criar a conta. Tente novamente.');
            return;
        }

        self::respond(201, 'Cadastro realizado com sucesso.', [
            'id_cliente' => $id_cliente,
        ]);
    }

    private static function validate_cliente(array $data): array
    {
        $errors = [];

        $nome = trim($data['nome_cliente'] ?? '');
        if (strlen($nome) < 3 || strlen($nome) > 255) {
            $errors[] = 'Nome completo inválido (3–255 caracteres).';
        }

        $cpf = preg_replace('/\D/', '', $data['cpf'] ?? '');
        if (!self::cpf_valido($cpf)) {
            $errors[] = 'CPF inválido.';
        }

        $celular    = trim($data['celular'] ?? '');
        $cel_digits = preg_replace('/\D/', '', $celular);
        if (strlen($cel_digits) < 10 || strlen($cel_digits) > 11) {
            $errors[] = 'Número de celular inválido.';
        }

        $email = trim($data['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $errors[] = 'E-mail inválido.';
        }

        $senha = $data['senha'] ?? '';
        if (strlen($senha) < 8) {
            $errors[] = 'A senha deve ter no mínimo 8 caracteres.';
        }

        return $errors;
    }

    private static function validate_veiculo(array $data): array
    {
        $errors = [];

        $marca = trim($data['marca'] ?? '');
        if (strlen($marca) < 2 || strlen($marca) > 100) {
            $errors[] = 'Marca do veículo inválida.';
        }

        $modelo = trim($data['modelo'] ?? '');
        if (strlen($modelo) < 2 || strlen($modelo) > 100) {
            $errors[] = 'Modelo do veículo inválido.';
        }

        $ano = trim($data['ano'] ?? '');
        if (!preg_match('/^(19|20)\d{2}(\/\d{2,4})?$/', $ano)) {
            $errors[] = 'Ano do veículo inválido.';
        }

        $cor = trim($data['cor'] ?? '');
        if (strlen($cor) < 2 || strlen($cor) > 50) {
            $errors[] = 'Cor do veículo inválida.';
        }

        $placa = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $data['placa'] ?? ''));
        if (!self::placa_valida($placa)) {
            $errors[] = 'Placa inválida. Use o formato ABC-1234 ou ABC1D23.';
        }

        return $errors;
    }

    private static function cpf_valido(string $cpf): bool
    {
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1+$/', $cpf)) {
            return false;
        }

        $calc_digit = function (string $slice, int $factor): int {
            $sum = 0;
            for ($i = 0; $i < strlen($slice); $i++) {
                $sum += (int)$slice[$i] * ($factor - $i);
            }
            $rest = ($sum * 10) % 11;
            return $rest >= 10 ? 0 : $rest;
        };

        $d1 = $calc_digit(substr($cpf, 0, 9), 10);
        $d2 = $calc_digit(substr($cpf, 0, 10), 11);

        return $d1 === (int)$cpf[9] && $d2 === (int)$cpf[10];
    }

    private static function placa_valida(string $placa): bool
    {
        $old_format      = '/^[A-Z]{3}\d{4}$/';
        $mercosul_format = '/^[A-Z]{3}\d[A-Z]\d{2}$/';
        return preg_match($old_format, $placa) || preg_match($mercosul_format, $placa);
    }

    private static function cpf_ja_cadastrado(Database $db, string $cpf): bool
    {
        return $db->query_one(
            'SELECT 1 FROM clientes WHERE CPF = :cpf LIMIT 1',
            [':cpf' => $cpf]
        ) !== null;
    }

    private static function email_ja_cadastrado(Database $db, string $email): bool
    {
        return $db->query_one(
            'SELECT 1 FROM clientes WHERE email = :email LIMIT 1',
            [':email' => $email]
        ) !== null;
    }

    private static function placa_ja_cadastrada(Database $db, string $placa): bool
    {
        return $db->query_one(
            'SELECT 1 FROM veiculos WHERE placa = :placa LIMIT 1',
            [':placa' => $placa]
        ) !== null;
    }

    private static function read_json_body(): ?array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) return null;

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function is_duplicate_entry(\PDOException $e): bool
    {
        return str_starts_with($e->getCode(), '23');
    }

    private static function respond(int $code, string $message, array $extra = []): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(
            array_merge(['message' => $message], $extra),
            JSON_UNESCAPED_UNICODE
        );
    }
}

'@ | Set-Content -Path '$root\frontend\app\Controllers\CadastroController.php' -Encoding UTF8

New-Item -ItemType Directory -Force -Path '$root\frontend\app\Controllers' | Out-Null
Write-Host '  -> frontend/app/Controllers/ProdutoController.php'
@'
<?php

declare(strict_types=1);

namespace Automax\Controllers;

use Automax\Config\Database;

class ProdutoNotFoundException extends \RuntimeException {}

class ProdutoController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::get_instance();
    }

    public function buscar_por_id(int $id_produto): array
    {
        $produto = $this->db->query_one(
            'SELECT id_produto, nome, preco, stock, imagem, categoria, detalhes
               FROM produtos
              WHERE id_produto = :id
              LIMIT 1',
            [':id' => $id_produto]
        );

        if ($produto === null) {
            throw new ProdutoNotFoundException("Produto #{$id_produto} não encontrado.");
        }

        return $produto;
    }

    public function buscar_relacionados(string $categoria, int $excluir_id, int $limite = 3): array
    {
        return $this->db->query(
            'SELECT id_produto, nome, preco, imagem
               FROM produtos
              WHERE categoria = :categoria
                AND id_produto != :excluir_id
              LIMIT :limite',
            [
                ':categoria'  => $categoria,
                ':excluir_id' => $excluir_id,
                ':limite'     => $limite,
            ]
        );
    }

    public function listar(int $pagina = 1, int $por_pagina = 12): array
    {
        $offset = ($pagina - 1) * $por_pagina;

        return $this->db->query(
            'SELECT id_produto, nome, preco, stock, imagem, categoria
               FROM produtos
              ORDER BY id_produto DESC
              LIMIT :limite OFFSET :offset',
            [
                ':limite'  => $por_pagina,
                ':offset'  => $offset,
            ]
        );
    }
}

'@ | Set-Content -Path '$root\frontend\app\Controllers\ProdutoController.php' -Encoding UTF8

New-Item -ItemType Directory -Force -Path '$root\frontend' | Out-Null
Write-Host '  -> frontend/index.php'
@'
<?php

declare(strict_types=1);

use Automax\Http\Router;
use Automax\Auth\AccessControl;
use Automax\Controllers\AuthController;
use Automax\Controllers\CadastroController;
use Automax\Controllers\ProdutoController;
use Automax\Controllers\ProdutoNotFoundException;
use Automax\Config\DatabaseException;

$router = new Router(__DIR__);

// ── Helpers ───────────────────────────────────────────────────────────────

function serve_page(string $base_href, string $file_path): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self'");
    echo '<base href="' . $base_href . '">';
    include $file_path;
}

function build_user_initials(string $nome): string
{
    $words = array_values(array_filter(explode(' ', $nome)));
    $first = mb_substr($words[0] ?? '', 0, 1, 'UTF-8');
    $last  = mb_substr(end($words) ?: '', 0, 1, 'UTF-8');
    return mb_strtoupper($first !== $last ? $first . $last : $first, 'UTF-8');
}

function serve_protected_page(string $base_href, string $file_path): void
{
    AuthController::exigir_autenticacao();

    $nivel = $_SESSION['nivel_de_acesso'] ?? '';

    $user_data = [
        'nome'       => $_SESSION['funcionario_nome'] ?? '',
        'nivel'      => $nivel,
        'iniciais'   => build_user_initials($_SESSION['funcionario_nome'] ?? ''),
        'permissoes' => AccessControl::permissoes_do_nivel($nivel),
        'csrf_token' => $_SESSION['csrf_token'] ?? '',
    ];

    $safe_json = json_encode($user_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self'");
    echo '<base href="' . $base_href . '">';
    echo "<script>window.__session_user = {$safe_json};</script>";
    include $file_path;
}

function serve_login_page(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $flash_error = $_SESSION['flash_error'] ?? null;
    unset($_SESSION['flash_error']);

    $safe_token = json_encode($_SESSION['csrf_token'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<base href="/pages/login/">';
    echo "<script>window.__csrf_token = {$safe_token};</script>";

    if ($flash_error !== null) {
        $safe_json = json_encode($flash_error, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        echo "<script>window.__flash_error = {$safe_json};</script>";
    }

    include __DIR__ . '/pages/login/login.html';
}

// ── Rotas públicas ────────────────────────────────────────────────────────

$router->get('/', function () {
    serve_page('/pages/homepage/', __DIR__ . '/pages/homepage/index.html');
});

$router->get('/auth/login', function () {
    serve_login_page();
});

$router->post('/auth/login', function () {
    AuthController::validate_csrf_token();
    AuthController::handle_login();
});

$router->post('/auth/logout', function () {
    AuthController::validate_csrf_token();
    AuthController::handle_logout();
});

// ── Rotas protegidas ──────────────────────────────────────────────────────

$router->get('/produtos', function () {
    serve_protected_page('/pages/produtos/', __DIR__ . '/pages/produtos/produtos.html');
});

$router->get('/produto/:id', function (array $params) {
    AuthController::exigir_autenticacao();

    $id_produto = filter_var($params['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id_produto === false) {
        http_response_code(400);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<p style="font-family:sans-serif;padding:2rem">ID de produto inválido.</p>';
        return;
    }

    $safe_id   = json_encode($id_produto);
    $safe_user = json_encode([
        'nome'       => $_SESSION['funcionario_nome'] ?? '',
        'nivel'      => $_SESSION['nivel_de_acesso']  ?? '',
        'iniciais'   => build_user_initials($_SESSION['funcionario_nome'] ?? ''),
        'permissoes' => AccessControl::permissoes_do_nivel($_SESSION['nivel_de_acesso'] ?? ''),
        'csrf_token' => $_SESSION['csrf_token'] ?? '',
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self'");
    echo '<base href="/pages/produto/">';
    echo "<script>window.__produto_id = {$safe_id}; window.__session_user = {$safe_user};</script>";
    include __DIR__ . '/pages/produto/produto.html';
});

$router->get('/ordem-servico', function () {
    AccessControl::exigir_permissao('ordem_servico.visualizar');
    serve_protected_page('/pages/ordem-servico/', __DIR__ . '/pages/ordem-servico/automax-os.html');
});

// ── API de produtos ───────────────────────────────────────────────────────

$router->get('/api/produto', function () {
    include __DIR__ . '/api/produto.php';
});

$router->get('/api/produtos', function () {
    include __DIR__ . '/api/produtos.php';
});

// ── Rotas de cadastro ─────────────────────────────────────────────────────

$router->get('/cadastro', function () {
    CadastroController::handle_page();
});

$router->post('/cadastro/criar', function () {
    AuthController::validate_csrf_token();
    CadastroController::handle_criar();
});

foreach (['/servicos', '/pedir'] as $rota) {
    $router->get($rota, function () use ($rota) {
        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        echo "<h2 style='font-family:sans-serif;padding:2rem'>Página <code>{$rota}</code> em construção.</h2>";
    });
}

$router->get('/busca', function () {
    serve_page('/pages/busca/', __DIR__ . '/pages/busca/busca.html');
});

$router->get('/api/busca', function () {
    include __DIR__ . '/api/busca.php';
});

// ── Dispatch ──────────────────────────────────────────────────────────────

try {
    $router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
} catch (Exception $e) {
    http_response_code(500);
    echo $e->getMessage();
}

'@ | Set-Content -Path '$root\frontend\index.php' -Encoding UTF8

New-Item -ItemType Directory -Force -Path '$root\tests' | Out-Null
Write-Host '  -> tests/bootstrap.php'
@'
<?php
declare(strict_types=1);

$_ENV['DB_HOST'] = 'localhost';
$_ENV['DB_NAME'] = 'oficina_db_test';
$_ENV['DB_USER'] = 'test';
$_ENV['DB_PASS'] = 'test';

// O autoloader do Composer carrega tudo via PSR-4 — não há require_once manual.
require_once __DIR__ . '/../vendor/autoload.php';

'@ | Set-Content -Path '$root\tests\bootstrap.php' -Encoding UTF8

New-Item -ItemType Directory -Force -Path '$root\tests\support' | Out-Null
Write-Host '  -> tests/support/DatabaseMock.php'
@'
<?php
declare(strict_types=1);

namespace Tests\Support;

use Automax\Config\Database;

class DatabaseMock extends Database
{
    private static ?self $instance = null;
    private array $queryOneReturns = [];
    private array $queryReturns = [];
    private int $executeReturn = 1;
    private string $lastInsertId = '1';
    public array $calls = [];

    public function __construct() {}

    public static function setup(): self
    {
        self::$instance = new self();
        self::injectIntoSingleton(self::$instance);
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
        self::injectIntoSingleton(null);
    }

    public function willReturnOnQueryOne(?array $row): self
    {
        $this->queryOneReturns[] = $row;
        return $this;
    }

    public function willReturnOnQuery(array $rows): self
    {
        $this->queryReturns[] = $rows;
        return $this;
    }

    public function willReturnOnExecute(int $rowCount): self
    {
        $this->executeReturn = $rowCount;
        return $this;
    }

    public function willReturnLastInsertId(string $id): self
    {
        $this->lastInsertId = $id;
        return $this;
    }

    public function query(string $sql, array $params = []): array
    {
        $this->calls[] = ['method' => 'query', 'sql' => $sql, 'params' => $params];
        return array_shift($this->queryReturns) ?? [];
    }

    public function query_one(string $sql, array $params = []): ?array
    {
        $this->calls[] = ['method' => 'query_one', 'sql' => $sql, 'params' => $params];
        return array_shift($this->queryOneReturns) ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->calls[] = ['method' => 'execute', 'sql' => $sql, 'params' => $params];
        return $this->executeReturn;
    }

    public function last_insert_id(): string
    {
        return $this->lastInsertId;
    }

    private static function injectIntoSingleton(?self $mock): void
    {
        $ref  = new \ReflectionClass(Database::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $mock);
    }
}

'@ | Set-Content -Path '$root\tests\support\DatabaseMock.php' -Encoding UTF8

New-Item -ItemType Directory -Force -Path '$root\tests\Unit\Auth' | Out-Null
Write-Host '  -> tests/Unit/Auth/AuthControllerTest.php'
@'
<?php
declare(strict_types=1);

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Tests\Support\DatabaseMock;
use Automax\Controllers\AuthController;

class AuthControllerTest extends TestCase
{
    private DatabaseMock $db;

    protected function setUp(): void
    {
        $_POST    = [];
        $_SESSION = [];
        $this->db = DatabaseMock::setup();
        if (session_status() === PHP_SESSION_NONE) session_start();
    }

    protected function tearDown(): void
    {
        DatabaseMock::reset();
        $_POST    = [];
        $_SESSION = [];
        while (ob_get_level() > 0) ob_end_clean();
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    }

    private function runLogin(): void
    {
        ob_start();
        try { AuthController::handle_login(); }
        catch (\Throwable $e) {}
        finally { ob_end_clean(); }
    }

    private function runLogout(): void
    {
        ob_start();
        try { AuthController::handle_logout(); }
        catch (\Throwable $e) {}
        finally { ob_end_clean(); }
    }

    private function senhaHash(string $s): string
    {
        return password_hash($s, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    private function funcionarioFake(string $senha, int $id = 1, string $nome = 'Admin', string $nivel = 'gerente'): array
    {
        return ['id_funcionario' => $id, 'nome_funcionario' => $nome, 'nivel_de_acesso' => $nivel, 'senha' => $this->senhaHash($senha)];
    }

    public function test_login_com_campos_vazios_registra_flash_error(): void
    {
        $_POST = ['email' => '', 'senha' => ''];
        $this->runLogin();
        $this->assertArrayHasKey('flash_error', $_SESSION);
        $this->assertStringContainsString('Preencha', $_SESSION['flash_error']);
    }

    public function test_login_com_email_invalido_registra_flash_error(): void
    {
        $_POST = ['email' => 'isso-nao-e-email', 'senha' => 'qualquercoisa'];
        $this->runLogin();
        $this->assertArrayHasKey('flash_error', $_SESSION);
        $this->assertStringContainsStringIgnoringCase('email', $_SESSION['flash_error']);
    }

    public function test_login_com_email_nao_cadastrado_registra_flash_error(): void
    {
        $this->db->willReturnOnQueryOne(null);
        $_POST = ['email' => 'naoexiste@automax.com', 'senha' => 'senha123'];
        $this->runLogin();
        $this->assertArrayHasKey('flash_error', $_SESSION);
        $this->assertStringContainsStringIgnoringCase('incorretos', $_SESSION['flash_error']);
    }

    public function test_login_com_senha_errada_nao_cria_sessao(): void
    {
        $this->db->willReturnOnQueryOne($this->funcionarioFake('senhaCorreta'));
        $_POST = ['email' => 'admin@automax.com', 'senha' => 'senhaErrada'];
        $this->runLogin();
        $this->assertArrayHasKey('flash_error', $_SESSION);
        $this->assertArrayNotHasKey('funcionario_id', $_SESSION);
    }

    public function test_login_valido_preenche_sessao_corretamente(): void
    {
        $this->db->willReturnOnQueryOne($this->funcionarioFake('minhasenha123', id: 42, nome: 'Jonas Pereira', nivel: 'mecanico'));
        $_POST = ['email' => 'jonas@automax.com', 'senha' => 'minhasenha123'];
        $this->runLogin();
        $this->assertEquals(42,              $_SESSION['funcionario_id']   ?? null);
        $this->assertEquals('Jonas Pereira', $_SESSION['funcionario_nome'] ?? null);
        $this->assertEquals('mecanico',      $_SESSION['nivel_de_acesso']  ?? null);
    }

    public function test_login_valido_registra_timestamp_de_autenticacao(): void
    {
        $this->db->willReturnOnQueryOne($this->funcionarioFake('admin123'));
        $_POST = ['email' => 'admin@automax.com', 'senha' => 'admin123'];
        $antes = time();
        $this->runLogin();
        $depois = time();
        $this->assertArrayHasKey('autenticado_em', $_SESSION);
        $this->assertGreaterThanOrEqual($antes,  $_SESSION['autenticado_em']);
        $this->assertLessThanOrEqual($depois, $_SESSION['autenticado_em']);
    }

    public function test_login_valido_consulta_banco_com_email_correto(): void
    {
        $this->db->willReturnOnQueryOne($this->funcionarioFake('pass'));
        $_POST = ['email' => 'maria@automax.com', 'senha' => 'pass'];
        $this->runLogin();
        $chamada = $this->db->calls[0] ?? null;
        $this->assertNotNull($chamada);
        $this->assertEquals('query_one', $chamada['method']);
        $this->assertStringContainsString('funcionarios', $chamada['sql']);
        $this->assertEquals('maria@automax.com', $chamada['params'][':email']);
    }

    public function test_logout_limpa_sessao_completamente(): void
    {
        $_SESSION = ['funcionario_id' => 7, 'funcionario_nome' => 'Alguem', 'nivel_de_acesso' => 'gerente', 'autenticado_em' => time()];
        $this->runLogout();
        $this->assertEmpty($_SESSION);
    }

    public function test_logout_sem_sessao_ativa_nao_lanca_excecao(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        $this->expectNotToPerformAssertions();
        $this->runLogout();
    }
}

'@ | Set-Content -Path '$root\tests\Unit\Auth\AuthControllerTest.php' -Encoding UTF8


# ── 3. Atualizar api/*.php (ja foram modificados — reescreve com estado correto) ──
Write-Host ""
Write-Host "3/6 - Atualizando frontend/api/*.php..."

New-Item -ItemType Directory -Force -Path '$root\frontend\api' | Out-Null
Write-Host '  -> frontend/api/produto.php'
@'
<?php

use Automax\Controllers\AuthController;
use Automax\Controllers\ProdutoController;
use Automax\Controllers\ProdutoNotFoundException;
use Automax\Config\DatabaseException;

declare(strict_types=1);

/*
 * Endpoint: GET /api/produto/:id
 *
 * Responde com JSON contendo os dados do produto e seus relacionados.
 * Chamado pelo produto.js no frontend.
 *
 * Respostas:
 *   200  { produto: {...}, relacionados: [...] }
 *   400  { erro: "ID inválido" }
 *   401  { erro: "Não autenticado" }
 *   404  { erro: "Produto não encontrado" }
 *   500  { erro: "Erro interno" }
 */





header('Content-Type: application/json; charset=UTF-8');

function responder_json(int $codigo, array $payload): never
{
    http_response_code($codigo);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

AuthController::exigir_autenticacao();

$id_raw    = $_GET['id'] ?? '';
$id_produto = filter_var($id_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($id_produto === false) {
    responder_json(400, ['erro' => 'ID inválido. Forneça um inteiro positivo.']);
}


try {
    $controller = new ProdutoController();
    $produto     = $controller->buscar_por_id($id_produto);
    $relacionados = $controller->buscar_relacionados($produto['categoria'], $id_produto);

    responder_json(200, [
        'produto'     => $produto,
        'relacionados' => $relacionados,
    ]);

} catch (ProdutoNotFoundException $e) {
    responder_json(404, ['erro' => $e->getMessage()]);

} catch (DatabaseException $e) {
    error_log('[API produto] DatabaseException: ' . $e->getMessage());
    responder_json(500, ['erro' => 'Erro interno. Tente novamente mais tarde.']);

} catch (Throwable $e) {
    error_log('[API produto] Throwable: ' . $e->getMessage());
    responder_json(500, ['erro' => 'Erro interno inesperado.']);
}
'@ | Set-Content -Path '$root\frontend\api\produto.php' -Encoding UTF8

New-Item -ItemType Directory -Force -Path '$root\frontend\api' | Out-Null
Write-Host '  -> frontend/api/produtos.php'
@'
<?php

use Automax\Controllers\AuthController;
use Automax\Config\Database;
use Automax\Config\DatabaseException;

declare(strict_types=1);

/*
 * Endpoint: GET /api/produtos?pagina=:n&categoria=:cat
 *
 * Lista produtos com paginação e filtro opcional por categoria.
 * Exige autenticação (sessão ativa).
 *
 * Respostas:
 *   200  { produtos: [...], total: int, pagina: int, por_pagina: int, paginas: int }
 *   401  { erro: "Não autenticado" }
 *   405  { erro: "Método não permitido" }
 *   500  { erro: "Erro interno" }
 */





header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['erro' => 'Método não permitido.']);
    exit;
}

AuthController::exigir_autenticacao();

$por_pagina = 12;

$pagina = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($pagina === false) {
    $pagina = 1;
}

$categorias_permitidas = ['pecas', 'fluidos', 'eletrico', 'todos'];
$categoria_raw = strtolower(trim($_GET['categoria'] ?? 'todos'));
$categoria = in_array($categoria_raw, $categorias_permitidas, true) ? $categoria_raw : 'todos';

try {
    $db     = Database::get_instance();
    $offset = ($pagina - 1) * $por_pagina;

    $where_sql  = $categoria !== 'todos' ? 'WHERE categoria = :categoria' : '';
    $params_base = $categoria !== 'todos' ? [':categoria' => $categoria] : [];

    $total = (int) ($db->query_one(
        "SELECT COUNT(*) AS total FROM produtos {$where_sql}",
        $params_base
    )['total'] ?? 0);

    $params_rows = array_merge($params_base, [
        ':limite' => $por_pagina,
        ':offset' => $offset,
    ]);

    $linhas = $db->query(
        "SELECT id_produto, nome, preco, stock, imagem, categoria
           FROM produtos
         {$where_sql}
          ORDER BY id_produto DESC
          LIMIT :limite OFFSET :offset",
        $params_rows
    );

    $produtos = array_map(fn(array $r): array => [
        'id'        => (int)   $r['id_produto'],
        'nome'      =>         $r['nome'],
        'preco'     => (float) $r['preco'],
        'stock'     => (int)   $r['stock'],
        'imagem'    =>         $r['imagem'],
        'categoria' =>         $r['categoria'],
    ], $linhas);

    http_response_code(200);
    echo json_encode([
        'produtos'   => $produtos,
        'total'      => $total,
        'pagina'     => $pagina,
        'por_pagina' => $por_pagina,
        'paginas'    => (int) ceil($total / $por_pagina),
    ], JSON_UNESCAPED_UNICODE);

} catch (DatabaseException $e) {
    error_log('[API produtos] DatabaseException: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno. Tente novamente mais tarde.']);
} catch (Throwable $e) {
    error_log('[API produtos] Throwable: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno inesperado.']);
}
'@ | Set-Content -Path '$root\frontend\api\produtos.php' -Encoding UTF8

New-Item -ItemType Directory -Force -Path '$root\frontend\api' | Out-Null
Write-Host '  -> frontend/api/busca.php'
@'
<?php

use Automax\Config\Database;
use Automax\Config\DatabaseException;

declare(strict_types=1);

/*
 * Endpoint público: GET /api/busca?q=:termo&categoria=:cat&pagina=:n
 *
 * Busca produtos na tabela `produtos` sem exigir autenticação,
 * pois o resultado é exibido na página inicial (pré-login).
 *
 * Segurança:
 *  - Parâmetros sanitizados e validados antes de qualquer uso
 *  - Apenas prepared statements com parâmetros nomeados (zero SQL injection)
 *  - Paginação server-side (nenhum dado extra é exposto)
 *  - Cabeçalhos anti-clickjacking e anti-sniff incluídos
 *  - Campos retornados são explicitamente enumerados (sem SELECT *)
 *
 * Respostas:
 *   200  { resultados: [...], total: int, pagina: int, por_pagina: int }
 *   400  { erro: "..." }
 *   405  { erro: "Método não permitido" }
 *   500  { erro: "Erro interno" }
 */



// ── Cabeçalhos de segurança ────────────────────────────────────────────────

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

// ── Apenas GET ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['erro' => 'Método não permitido.']);
    exit;
}

// ── Helpers ────────────────────────────────────────────────────────────────

function responder(int $codigo, array $payload): never
{
    http_response_code($codigo);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Validação e sanitização dos parâmetros ─────────────────────────────────

// Termo de busca: strip tags, trim, limite de 100 chars
$termo_raw = $_GET['q'] ?? '';
$termo     = mb_substr(trim(strip_tags($termo_raw)), 0, 100, 'UTF-8');

if ($termo === '') {
    responder(400, ['erro' => 'Informe um termo de busca.']);
}

// Categoria: lista de valores permitidos (whitelist)
$categorias_permitidas = ['pecas', 'fluidos', 'eletrico', 'todos'];
$categoria_raw = strtolower(trim($_GET['categoria'] ?? 'todos'));
$categoria     = in_array($categoria_raw, $categorias_permitidas, true)
    ? $categoria_raw
    : 'todos';

// Paginação: página >= 1, por_pagina fixo em 12
$pagina_raw = $_GET['pagina'] ?? '1';
$pagina     = filter_var($pagina_raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($pagina === false) {
    $pagina = 1;
}

$por_pagina = 12;
$offset     = ($pagina - 1) * $por_pagina;

// ── Monta query com prepared statements ───────────────────────────────────

/*
 * Busca por LIKE nos campos nome e detalhes.
 * O % é adicionado aqui em PHP, nunca vindo do usuário direto,
 * para evitar qualquer possibilidade de injeção via parâmetro.
 */
$like = '%' . $termo . '%';

$params_count = [':like_nome' => $like, ':like_det' => $like];
$params_rows  = [':like_nome' => $like, ':like_det' => $like];

$where_categoria = '';
if ($categoria !== 'todos') {
    $where_categoria        = ' AND categoria = :categoria';
    $params_count[':categoria'] = $categoria;
    $params_rows[':categoria']  = $categoria;
}

$sql_count = "
    SELECT COUNT(*) AS total
      FROM produtos
     WHERE (nome LIKE :like_nome OR detalhes LIKE :like_det)
    {$where_categoria}
";

$sql_rows = "
    SELECT id_produto, nome, preco, imagem, categoria, detalhes
      FROM produtos
     WHERE (nome LIKE :like_nome OR detalhes LIKE :like_det)
    {$where_categoria}
     ORDER BY nome ASC
     LIMIT :limite OFFSET :offset
";

$params_rows[':limite']  = $por_pagina;
$params_rows[':offset']  = $offset;

// ── Executa ────────────────────────────────────────────────────────────────

try {
    $db      = Database::get_instance();
    $total   = (int) ($db->query_one($sql_count, $params_count)['total'] ?? 0);
    $linhas  = $db->query($sql_rows, $params_rows);

    // Formata os dados: converte preço para float e garante tipos corretos
    $resultados = array_map(function (array $row): array {
        return [
            'id'        => (int)   $row['id_produto'],
            'nome'      =>         $row['nome'],
            'preco'     => (float) $row['preco'],
            'imagem'    =>         $row['imagem'],
            'categoria' =>         $row['categoria'],
            'detalhes'  =>         mb_substr($row['detalhes'], 0, 120, 'UTF-8'),
        ];
    }, $linhas);

    responder(200, [
        'resultados' => $resultados,
        'total'      => $total,
        'pagina'     => $pagina,
        'por_pagina' => $por_pagina,
    ]);

} catch (DatabaseException $e) {
    error_log('[API busca] DatabaseException: ' . $e->getMessage());
    responder(500, ['erro' => 'Erro interno. Tente novamente mais tarde.']);
} catch (Throwable $e) {
    error_log('[API busca] Throwable: ' . $e->getMessage());
    responder(500, ['erro' => 'Erro interno inesperado.']);
}

'@ | Set-Content -Path '$root\frontend\api\busca.php' -Encoding UTF8


# ── 4. Atualizar composer.json ────────────────────────────────────────────
Write-Host ""
Write-Host "4/6 - Atualizando composer.json..."
@'
{
    "name": "systemic/tests",
    "description": "Testes unitarios do backend Systemic (Automax)",
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Automax\\": "frontend/app/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "phpunit",
        "test:auth": "phpunit --filter AuthControllerTest",
        "test:cadastro": "phpunit --filter CadastroControllerTest",
        "test:produto": "phpunit --filter ProdutoControllerTest"
    }
}
'@ | Set-Content -Path "$root\composer.json" -Encoding UTF8


# ── 5. Remover arquivos legados ───────────────────────────────────────────
Write-Host ""
Write-Host "5/6 - Removendo arquivos legados..."
$legados = @(
    "$fe\database.php",
    "$fe\libs\router.php",
    "$fe\libs\AccessControl.php",
    "$fe\auth_controller.php",
    "$fe\cadastro_controller.php",
    "$fe\ProdutoController.php"
)
foreach ($f in $legados) {
    if (Test-Path $f) {
        Remove-Item $f -Force
        Write-Host "  removido: $f"
    }
}
# Remove libs/ se vazia
$libsDir = "$fe\libs"
if (Test-Path $libsDir) {
    $remaining = Get-ChildItem $libsDir
    if ($remaining.Count -eq 0) {
        Remove-Item $libsDir -Force
        Write-Host "  removido: $libsDir"
    }
}


# ── 6. Regenerar autoloader ───────────────────────────────────────────────
Write-Host ""
Write-Host "6/6 - Regenerando autoloader do Composer..."
composer dump-autoload --optimize

Write-Host ""
Write-Host "Migracao PSR-4 concluida!" -ForegroundColor Green
Write-Host ""
Write-Host "Estrutura criada:"
Write-Host "  frontend/app/Config/Database.php"
Write-Host "  frontend/app/Http/Router.php"
Write-Host "  frontend/app/Auth/AccessControl.php"
Write-Host "  frontend/app/Controllers/AuthController.php"
Write-Host "  frontend/app/Controllers/CadastroController.php"
Write-Host "  frontend/app/Controllers/ProdutoController.php"
Write-Host ""
Write-Host "Proximo passo: composer test"
