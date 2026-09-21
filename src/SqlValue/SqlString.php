<?php

declare(strict_types=1);

namespace TypeDb\SqlValue;

class SqlString implements SqlValue
{
    public function __construct(
        public readonly string $value
    ) {}

    public function unwrap(): string
    {
        return $this->value;
    }

    public function toPdoParameter(): string
    {
        return $this->value;
    }

    public function pdoParameterType(): int
    {
        return \PDO::PARAM_STR;
    }
}
