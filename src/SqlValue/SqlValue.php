<?php

declare(strict_types=1);

namespace TypeDb\SqlValue;

/**
 * A value that can be represented in a SQL query.
 *
 * The operations are documented here rather than declared as abstract methods
 * so marker implementations from earlier releases remain constructible and
 * can be rejected by the helpers with InvalidArgumentException.
 *
 * @method string|float|int|null unwrap()
 * @method mixed toPdoParameter()
 * @method int pdoParameterType()
 */
interface SqlValue
{
}
