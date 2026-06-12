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
            ?: throw new \RuntimeException('VariÃ¡vel de ambiente DB_PASS nÃ£o configurada.');

        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->connection = new \PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            throw new DatabaseException('NÃ£o foi possÃ­vel conectar ao banco de dados.', 0, $e);
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

    public function query_all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params);
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->execute($sql, $params);
        return (int) $this->connection->lastInsertId();
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