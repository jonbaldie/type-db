<?php

declare(strict_types=1);

namespace TypeDb;

use PDO;

class Connection
{
    public function __construct(
        public readonly PDO $pdo
    ) {}

    /**
     * @param string $sql
     * @param SqlValue\SqlValue[] $sql_values
     * @return SqlValue\SqlValue[][]
     */
    public function quickQuery(
        string $sql, array $sql_values
    ): array {
        return quick_query($this, $sql, $sql_values);
    }
}
