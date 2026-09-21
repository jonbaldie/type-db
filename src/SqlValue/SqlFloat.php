<?php

declare(strict_types=1);

namespace TypeDb\SqlValue;

class SqlFloat implements SqlValue
{
    public function __construct(
        public readonly float $value
    ) {}

    public function unwrap(): float
    {
        return $this->value;
    }

    public function toPdoParameter(): string
    {
        return \TypeDb\round_trip_float_string($this->value);
    }

    public function pdoParameterType(): int
    {
        return \PDO::PARAM_STR;
    }
}
