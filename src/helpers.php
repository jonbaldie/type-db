<?php

declare(strict_types=1);

namespace TypeDb;

use PDO;
use PDOStatement;

function throw_query_failure(
    PDO|PDOStatement $statement_or_pdo,
    string $action,
    string $sql,
): never {
    $error = $statement_or_pdo->errorInfo();
    $sqlState = $error[0] ?? 'unknown';
    $driverCode = $error[1] ?? 'unknown';
    $driverMessage = $error[2] ?? 'unknown';

    throw new \RuntimeException(
        sprintf(
            'Failed to %s query [%s/%s]: %s. SQL: %s',
            $action,
            $sqlState,
            (string) $driverCode,
            $driverMessage,
            $sql,
        )
    );
}

/**
 * @param array<string, mixed>|false $meta
 */
function column_result_kind(array|false $meta): ?string
{
    if ($meta === false) {
        return null;
    }

    $declared = $meta['sqlite:decl_type'] ?? null;
    if (is_string($declared)) {
        $kind = kind_from_declared_type($declared);
        if (is_string($kind)) {
            return $kind;
        }
    }

    $native = $meta['native_type'] ?? null;
    if (is_string($native)) {
        return kind_from_native_type($native);
    }

    return null;
}

function kind_from_declared_type(string $declared): ?string
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

function kind_from_native_type(string $native): ?string
{
    return match (strtolower($native)) {
        'integer', 'int', 'long', 'longlong' => 'integer',
        'double', 'float', 'real' => 'float',
        'string', 'blob', 'datetime', 'date', 'time', 'timestamp', 'var_string' => 'string',
        default => null,
    };
}

/**
 * @return list<?string>
 */
function statement_column_kinds(PDOStatement $statement): array
{
    $kinds = [];
    $column_count = $statement->columnCount();

    for ($index = 0; $index < $column_count; $index++) {
        $kinds[] = column_result_kind($statement->getColumnMeta($index));
    }

    return $kinds;
}

/**
 * @return list<string>
 */
function statement_duplicate_column_names(PDOStatement $statement): array
{
    $seen = [];
    $duplicates = [];
    $column_count = $statement->columnCount();

    for ($index = 0; $index < $column_count; $index++) {
        $meta = $statement->getColumnMeta($index);
        if ($meta === false) {
            continue;
        }

        $name = $meta['name'];

        if (isset($seen[$name])) {
            $duplicates[$name] = true;
        }

        $seen[$name] = true;
    }

    return array_keys($duplicates);
}

/**
 * @param list<?string> $column_kinds
 * @param array<array-key, mixed> $row
 * @return SqlValue\SqlValue[]
 */
function row_sql_values(array $row, array $column_kinds): array
{
    $mapped = [];
    $index = 0;

    foreach ($row as $column => $value) {
        $mapped[$column] = cell_sql_value($value, $column_kinds[$index] ?? null);
        $index++;
    }

    return $mapped;
}

function cell_sql_value(mixed $value, ?string $kind): SqlValue\SqlValue
{
    if ($value === null) {
        return new SqlValue\SqlNull();
    }

    if ($kind === 'integer') {
        if (is_int($value)) {
            return new SqlValue\SqlInteger($value);
        }

        if (is_float($value) || is_string($value)) {
            return new SqlValue\SqlInteger((int) $value);
        }
    }

    if ($kind === 'float') {
        if (is_float($value)) {
            return new SqlValue\SqlFloat($value);
        }

        if (is_int($value) || is_string($value)) {
            return new SqlValue\SqlFloat((float) $value);
        }
    }

    if ($kind === 'string') {
        if (is_string($value)) {
            return new SqlValue\SqlString($value);
        }

        if (is_int($value) || is_float($value)) {
            return new SqlValue\SqlString((string) $value);
        }
    }

    if (is_string($value) || is_int($value) || is_float($value)) {
        return to_sql($value);
    }

    return new SqlValue\SqlNull();
}

/**
 * @param string|float|int|null $value
 * @return SqlValue\SqlValue
 */
function to_sql(
    string|float|int|null $value
): SqlValue\SqlValue
{
    if (is_string($value)) {
        return new SqlValue\SqlString($value);
    }

    if (is_float($value)) {
        return new SqlValue\SqlFloat($value);
    }

    if (is_integer($value)) {
        return new SqlValue\SqlInteger($value);
    }

    return new SqlValue\SqlNull();
}

/**
 * @param SqlValue\SqlValue $value
 * @return string|float|int|null
 */
function from_sql(
    SqlValue\SqlValue $value
): string|float|int|null
{
    if ($value instanceof SqlValue\SqlString) {
        return $value->value;
    }

    if ($value instanceof SqlValue\SqlFloat) {
        return $value->value;
    }

    if ($value instanceof SqlValue\SqlInteger) {
        return $value->value;
    }

    if ($value instanceof SqlValue\SqlNull) {
        return null;
    }

    return null;
}

/**
 * @param Connection $conn
 * @param string $sql
 * @param SqlValue\SqlValue[] $sql_values
 * @return SqlValue\SqlValue[][]
 */
function quick_query(
    Connection $conn,
    string $sql,
    array $sql_values = [],
): array
{
    // 1. prepare query
    $statement = $conn->pdo->prepare($sql);

    if ($statement === false) {
        throw_query_failure($conn->pdo, 'prepare', $sql);
    }

    // 2. execute with parameters
    $executed = $statement->execute(
        array_map('\TypeDb\from_sql', $sql_values)
    );

    if ($executed === false) {
        throw_query_failure($statement, 'execute', $sql);
    }

    // 3. interpret result
    $duplicates = statement_duplicate_column_names($statement);

    if (count($duplicates) > 0) {
        throw new \RuntimeException(
            sprintf(
                'Failed to interpret query [HY000/unknown]: Duplicate column names in result set: %s. SQL: %s',
                implode(', ', $duplicates),
                $sql,
            )
        );
    }

    $column_kinds = statement_column_kinds($statement);
    $results = $statement->fetchAll(PDO::FETCH_ASSOC);

    // 4. turn result values into SqlValue objects
    $return = array_map(
        fn (array $row) => row_sql_values($row, $column_kinds),
        $results
    );

    // 5. return full result
    return $return;
}
