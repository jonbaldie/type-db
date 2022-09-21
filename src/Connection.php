<?php

declare(strict_types=1);

namespace TypeDb;

use PDO;

class Connection
{
    public function __construct(
        public readonly PDO $pdo
    ) {}
}
