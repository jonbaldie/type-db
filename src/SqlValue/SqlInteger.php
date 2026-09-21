<?php

declare(strict_types=1);

namespace TypeDb\SqlValue;

class SqlInteger implements SqlValue
{
    public function __construct(
        public readonly int $value
    ) {}

    public function unwrap(): int
    {
        return $this->value;
    }

    public function toPdoParameter(): int
    {
        return $this->value;
    }

    public function pdoParameterType(): int
    {
        return \PDO::PARAM_INT;
    }
}
