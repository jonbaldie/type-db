<?php

declare(strict_types=1);

namespace TypeDb;

/**
 * Rewrites SQL for SQLite, which has no PDO parameter type for floats.
 *
 * Float placeholders are cast in the SQL expression so that SQLite receives a
 * REAL value instead of text. Each generated cast carries an internal marker
 * comment so the resulting QueryPlan can restore result column names without
 * touching user-written literals or aliases.
 */
final class SqlRewriter
{
    /**
     * @param SqlValue\SqlValue[] $sql_values
     */
    public function rewrite(string $sql, array $sql_values): QueryPlan
    {
        $rewrites = [];

        // Keep integer keys aligned with bindValue()'s positional parameter indexes.
        // Re-indexing would let named values shift the values checked for "?".
        $values = $sql_values;
        $named_float_parameters = [];
        $named_parameters = [];

        foreach ($sql_values as $key => $value) {
            if (!is_int($key)) {
                $string_key = (string) $key;

                if (str_starts_with($string_key, '@') || str_starts_with($string_key, '$')) {
                    self::throwUnsupportedNamedParameter($string_key);
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
                    $result .= self::castToReal($parameter, $rewrites);
                } else {
                    $result .= $parameter;
                }

                $index--;
                continue;
            }

            if (
                ($character === ':' || $character === '@' || $character === '$')
                && $index + 1 < $length
                && self::isIdentifierByte($sql[$index + 1])
            ) {
                $start = $index++;

                while ($index < $length) {
                    if (self::isIdentifierByte($sql[$index])) {
                        $index++;
                        continue;
                    }

                    if (
                        $sql[$index] === ':'
                        && $index + 2 < $length
                        && $sql[$index + 1] === ':'
                        && self::isIdentifierByte($sql[$index + 2])
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
                    self::throwUnsupportedNamedParameter($parameter);
                }

                if (isset($named_float_parameters[$name])) {
                    $result .= self::castToReal($parameter, $rewrites);
                } else {
                    $result .= $parameter;
                }

                $index--;
                continue;
            }

            $result .= $character;
        }

        return new QueryPlan($result, $rewrites);
    }

    /**
     * Each cast carries a numbered marker so that its result column name can
     * be told apart from user-written SQL of the same shape.
     *
     * @param list<array{rewritten: string, original: string}> $rewrites
     */
    private static function castToReal(string $parameter, array &$rewrites): string
    {
        $rewritten = 'CAST(' . $parameter . ' AS REAL) /* type-db:float-rewrite-' . (count($rewrites) + 1) . ' */';
        $rewrites[] = [
            'rewritten' => $rewritten,
            'original' => $parameter,
        ];

        return $rewritten;
    }

    /**
     * Whether the byte is a valid SQLite identifier character. SQLite's tokenizer
     * treats `$`, `_`, alphanumerics, and any byte >= 0x80 as IdChar, so tokens
     * like :a$b and UTF-8 names must not terminate mid-identifier. ctype_alnum()
     * alone is byte-class-based and rejects `$`, continuation bytes (and, under
     * the C locale, all bytes >= 128).
     */
    private static function isIdentifierByte(string $byte): bool
    {
        return ctype_alnum($byte) || $byte === '_' || $byte === '$' || ord($byte) >= 0x80;
    }

    private static function throwUnsupportedNamedParameter(string $parameter): never
    {
        throw new \InvalidArgumentException(
            sprintf(
                'SQLite named parameter "%s" is not supported; use a ":"-prefixed name instead.',
                $parameter,
            )
        );
    }
}
