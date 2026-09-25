<?php

declare(strict_types=1);

namespace TypeDb;

use PDO;
use PDOStatement;

/**
 * Interprets an executed statement's result set as rows of SqlValue objects.
 *
 * Column names are normalized and checked for duplicates once, up front.
 * Column metadata is read per row and only for string cells: SQLite reports
 * the current row's storage class, which can differ between rows of the same
 * column, and only strings need a column kind to be interpreted.
 */
final class RowHydrator
{
    /** @var array<string, string> */
    private array $normalizedKeys = [];

    /**
     * @param \Closure(string): string $normalizeColumnName
     * @param ?string $sql SQL reported in errors; defaults to the statement's own
     */
    public function __construct(
        private readonly PDOStatement $statement,
        private readonly \Closure $normalizeColumnName,
        ?string $sql = null,
    ) {
        $seen = [];
        $duplicates = [];
        $columnCount = $statement->columnCount();

        for ($index = 0; $index < $columnCount; $index++) {
            $meta = $statement->getColumnMeta($index);
            if ($meta === false) {
                continue;
            }

            $name = ($this->normalizeColumnName)((string) $meta['name']);

            if (isset($seen[$name])) {
                $duplicates[$name] = true;
            }

            $seen[$name] = true;
        }

        if (count($duplicates) > 0) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to interpret query [HY000/unknown]: Duplicate column names in result set: %s. SQL: %s',
                    implode(', ', array_keys($duplicates)),
                    $sql ?? $statement->queryString,
                )
            );
        }
    }

    /**
     * @return list<array<string, SqlValue\SqlValue>>
     */
    public function fetchAll(): array
    {
        $rows = [];

        while (is_array($row = $this->statement->fetch(PDO::FETCH_ASSOC))) {
            $rows[] = $this->hydrate($row);
        }

        return $rows;
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, SqlValue\SqlValue>
     */
    private function hydrate(array $row): array
    {
        $mapped = [];
        $index = 0;

        foreach ($row as $column => $value) {
            $mapped[$this->normalizedKey((string) $column)] = $this->cell($value, $index);
            $index++;
        }

        return $mapped;
    }

    private function normalizedKey(string $column): string
    {
        return $this->normalizedKeys[$column] ??= ($this->normalizeColumnName)($column);
    }

    private function cell(mixed $value, int $index): SqlValue\SqlValue
    {
        if (is_int($value)) {
            return new SqlValue\SqlInteger($value);
        }

        if (is_float($value)) {
            return new SqlValue\SqlFloat($value);
        }

        if (!is_string($value)) {
            return new SqlValue\SqlNull();
        }

        return match ($this->kind($index)) {
            'integer' => new SqlValue\SqlInteger((int) $value),
            'float' => new SqlValue\SqlFloat(self::stringifiedFloat($value)),
            default => new SqlValue\SqlString($value),
        };
    }

    private function kind(int $index): ?string
    {
        return self::kindFromMeta($this->statement->getColumnMeta($index));
    }

    /**
     * Widened because the driver adds keys such as `sqlite:decl_type`.
     *
     * @param array<string, mixed>|false $meta
     */
    private static function kindFromMeta(array|false $meta): ?string
    {
        if ($meta === false) {
            return null;
        }

        $native = $meta['native_type'] ?? null;
        if (is_string($native)) {
            $kind = self::kindFromNativeType($native);
            if (is_string($kind)) {
                return $kind;
            }
        }

        $declared = $meta['sqlite:decl_type'] ?? null;
        if (is_string($declared)) {
            return self::kindFromDeclaredType($declared);
        }

        return null;
    }

    private static function kindFromNativeType(string $native): ?string
    {
        return match (strtolower($native)) {
            'integer', 'int', 'long', 'longlong' => 'integer',
            'double', 'float', 'real' => 'float',
            'string', 'blob', 'datetime', 'date', 'time', 'timestamp', 'var_string' => 'string',
            default => null,
        };
    }

    private static function kindFromDeclaredType(string $declared): ?string
    {
        $upper = strtoupper($declared);

        if (str_contains($upper, 'INT')) {
            return 'integer';
        }

        if (str_contains($upper, 'CHAR') || str_contains($upper, 'CLOB') || str_contains($upper, 'TEXT')) {
            return 'string';
        }

        if (str_contains($upper, 'BLOB')) {
            return 'string';
        }

        if (str_contains($upper, 'REAL') || str_contains($upper, 'FLOA') || str_contains($upper, 'DOUB')) {
            return 'float';
        }

        return null;
    }

    /**
     * SQLite stringifies infinities as "INF" and "-INF", which a PHP float cast
     * turns into 0.0.
     */
    private static function stringifiedFloat(string $value): float
    {
        return match (strtoupper($value)) {
            'INF', '+INF' => INF,
            '-INF' => -INF,
            default => (float) $value,
        };
    }
}
