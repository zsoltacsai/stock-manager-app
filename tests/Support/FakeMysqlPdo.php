<?php

declare(strict_types=1);

/**
 * Phase 5 remediáció (DB-01/DB-06/DB-08) — MySQL-t SZIMULÁLÓ PDO a
 * BackupManager MySQL-ágainak determinisztikus teszteléséhez (a
 * tesztkörnyezetben nincs MySQL-szerver és pdo_mysql). NEM valódi MySQL:
 * csak azt modellezi, amire a mentés/visszaállítás logikája épít:
 *   - táblák (CREATE TABLE szövege, sorok), `REFERENCES` szülőtáblák;
 *   - `SET FOREIGN_KEY_CHECKS` munkamenet-változó; bekapcsolt állapotban
 *     egy másik tábla által hivatkozott tábla DROP-ja a MySQL 8 3730-as
 *     hibájával elbukik (dokumentált MySQL-viselkedés);
 *   - SHOW TABLES / SHOW CREATE TABLE / SELECT * a dumphoz;
 *   - a számlaszám-sorozatok egyeztetésének SQL-alakjai;
 *   - minden utasítás naplózása és egyszeri/többszöri hibainjektálás.
 */
final class FakeMysqlPdo extends PDO
{
    /** @var array<string, array{create:string, refs:list<string>, rows:list<array<string,?string>>}> */
    public array $tables = [];
    public int $fkChecks = 1;
    /** @var list<string> */
    public array $log = [];
    public ?string $failOn = null;
    public int $failTimes = 0;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [FakeMysqlStatement::class, []]);
    }

    /** @param list<array<string,mixed>> $rows */
    public function seedTable(string $name, string $columnsSql, array $rows = [], array $refs = []): void
    {
        $create = "CREATE TABLE `$name` (\n  $columnsSql\n) ENGINE=InnoDB";
        if ($refs) {
            // A hivatkozásokat a CREATE szövegébe is beírjuk, hogy a dump → restore körben megmaradjanak.
            $fk = implode(",\n  ", array_map(fn ($p, $i) => "CONSTRAINT `fk_{$name}_$i` FOREIGN KEY (`{$p}_id`) REFERENCES `$p` (`id`)", $refs, array_keys($refs)));
            $create = "CREATE TABLE `$name` (\n  $columnsSql,\n  $fk\n) ENGINE=InnoDB";
        }
        $this->tables[$name] = ['create' => $create, 'refs' => $refs, 'rows' => array_map(fn ($r) => array_map(fn ($v) => $v === null ? null : (string) $v, $r), $rows)];
    }

    /** @return array<string, list<array<string,?string>>> */
    public function snapshot(): array
    {
        $out = [];
        foreach ($this->tables as $name => $t) {
            $out[$name] = $t['rows'];
        }
        ksort($out);
        return $out;
    }

    private function maybeFail(string $sql): void
    {
        if ($this->failOn !== null && $this->failTimes > 0 && str_contains($sql, $this->failOn)) {
            $this->failTimes--;
            throw new PDOException('SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query (szimulált)');
        }
    }

    public function exec(string $statement): int|false
    {
        $sql = trim($statement);
        $this->log[] = $sql;
        $this->maybeFail($sql);
        if (preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*(\d)/i', $sql, $m)) {
            $this->fkChecks = (int) $m[1];
            return 0;
        }
        if (preg_match('/^DROP TABLE IF EXISTS `?(\w+)`?$/i', $sql, $m)) {
            $name = $m[1];
            if (!isset($this->tables[$name])) {
                return 0;
            }
            if ($this->fkChecks === 1) {
                foreach ($this->tables as $child => $t) {
                    if ($child !== $name && in_array($name, $t['refs'], true)) {
                        throw new PDOException("SQLSTATE[HY000]: General error: 3730 Cannot drop table '$name' referenced by a foreign key constraint on table '$child'.");
                    }
                }
            }
            unset($this->tables[$name]);
            return 0;
        }
        if (preg_match('/^CREATE TABLE `?(\w+)`?\s*\(/i', $sql, $m)) {
            $name = $m[1];
            if (isset($this->tables[$name])) {
                throw new PDOException("SQLSTATE[42S01]: Base table or view already exists: 1050 Table '$name' already exists");
            }
            preg_match_all('/REFERENCES `?(\w+)`?/i', $sql, $r);
            $this->tables[$name] = ['create' => $sql, 'refs' => array_values(array_unique($r[1])), 'rows' => []];
            return 0;
        }
        if (preg_match('/^INSERT INTO `?(\w+)`?\s*\((.*?)\)\s*VALUES\s*\((.*)\)$/is', $sql, $m)) {
            $name = $m[1];
            if (!isset($this->tables[$name])) {
                throw new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table '$name' doesn't exist");
            }
            $cols = array_map(fn ($c) => trim($c, " `"), explode(',', $m[2]));
            $this->tables[$name]['rows'][] = array_combine($cols, self::parseValues($m[3]));
            return 1;
        }
        return 0; // SET SESSION…, START TRANSACTION…, COMMIT…
    }

    /** @return list<?string> */
    private static function parseValues(string $values): array
    {
        $out = [];
        $len = strlen($values);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && ($values[$i] === ' ' || $values[$i] === ',')) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            if ($values[$i] === "'") {
                $buf = '';
                $i++;
                while ($i < $len) {
                    if ($values[$i] === "'" && ($values[$i + 1] ?? '') === "'") {
                        $buf .= "'";
                        $i += 2;
                        continue;
                    }
                    if ($values[$i] === "'") {
                        $i++;
                        break;
                    }
                    $buf .= $values[$i++];
                }
                $out[] = $buf;
            } else {
                $end = strpos($values, ',', $i);
                $token = trim($end === false ? substr($values, $i) : substr($values, $i, $end - $i));
                $out[] = strtoupper($token) === 'NULL' ? null : $token;
                $i = $end === false ? $len : $end;
            }
        }
        return $out;
    }

    /** @param list<array<string,?string>> $rows */
    private function rowsStatement(array $rows, array $columns): PDOStatement
    {
        if (!$rows) {
            return parent::query('SELECT 1 WHERE 0');
        }
        $selects = [];
        $params = [];
        foreach ($rows as $row) {
            $parts = [];
            foreach ($columns as $c) {
                $parts[] = '? AS "' . str_replace('"', '""', $c) . '"';
                $params[] = $row[$c] ?? null;
            }
            $selects[] = 'SELECT ' . implode(', ', $parts);
        }
        $stmt = parent::prepare(implode(' UNION ALL ', $selects));
        $stmt->execute($params);
        return $stmt;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $sql = trim($query);
        $this->log[] = $sql;
        if (preg_match('/^SHOW TABLES$/i', $sql)) {
            $names = array_keys($this->tables);
            sort($names);
            return $this->rowsStatement(array_map(fn ($n) => ['name' => $n], $names), ['name']);
        }
        if (preg_match('/^SHOW CREATE TABLE `?(\w+)`?$/i', $sql, $m)) {
            return $this->rowsStatement([['Table' => $m[1], 'Create Table' => $this->tables[$m[1]]['create']]], ['Table', 'Create Table']);
        }
        if (preg_match('/^SELECT (.+?) FROM `?(\w+)`?$/i', $sql, $m)) {
            $name = $m[2];
            if (!isset($this->tables[$name])) {
                throw new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table '$name' doesn't exist");
            }
            $rows = $this->tables[$name]['rows'];
            $columns = trim($m[1]) === '*'
                ? ($rows ? array_keys($rows[0]) : ['id'])
                : array_map('trim', explode(',', $m[1]));
            return $this->rowsStatement($rows, $columns);
        }
        throw new PDOException('FakeMysqlPdo: nem modellezett lekérdezés: ' . $sql);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $stmt = parent::prepare('SELECT 1');
        $stmt->owner = $this;
        $stmt->sqlText = trim(preg_replace('/\s+/', ' ', $query));
        return $stmt;
    }

    /** A számlaszám-sorozat egyeztetés prepare()-elt utasításai. */
    public function executePrepared(string $sql, array $params): array
    {
        $this->log[] = $sql . ' ' . json_encode($params);
        if (preg_match('/^INSERT IGNORE INTO (\w+) \((\w+), (\w+), updated_at\)/', $sql, $m)) {
            foreach ($this->tables[$m[1]]['rows'] ?? [] as $row) {
                if ((string) $row[$m[2]] === (string) $params[0]) {
                    return [];
                }
            }
            $this->tables[$m[1]]['rows'][] = [$m[2] => (string) $params[0], $m[3] => '0', 'updated_at' => (string) $params[1]];
            return [];
        }
        if (preg_match('/^UPDATE (\w+) SET (\w+) = \?, updated_at = \? WHERE (\w+) = \? AND \w+ < \?$/', $sql, $m)) {
            foreach ($this->tables[$m[1]]['rows'] as &$row) {
                if ((string) $row[$m[3]] === (string) $params[2] && (int) $row[$m[2]] < (int) $params[3]) {
                    $row[$m[2]] = (string) $params[0];
                    $row['updated_at'] = (string) $params[1];
                }
            }
            return [];
        }
        if (preg_match('/^SELECT 1 FROM invoices WHERE id = \?$/', $sql)) {
            foreach ($this->tables['invoices']['rows'] ?? [] as $row) {
                if ((string) $row['id'] === (string) $params[0]) {
                    return [[1]];
                }
            }
            return [];
        }
        throw new PDOException('FakeMysqlPdo: nem modellezett prepared utasítás: ' . $sql);
    }
}

final class FakeMysqlStatement extends PDOStatement
{
    public ?FakeMysqlPdo $owner = null;
    public string $sqlText = '';
    private array $result = [];

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        if ($this->owner === null) {
            return parent::execute($params);
        }
        $this->result = $this->owner->executePrepared($this->sqlText, $params ?? []);
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->owner === null) {
            return parent::fetchColumn($column);
        }
        $row = array_shift($this->result);
        return $row === null ? false : $row[$column];
    }

    public function closeCursor(): bool
    {
        $this->result = [];
        return $this->owner === null ? parent::closeCursor() : true;
    }
}
