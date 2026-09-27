<?php

declare(strict_types=1);

/**
 * Phase 5 remediáció — SQL-rögzítő PDO a Database MySQL-ágainak
 * ellenőrzéséhez élő MySQL nélkül. A kiadott SQL-t és a kötött értékeket
 * rögzíti (nem hajtja végre); a lekérdezések eredménye regex → sorok
 * leképezéssel adható meg. NEM valódi MySQL — csak azt bizonyítja, milyen
 * SQL-t (milyen sorrendben, milyen értékekkel) küldene a MySQL-ág.
 */
final class SqlCapturePdo extends PDO
{
    /** @var list<array{sql:string, params:array}> */
    public array $log = [];
    /** @var array<string, list<array<string,mixed>>> regex => eredménysorok */
    public array $results = [];
    public int $rowCount = 1;
    private bool $inTx = false;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SqlCaptureStatement::class, []]);
    }

    public static function normalize(string $sql): string
    {
        return trim(preg_replace('/\s+/', ' ', $sql));
    }

    /** @return list<array<string,mixed>> */
    public function resultFor(string $sql): array
    {
        foreach ($this->results as $pattern => $rows) {
            if (preg_match($pattern, $sql)) {
                return $rows;
            }
        }
        return [];
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $stmt = parent::prepare('SELECT 1');
        $stmt->owner = $this;
        $stmt->sqlText = self::normalize($query);
        return $stmt;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $stmt = $this->prepare($query);
        $stmt->execute();
        return $stmt;
    }

    public function exec(string $statement): int|false
    {
        $this->log[] = ['sql' => self::normalize($statement), 'params' => []];
        return 0;
    }

    public function beginTransaction(): bool
    {
        $this->log[] = ['sql' => 'BEGIN', 'params' => []];
        $this->inTx = true;
        return true;
    }

    public function commit(): bool
    {
        $this->log[] = ['sql' => 'COMMIT', 'params' => []];
        $this->inTx = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->log[] = ['sql' => 'ROLLBACK', 'params' => []];
        $this->inTx = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTx;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return '1';
    }

    /** @return list<string> */
    public function statements(): array
    {
        return array_column($this->log, 'sql');
    }
}

final class SqlCaptureStatement extends PDOStatement
{
    public ?SqlCapturePdo $owner = null;
    public string $sqlText = '';
    private array $rows = [];

    protected function __construct()
    {
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        return true;
    }

    public function execute(?array $params = null): bool
    {
        $this->owner->log[] = ['sql' => $this->sqlText, 'params' => $params ?? []];
        $this->rows = $this->owner->resultFor($this->sqlText);
        return true;
    }

    public function rowCount(): int
    {
        return $this->owner->rowCount;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = array_shift($this->rows);
        return $row ?? false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = array_shift($this->rows);
        return $row === null ? false : array_values($row)[$column];
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = $this->rows;
        $this->rows = [];
        if ($mode === PDO::FETCH_COLUMN) {
            return array_map(fn ($r) => array_values($r)[0], $rows);
        }
        return $rows;
    }

    public function closeCursor(): bool
    {
        return true;
    }
}
