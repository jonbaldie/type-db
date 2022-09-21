<?php

declare(strict_types=1);

namespace TypeDb\SqlValue;

class SqlInteger implements SqlValue
{
    public function __construct(
        public readonly int $value
    ) {}
}
