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
    if (!is_callable([$value, 'unwrap'])) {
        throw_unsupported_sql_value($value);
    }

    return $value->unwrap();
}

function throw_unsupported_sql_value(SqlValue\SqlValue $value): never
{
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
 * @return mixed
 */
function bind_sql_value(SqlValue\SqlValue $value): mixed
{
    if (!is_callable([$value, 'toPdoParameter'])) {
        throw_unsupported_sql_value($value);
    }

    return $value->toPdoParameter();
}

function sql_value_parameter_type(SqlValue\SqlValue $value): int
{
    if (!is_callable([$value, 'pdoParameterType'])) {
        throw_unsupported_sql_value($value);
    }

    return $value->pdoParameterType();
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
    $plan = $conn->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? (new SqlRewriter())->rewrite($sql, $sql_values)
        : QueryPlan::unchanged($sql);

    // 1. prepare query
    $statement = $conn->pdo->prepare($plan->getPreparedSql());

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

    // 3. interpret result and turn result values into SqlValue objects
    $hydrator = new RowHydrator(
        $statement,
        $plan->hasRewrites()
            ? $plan->restoreColumnName(...)
            : static fn (string $name): string => $name,
        $sql,
    );

    // PDO::NULL_TO_STRING erases the distinction between SQL NULL and an empty
    // string before hydration, so fetch with PDO's natural null handling.
    $oracleNulls = $conn->pdo->getAttribute(PDO::ATTR_ORACLE_NULLS);
    if ($oracleNulls === PDO::NULL_TO_STRING) {
        $conn->pdo->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);

        try {
            return $hydrator->fetchAll();
        } finally {
            $conn->pdo->setAttribute(PDO::ATTR_ORACLE_NULLS, $oracleNulls);
        }
    }

    // 4. return full result
    return $hydrator->fetchAll();
}
