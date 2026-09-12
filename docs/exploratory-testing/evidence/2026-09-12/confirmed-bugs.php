<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use TypeDb\Connection;
use TypeDb\SqlValue\SqlFloat;
use TypeDb\SqlValue\SqlInteger;
use TypeDb\SqlValue\SqlString;
use TypeDb\SqlValue\SqlValue;

/** @return array<string, mixed> */
function describe_sql_value(SqlValue $value): array
{
    if ($value instanceof SqlFloat) {
        return [
            'class' => $value::class,
            'value' => is_finite($value->value) ? $value->value : var_export($value->value, true),
            'bits' => bin2hex(pack('d', $value->value)),
        ];
    }

    if ($value instanceof SqlInteger) {
        return ['class' => $value::class, 'value' => $value->value];
    }

    if ($value instanceof SqlString) {
        return ['class' => $value::class, 'value' => $value->value];
    }

    return ['class' => $value::class];
}

// Bug 1: Non-ASCII / Unicode named parameter handling in sqlite_float_parameter_sql()
$nonAsciiPdo = new \PDO('sqlite::memory:');
$nonAsciiConn = new Connection($nonAsciiPdo);

// 1a. Float degradation for :café
$cafeResult = TypeDb\quick_query(
    $nonAsciiConn,
    'select :café as res',
    [':café' => TypeDb\to_sql(1.5)],
)[0]['res'];

// 1b. Float degradation for :π
$piResult = TypeDb\quick_query(
    $nonAsciiConn,
    'select :π as res',
    [':π' => TypeDb\to_sql(3.14)],
)[0]['res'];

// 1c. SQL syntax corruption under C locale
$corruptResult = null;
setlocale(LC_ALL, 'C');
try {
    TypeDb\quick_query(
        $nonAsciiConn,
        'select :café as res',
        [':caf' => TypeDb\to_sql(1.5)],
    );
} catch (\Throwable $e) {
    $corruptResult = [
        'class' => $e::class,
        'message' => $e->getMessage(),
        'rewritten_sql' => TypeDb\sqlite_float_parameter_sql('select :café as res', [':caf' => TypeDb\to_sql(1.5)]),
    ];
}

// 1d. Pre-flight bypass for @über under C locale
$preflightBypassResult = null;
try {
    TypeDb\quick_query(
        $nonAsciiConn,
        'select @über as res',
        ['über' => TypeDb\to_sql(1)],
    );
} catch (\Throwable $e) {
    $preflightBypassResult = [
        'class' => $e::class,
        'message' => $e->getMessage(),
    ];
}

// Bug 2: Dollar-containing named parameter handling in sqlite_float_parameter_sql()
$dollarPdo = new \PDO('sqlite::memory:');
$dollarConn = new Connection($dollarPdo);

// 2a. Float degradation for :a$b
$dollarParamResult = TypeDb\quick_query(
    $dollarConn,
    'select :a$b as res',
    [':a$b' => TypeDb\to_sql(1.5)],
)[0]['res'];

// 2b. False positive exception for :a$b with key 'b'
$falsePositiveResult = null;
try {
    TypeDb\quick_query(
        $dollarConn,
        'select :a$b as res',
        ['b' => TypeDb\to_sql('test')],
    );
} catch (\Throwable $e) {
    $falsePositiveResult = [
        'class' => $e::class,
        'message' => $e->getMessage(),
    ];
}

echo json_encode([
    'non_ascii_named_parameter' => [
        'cafe_actual' => describe_sql_value($cafeResult),
        'pi_actual' => describe_sql_value($piResult),
        'sql_corruption_under_c_locale' => $corruptResult,
        'preflight_bypass_under_c_locale' => $preflightBypassResult,
    ],
    'dollar_in_named_parameter' => [
        'actual' => describe_sql_value($dollarParamResult),
        'false_positive_exception' => $falsePositiveResult,
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
