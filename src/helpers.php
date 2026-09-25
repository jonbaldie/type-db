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

function throw_unsupported_sqlite_named_parameter(string $parameter): never
{
    throw new \InvalidArgumentException(
        sprintf(
            'SQLite named parameter "%s" is not supported; use a ":"-prefixed name instead.',
            $parameter,
        )
    );
}


/**
 * Restore a result name after SQLite's internal float-parameter rewrite.
 *
 * Matching is case-insensitive because `PDO::ATTR_CASE` (`CASE_LOWER` /
 * `CASE_UPPER`) normalizes the case of column names PDO reports before this
 * function sees them, while the rewrite template is generated in a fixed
 * case. When restoring the parameter name, the matched parameter text from
 * within the CAST expression is preserved so that the connection's case
 * normalization is respected.
 *
 * @param list<array{rewritten: string, original: string}> $float_rewrites
 */
function sqlite_result_column_name(string $name, array $float_rewrites): string
{
    $seen = [];

    foreach ($float_rewrites as $rewrite) {
        $rewrite_key = $rewrite['rewritten'] . "\0" . $rewrite['original'];

        if (isset($seen[$rewrite_key])) {
            continue;
        }

        $seen[$rewrite_key] = true;
        $prefix = 'CAST(';

        if (
            str_starts_with($rewrite['rewritten'], $prefix)
            && substr($rewrite['rewritten'], strlen($prefix), strlen($rewrite['original'])) === $rewrite['original']
        ) {
            $suffix = substr($rewrite['rewritten'], strlen($prefix) + strlen($rewrite['original']));
            $pattern = '/' . preg_quote($prefix, '/') . '(' . preg_quote($rewrite['original'], '/') . ')' . preg_quote($suffix, '/') . '/i';
            $name = preg_replace_callback(
                $pattern,
                static fn (array $matches): string => (string) $matches[1],
                $name,
            ) ?? $name;
        } else {
            $name = preg_replace_callback(
                '/' . preg_quote($rewrite['rewritten'], '/') . '/i',
                static fn (): string => $rewrite['original'],
                $name,
            ) ?? $name;
        }
    }

    return $name;
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

/**
 * Whether the byte is a valid SQLite identifier character. SQLite's tokenizer
 * treats `$`, `_`, alphanumerics, and any byte >= 0x80 as IdChar, so tokens
 * like :a$b and UTF-8 names must not terminate mid-identifier. ctype_alnum()
 * alone is byte-class-based and rejects `$`, continuation bytes (and, under
 * the C locale, all bytes >= 128).
 */
function is_sqlite_identifier_byte(string $byte): bool
{
    return ctype_alnum($byte) || $byte === '_' || $byte === '$' || ord($byte) >= 0x80;
}

/**
 * SQLite has no PDO parameter type for floats. Cast float placeholders in the
 * SQL expression so that SQLite receives a REAL value instead of text.
 * When requested, generated casts receive an internal marker so result names
 * can be restored without changing user-written literals or aliases.
 *
 * @param SqlValue\SqlValue[] $sql_values
 * @param list<array{rewritten: string, original: string}> &$float_rewrites
 * @param bool $mark_rewrites
 */
function sqlite_float_parameter_sql(
    string $sql,
    array $sql_values,
    array &$float_rewrites = [],
    bool $mark_rewrites = false,
): string
{
    $float_rewrites = [];

    // Keep integer keys aligned with bindValue()'s positional parameter indexes.
    // Re-indexing would let named values shift the values checked for "?".
    $values = $sql_values;
    $named_float_parameters = [];
    $named_parameters = [];

    foreach ($sql_values as $key => $value) {
        if (!is_int($key)) {
            $string_key = (string) $key;

            if (str_starts_with($string_key, '@') || str_starts_with($string_key, '$')) {
                throw_unsupported_sqlite_named_parameter($string_key);
            }

            $name = ltrim($string_key, ':');
            $named_parameters[$name] = true;

            if (is_float(from_sql($value))) {
                $named_float_parameters[$name] = true;
            }
        }
    }

    $result = '';
    $value_index = 0;
    $rewrite_index = 0;
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
            // SQLite numbers an anonymous "?" one past the largest parameter
            // number assigned so far, including explicit "?NNN" numbers.
            $parameter_index = $index > $number_start
                ? (int) substr($sql, $number_start, $index - $number_start) - 1
                : $value_index;
            $value_index = max($value_index, $parameter_index + 1);
            if (array_key_exists($parameter_index, $values) && is_float(from_sql($values[$parameter_index]))) {
                $rewritten_parameter = 'CAST(' . $parameter . ' AS REAL)';
                $rewritten_parameter .= match ($mark_rewrites) {
                    true => ' /* type-db:float-rewrite-' . ++$rewrite_index . ' */',
                    false => '',
                };

                $result .= $rewritten_parameter;
                $float_rewrites[] = [
                    'rewritten' => $rewritten_parameter,
                    'original' => $parameter,
                ];
            } else {
                $result .= $parameter;
            }

            $index--;
            continue;
        }

        if (
            ($character === ':' || $character === '@' || $character === '$')
            && $index + 1 < $length
            && is_sqlite_identifier_byte($sql[$index + 1])
        ) {
            $start = $index++;

            while ($index < $length) {
                if (is_sqlite_identifier_byte($sql[$index])) {
                    $index++;
                    continue;
                }

                if (
                    $sql[$index] === ':'
                    && $index + 2 < $length
                    && $sql[$index + 1] === ':'
                    && is_sqlite_identifier_byte($sql[$index + 2])
                ) {
                    $index += 2;
                    continue;
                }

                break;
            }

            if ($index < $length && $sql[$index] === '(') {
                $paren_depth = 0;
                $paren_index = $index;

                while ($paren_index < $length) {
                    if ($sql[$paren_index] === '(') {
                        $paren_depth++;
                    } elseif ($sql[$paren_index] === ')') {
                        $paren_depth--;

                        if ($paren_depth === 0) {
                            $paren_index++;
                            break;
                        }
                    }

                    $paren_index++;
                }

                if ($paren_depth === 0) {
                    $index = $paren_index;
                }
            }

            $parameter = substr($sql, $start, $index - $start);
            $name = substr($parameter, 1);

            if (($character === '@' || $character === '$') && isset($named_parameters[$name])) {
                throw_unsupported_sqlite_named_parameter($parameter);
            }

            if (isset($named_float_parameters[$name])) {
                $rewritten_parameter = 'CAST(' . $parameter . ' AS REAL)';
                $rewritten_parameter .= match ($mark_rewrites) {
                    true => ' /* type-db:float-rewrite-' . ++$rewrite_index . ' */',
                    false => '',
                };

                $result .= $rewritten_parameter;
                $float_rewrites[] = [
                    'rewritten' => $rewritten_parameter,
                    'original' => $parameter,
                ];
            } else {
                $result .= $parameter;
            }

            $index--;
            continue;
        }

        $result .= $character;
    }

    return $result;
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
    $float_rewrites = [];
    $prepared_sql = $conn->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? sqlite_float_parameter_sql($sql, $sql_values, $float_rewrites, true)
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

    // 3. interpret result and turn result values into SqlValue objects
    $hydrator = new RowHydrator(
        $statement,
        static fn (string $name): string => sqlite_result_column_name($name, $float_rewrites),
        $sql,
    );

    // 4. return full result
    return $hydrator->fetchAll();
}
