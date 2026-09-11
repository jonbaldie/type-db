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

    $native = $meta['native_type'] ?? null;
    if (is_string($native)) {
        $kind = kind_from_native_type($native);
        if (is_string($kind)) {
            return $kind;
        }
    }

    $declared = $meta['sqlite:decl_type'] ?? null;
    if (is_string($declared)) {
        return kind_from_declared_type($declared);
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

    if (is_int($value)) {
        return new SqlValue\SqlInteger($value);
    }

    if (is_float($value)) {
        return new SqlValue\SqlFloat($value);
    }

    if (is_string($value)) {
        if ($kind === 'integer') {
            return new SqlValue\SqlInteger((int) $value);
        }

        if ($kind === 'float') {
            return new SqlValue\SqlFloat(stringified_float($value));
        }

        return new SqlValue\SqlString($value);
    }

    return new SqlValue\SqlNull();
}

/**
 * SQLite stringifies infinities as "INF" and "-INF", which a PHP float cast
 * turns into 0.0.
 */
function stringified_float(string $value): float
{
    return match (strtoupper($value)) {
        'INF', '+INF' => INF,
        '-INF' => -INF,
        default => (float) $value,
    };
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

    throw new \InvalidArgumentException(
        sprintf('Unsupported SqlValue implementation: %s', $value::class)
    );
}

/**
 * The shortest decimal representation that converts back to the exact same
 * double. Searching increasing precision instead of relying on the
 * `precision` or `serialize_precision` ini keeps this independent of the
 * host configuration. Infinities use SQLite overflow literals, while NAN is
 * rejected because SQLite has no NaN representation.
 */
function round_trip_float_string(float $value): string
{
    if (is_nan($value)) {
        throw new \InvalidArgumentException('NAN cannot be represented by SQLite.');
    }

    if (is_infinite($value)) {
        return $value > 0 ? '9e999' : '-9e999';
    }

    for ($precision = 1; $precision < 17; $precision++) {
        $candidate = c_locale_float_string($value, $precision);

        if ((float) $candidate === $value) {
            return $candidate;
        }
    }

    return c_locale_float_string($value, 17);
}

/**
 * Format a double with `%G` at the given precision using the C locale's
 * decimal point. `sprintf()` honours `LC_NUMERIC`, so under a comma-decimal
 * locale the output is text such as `1,5`, which SQLite cannot parse as a
 * numeric literal. The grouping flag is never used, so the decimal
 * separator is the only comma `%G` can emit and replacing it restores the
 * locale-independent form.
 */
function c_locale_float_string(float $value, int $precision): string
{
    return str_replace(',', '.', sprintf('%.' . $precision . 'G', $value));
}

/**
 * Prepare a SqlValue for parameter binding.
 *
 * PDO binds floats as text, and PHP's double-to-string conversion uses the
 * `precision` ini (default 14), silently truncating doubles with more
 * significant digits before they reach SQLite. Binding the round-trip
 * decimal representation instead lets SQLite parse the value back to the
 * exact same double, keeping write→read roundtrips lossless for
 * numeric-affinity columns. Infinities use overflow literals and NAN is
 * rejected because SQLite cannot represent it.
 *
 * @param SqlValue\SqlValue $value
 * @return string|float|int|null
 */
function bind_sql_value(SqlValue\SqlValue $value): string|float|int|null
{
    if ($value instanceof SqlValue\SqlFloat) {
        return round_trip_float_string($value->value);
    }

    return from_sql($value);
}

/**
 * SQLite has no PDO parameter type for floats. Cast float placeholders in the
 * SQL expression so that SQLite receives a REAL value instead of text.
 *
 * @param SqlValue\SqlValue[] $sql_values
 */
