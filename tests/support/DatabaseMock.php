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

    public function begin_transaction(): void
    {
        $this->calls[] = ['method' => 'begin_transaction', 'sql' => null, 'params' => []];
    }

    public function commit(): void
    {
        $this->calls[] = ['method' => 'commit', 'sql' => null, 'params' => []];
    }

    public function rollback(): void
    {
        $this->calls[] = ['method' => 'rollback', 'sql' => null, 'params' => []];
    }
}

