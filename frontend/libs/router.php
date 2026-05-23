<?php

declare(strict_types=1);

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
    private const debug_enabled = true;
    private string $basePath;   

    public function __construct(string $staticDir = __DIR__ . '/../pages', string $basePath = '')
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

        $ext      = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
        $mime     = self::MIME_TYPES[$ext] ?? 'application/octet-stream';
        $charset  = str_starts_with($mime, 'text/') ? '; charset=UTF-8' : '';

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
        if (self::debug_enabled) 
        {
            error_log("[Router] RAW path: '$path' | method: '$method'");
            error_log("[Router] REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
            error_log("[Router] SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'N/A'));
            header('X-Debug-Path: ' . $path);
            header('X-Debug-Method: ' . $method);
        }

        $method = strtoupper($method);
        $path = parse_url($path, PHP_URL_PATH);
        
        if ($this->basePath !== '' && str_starts_with($path, $this->basePath)) 
        {
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