function sqlite_float_parameter_sql(string $sql, array $sql_values): string
{
    $values = array_values($sql_values);
    $named_float_parameters = [];

    foreach ($sql_values as $key => $value) {
        if (!is_int($key) && $value instanceof SqlValue\SqlFloat) {
            $named_float_parameters[ltrim((string) $key, ':@$')] = true;
        }
    }

    $result = '';
    $value_index = 0;
    $length = strlen($sql);

    for ($index = 0; $index < $length; $index++) {
        $character = $sql[$index];

        if ($character === "'" || $character === '"' || $character === chr(96)) {
            $start = $index++;

            while ($index < $length) {
                if ($sql[$index] === $character) {
                    if ($index + 1 < $length && $sql[$index + 1] === $character) {
                        $index += 2;
                        continue;
                    }

                    $index++;
                    break;
                }

                $index++;
            }

            $result .= substr($sql, $start, $index - $start);
            $index--;
            continue;
        }

        if ($character === '[') {
            $start = $index++;

            while ($index < $length) {
                if ($sql[$index] === ']') {
                    if ($index + 1 < $length && $sql[$index + 1] === ']') {
                        $index += 2;
                        continue;
                    }

                    $index++;
                    break;
                }

                $index++;
            }

            $result .= substr($sql, $start, $index - $start);
            $index--;
            continue;
        }

        if ($character === '-' && $index + 1 < $length && $sql[$index + 1] === '-') {
            $start = $index;
            $index += 2;

            while ($index < $length) {
                $line_character = substr($sql, $index, 1);

                if ($line_character === "\r" || $line_character === "\n") {
                    break;
                }

                $index++;
            }

            $result .= substr($sql, $start, $index - $start);
            $index--;
            continue;
        }

        if ($character === '/' && $index + 1 < $length && $sql[$index + 1] === '*') {
            $start = $index;
            $index += 2;

            while ($index + 1 < $length && !($sql[$index] === '*' && $sql[$index + 1] === '/')) {
                $index++;
            }

            if ($index + 1 < $length) {
                $index += 2;
            } else {
                $index = $length;
            }

            $result .= substr($sql, $start, $index - $start);
            $index--;
            continue;
        }

        if ($character === '?') {
            $start = $index;
            $index++;
            $number_start = $index;

            while ($index < $length && ctype_digit($sql[$index])) {
                $index++;
            }

            $parameter = substr($sql, $start, $index - $start);
            $parameter_index = $index > $number_start
                ? (int) substr($sql, $number_start, $index - $number_start) - 1
                : $value_index++;
            $value = $values[$parameter_index] ?? null;

            $result .= $value instanceof SqlValue\SqlFloat
                ? 'CAST(' . $parameter . ' AS REAL)'
                : $parameter;
            $index--;
            continue;
        }

        if (
            ($character === ':' || $character === '@' || $character === '$')
            && $index + 1 < $length
            && (ctype_alpha($sql[$index + 1]) || $sql[$index + 1] === '_')
        ) {
            $start = $index++;

            while (
                $index < $length
                && (ctype_alnum($sql[$index]) || $sql[$index] === '_')
            ) {
                $index++;
            }

            $parameter = substr($sql, $start, $index - $start);
            $name = substr($parameter, 1);

            $result .= isset($named_float_parameters[$name])
                ? 'CAST(' . $parameter . ' AS REAL)'
                : $parameter;
            $index--;
            continue;
        }

        $result .= $character;
    }

    return $result;
}

function sql_value_parameter_type(SqlValue\SqlValue $value): int
{
    if ($value instanceof SqlValue\SqlNull) {
        return PDO::PARAM_NULL;
    }

    if ($value instanceof SqlValue\SqlInteger) {
        return PDO::PARAM_INT;
    }

    return PDO::PARAM_STR;
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
    $prepared_sql = $conn->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? sqlite_float_parameter_sql($sql, $sql_values)
        : $sql;

    // 1. prepare query
    $statement = $conn->pdo->prepare($prepared_sql);

    if ($statement === false) {
        throw_query_failure($conn->pdo, 'prepare', $sql);
    }

    // 2. bind and execute parameters
    foreach ($sql_values as $key => $sql_value) {
        $parameter = is_int($key) ? $key + 1 : $key;
        $bound = $statement->bindValue(
            $parameter,
            bind_sql_value($sql_value),
            sql_value_parameter_type($sql_value),
        );

        if ($bound === false) {
            throw_query_failure($statement, 'bind', $sql);
        }
    }

    $executed = $statement->execute();

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
