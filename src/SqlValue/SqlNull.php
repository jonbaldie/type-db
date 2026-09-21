<?php

declare(strict_types=1);

namespace TypeDb\SqlValue;

class SqlNull implements SqlValue
{
    public function unwrap(): mixed
    {
        return null;
    }

    public function toPdoParameter(): mixed
    {
        return null;
    }

    public function pdoParameterType(): int
    {
        return \PDO::PARAM_NULL;
    }
}
