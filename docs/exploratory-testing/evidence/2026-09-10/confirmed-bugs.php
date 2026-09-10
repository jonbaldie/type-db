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

$negativeZeroPdo = new \PDO('sqlite::memory:');
$negativeZeroConnection = new Connection($negativeZeroPdo);
TypeDb\quick_query($negativeZeroConnection, 'create table negative_zero (value real)');
$negativeZero = -0.0;
TypeDb\quick_query(
    $negativeZeroConnection,
    'insert into negative_zero (value) values (?)',
    [TypeDb\to_sql($negativeZero)],
);
$negativeZeroResult = TypeDb\quick_query($negativeZeroConnection, 'select value from negative_zero')[0]['value'];

$stringifiedInfinityPdo = new \PDO('sqlite::memory:');
$stringifiedInfinityPdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
$stringifiedInfinityConnection = new Connection($stringifiedInfinityPdo);
TypeDb\quick_query($stringifiedInfinityConnection, 'create table stringified_infinity (value real)');
TypeDb\quick_query(
    $stringifiedInfinityConnection,
    'insert into stringified_infinity (value) values (?), (?)',
    [TypeDb\to_sql(INF), TypeDb\to_sql(-INF)],
);
$rawInfinityValues = $stringifiedInfinityPdo
    ->query('select value from stringified_infinity order by rowid')
    ->fetchAll(\PDO::FETCH_COLUMN);
$stringifiedInfinityResults = TypeDb\quick_query(
    $stringifiedInfinityConnection,
    'select value from stringified_infinity order by rowid',
);

$mixedParameterPdo = new \PDO('sqlite::memory:');
$mixedParameterConnection = new Connection($mixedParameterPdo);
$mixedParameterResult = TypeDb\quick_query(
    $mixedParameterConnection,
    'select ?2 as numbered, ? as anonymous',
    [TypeDb\to_sql(1), TypeDb\to_sql(2), TypeDb\to_sql(3.5)],
)[0];

echo json_encode([
    'negative_zero' => [
        'input_bits' => bin2hex(pack('d', $negativeZero)),
        'result' => describe_sql_value($negativeZeroResult),
    ],
    'stringified_infinity' => [
        'raw_pdo_values' => $rawInfinityValues,
        'quick_query_values' => array_map(
            fn (array $row): array => describe_sql_value($row['value']),
            $stringifiedInfinityResults,
        ),
    ],
    'numbered_then_anonymous' => [
        'expected' => [
            'numbered' => ['class' => SqlInteger::class, 'value' => 2],
            'anonymous' => ['class' => SqlFloat::class, 'value' => 3.5],
        ],
        'actual' => [
            'numbered' => describe_sql_value($mixedParameterResult['numbered']),
            'anonymous' => describe_sql_value($mixedParameterResult['anonymous']),
        ],
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
