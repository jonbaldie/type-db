<?php

declare(strict_types=1);

namespace TypeDb\SqlValue;

class SqlFloat implements SqlValue
{
    public function __construct(
        public readonly float $value
    ) {}
}
