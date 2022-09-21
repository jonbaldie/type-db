<?php

declare(strict_types=1);

namespace TypeDb;

use PDO;

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
    array $sql_values,
): array
{
    // 1. prepare query
    $statement = $conn->pdo->prepare($sql);

    // 2. execute with parameters
    $statement->execute(
        array_map('\TypeDb\from_sql', $sql_values)
    );

    // 3. interpret result
    $results = $statement->fetchAll(PDO::FETCH_ASSOC);

    // 4. turn result values into SqlValue objects
    $return = array_map(
        fn (array $row) => array_map('\TypeDb\to_sql', $row),
        $results
    );

    // 5. return full result
    return $return;
}

