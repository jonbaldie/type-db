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

// Bug 1: Multi-row string coercion to 0/0.0 when earlier row has number
$multiRowPdo = new \PDO('sqlite::memory:');
$multiRowConnection = new Connection($multiRowPdo);
$unionResults = TypeDb\quick_query(
    $multiRowConnection,
    "select 1 as val union all select 'hello'",
);

TypeDb\quick_query($multiRowConnection, 'create table untyped_items (val)');
TypeDb\quick_query(
    $multiRowConnection,
    'insert into untyped_items (val) values (?), (?)',
    [TypeDb\to_sql(42), TypeDb\to_sql('hello')],
);
$tableForwardResults = TypeDb\quick_query(
    $multiRowConnection,
    'select val from untyped_items order by rowid asc',
);
$tableBackwardResults = TypeDb\quick_query(
    $multiRowConnection,
    'select val from untyped_items order by rowid desc',
);

// Bug 2: Parameter index misalignment when named parameters precede positional parameters in $sql_values
$mixedParamPdo = new \PDO('sqlite::memory:');
$mixedParamConnection = new Connection($mixedParamPdo);
$mixedParamResult1 = TypeDb\quick_query(
    $mixedParamConnection,
    'select ? as pos, :name as named',
    ['name' => TypeDb\to_sql(1.5), 0 => TypeDb\to_sql('test')],
)[0];
$mixedParamResult2 = TypeDb\quick_query(
    $mixedParamConnection,
    'select ? as pos, :name as named',
    ['name' => TypeDb\to_sql('test'), 0 => TypeDb\to_sql(1.5)],
)[0];

// Bug 3: Named parameters starting with digits ignored by float rewrite
$digitParamPdo = new \PDO('sqlite::memory:');
$digitParamConnection = new Connection($digitParamPdo);
$digitParamResult = TypeDb\quick_query(
    $digitParamConnection,
    'select :1a as res',
    [':1a' => TypeDb\to_sql(1.5)],
)[0]['res'];

echo json_encode([
    'multi_row_type_coercion' => [
        'union_actual' => array_map(fn (array $r) => describe_sql_value($r['val']), $unionResults),
        'table_forward' => array_map(fn (array $r) => describe_sql_value($r['val']), $tableForwardResults),
        'table_backward' => array_map(fn (array $r) => describe_sql_value($r['val']), $tableBackwardResults),
    ],
    'mixed_named_positional_order' => [
        'named_float_before_positional_string' => [
            'pos' => describe_sql_value($mixedParamResult1['pos']),
            'named' => describe_sql_value($mixedParamResult1['named']),
        ],
        'named_string_before_positional_float' => [
            'pos' => describe_sql_value($mixedParamResult2['pos']),
            'named' => describe_sql_value($mixedParamResult2['named']),
        ],
    ],
    'digit_prefixed_named_parameter' => [
        'actual' => describe_sql_value($digitParamResult),
    ],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
