<?php

declare(strict_types=1);

namespace TypeDb\SqlValue;

class SqlString implements SqlValue
{
    public function __construct(
        public readonly string $value
    ) {}
}
