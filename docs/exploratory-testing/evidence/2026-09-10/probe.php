<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use TypeDb\Connection;
use TypeDb\SqlValue\SqlFloat;
use TypeDb\SqlValue\SqlInteger;
use TypeDb\SqlValue\SqlNull;
use TypeDb\SqlValue\SqlString;

/** @return mixed */
function describe_value(mixed $value): mixed
{
    if ($value instanceof SqlString) {
        return ['type' => 'SqlString', 'value' => $value->value];
    }

    if ($value instanceof SqlFloat) {
        $reciprocal = fdiv(1.0, $value->value);

        return [
            'type' => 'SqlFloat',
            'value' => is_finite($value->value) ? $value->value : var_export($value->value, true),
            'bits' => bin2hex(pack('d', $value->value)),
            'reciprocal' => is_finite($reciprocal) ? $reciprocal : var_export($reciprocal, true),
        ];
    }

    if ($value instanceof SqlInteger) {
        return ['type' => 'SqlInteger', 'value' => $value->value];
    }

    if ($value instanceof SqlNull) {
        return ['type' => 'SqlNull'];
    }

    return $value;
}

/** @return mixed */
function describe_result(mixed $result): mixed
{
    if (!is_array($result)) {
        return describe_value($result);
    }

    $described = [];
    foreach ($result as $key => $value) {
        $described[$key] = is_array($value)
            ? describe_result($value)
            : describe_value($value);
    }

    return $described;
}

/** @param callable(): mixed $operation */
function run_case(string $name, callable $operation): array
{
    try {
        return [
            'name' => $name,
            'status' => 'ok',
            'result' => describe_result($operation()),
        ];
    } catch (\Throwable $error) {
        return [
            'name' => $name,
            'status' => 'error',
            'exception' => $error::class,
            'message' => $error->getMessage(),
        ];
    }
}

$pdo = new \PDO('sqlite::memory:');
$connection = new Connection($pdo);

TypeDb\quick_query($connection, 'create table probe (id, value, amount)');

$cases = [
    run_case('named parameters without sigils', fn (): mixed => TypeDb\quick_query(
        $connection,
        'insert into probe (id, value, amount) values (:id, :value, :amount)',
        [
            'id' => TypeDb\to_sql(1),
            'value' => TypeDb\to_sql('one'),
            'amount' => TypeDb\to_sql(1.5),
        ],
    )),
    run_case('named parameters with colon keys', fn (): mixed => TypeDb\quick_query(
        $connection,
        'insert into probe (id, value, amount) values (:id, :value, :amount)',
        [
            ':id' => TypeDb\to_sql(2),
            ':value' => TypeDb\to_sql('two'),
            ':amount' => TypeDb\to_sql(2.5),
        ],
    )),
    run_case('at-sign named parameters', fn (): mixed => TypeDb\quick_query(
        $connection,
        'insert into probe (id, value, amount) values (@id, @value, @amount)',
        [
            'id' => TypeDb\to_sql(3),
            'value' => TypeDb\to_sql('three'),
            'amount' => TypeDb\to_sql(3.5),
        ],
    )),
    run_case('at-sign named parameters with sigil keys', fn (): mixed => TypeDb\quick_query(
        $connection,
        'insert into probe (id, value, amount) values (@id, @value, @amount)',
        [
            '@id' => TypeDb\to_sql(5),
            '@value' => TypeDb\to_sql('five'),
            '@amount' => TypeDb\to_sql(5.5),
        ],
    )),
    run_case('dollar-sign named parameters', fn (): mixed => TypeDb\quick_query(
        $connection,
        'insert into probe (id, value, amount) values ($id, $value, $amount)',
        [
            'id' => TypeDb\to_sql(4),
            'value' => TypeDb\to_sql('four'),
            'amount' => TypeDb\to_sql(4.5),
        ],
    )),
    run_case('dollar-sign named parameters with sigil keys', fn (): mixed => TypeDb\quick_query(
        $connection,
        'insert into probe (id, value, amount) values ($id, $value, $amount)',
        [
            '$id' => TypeDb\to_sql(6),
            '$value' => TypeDb\to_sql('six'),
            '$amount' => TypeDb\to_sql(6.5),
        ],
    )),
    run_case('repeated named float parameter', fn (): mixed => TypeDb\quick_query(
        $connection,
        'select :x as first, :x as second',
        ['x' => TypeDb\to_sql(5.5)],
    )),
    run_case('numbered parameters', fn (): mixed => TypeDb\quick_query(
        $connection,
        'select ?2 as second, ?1 as first',
        [TypeDb\to_sql(6), TypeDb\to_sql(6.5)],
    )),
    run_case('numbered parameter followed by anonymous parameter', fn (): mixed => TypeDb\quick_query(
        $connection,
        'select ?2 as numbered, ? as anonymous',
        [TypeDb\to_sql(1), TypeDb\to_sql(2), TypeDb\to_sql(3.5)],
    )),
    run_case('quoted question marks', fn (): mixed => TypeDb\quick_query(
        $connection,
        'select "?" as identifier, ? as value, \'?\' as literal',
        [TypeDb\to_sql(7.5)],
    )),
    run_case('comment question marks', fn (): mixed => TypeDb\quick_query(
        $connection,
        "select ? as value /* ? */ -- ?\n",
        [TypeDb\to_sql(8.5)],
    )),
    run_case('negative zero float round trip', function () use ($connection): mixed {
        $input = -0.0;
        TypeDb\quick_query($connection, 'create table negative_zero (value real)');
        TypeDb\quick_query(
            $connection,
            'insert into negative_zero (value) values (?)',
            [TypeDb\to_sql($input)],
        );

        return [
            'input_bits' => bin2hex(pack('d', $input)),
            'result' => TypeDb\quick_query($connection, 'select value from negative_zero'),
        ];
    }),
];

$cases[] = run_case('all inserted rows', fn (): mixed => TypeDb\quick_query(
    $connection,
    'select id, value, amount from probe order by id',
));

$cases[] = run_case('quoted unicode and NUL text parameter', function () use ($connection): mixed {
    $input = "O'Reilly; ? -- /* not SQL */ café\0tail";
    TypeDb\quick_query($connection, 'create table text_edge (value text)');
    TypeDb\quick_query(
        $connection,
        'insert into text_edge (value) values (?)',
        [TypeDb\to_sql($input)],
    );

    return [
        'input' => $input,
        'result' => TypeDb\quick_query($connection, 'select value from text_edge'),
    ];
});

$cases[] = run_case('failed write followed by valid retry', function () use ($connection): mixed {
    TypeDb\quick_query($connection, 'create table retry (id integer not null)');
    $failed = null;

    try {
        TypeDb\quick_query(
            $connection,
            'insert into retry (id) values (?)',
            [TypeDb\to_sql(null)],
        );
    } catch (\Throwable $error) {
        $failed = [
            'exception' => $error::class,
            'message' => $error->getMessage(),
        ];
    }

    TypeDb\quick_query(
        $connection,
        'insert into retry (id) values (?)',
        [TypeDb\to_sql(10)],
    );

    return [
        'failed_attempt' => $failed,
        'rows_after_retry' => TypeDb\quick_query($connection, 'select id from retry'),
    ];
});

$cases[] = run_case('persistent database survives connection reopen', function (): mixed {
    $path = tempnam(sys_get_temp_dir(), 'type-db-explore-');

    if ($path === false) {
        throw new \RuntimeException('Could not allocate a temporary SQLite path.');
    }

    try {
        $firstConnection = new Connection(new \PDO('sqlite:' . $path));
        TypeDb\quick_query($firstConnection, 'create table persisted (value text)');
        TypeDb\quick_query(
            $firstConnection,
            'insert into persisted (value) values (?)',
            [TypeDb\to_sql('saved')],
        );

        $reopenedConnection = new Connection(new \PDO('sqlite:' . $path));

        return TypeDb\quick_query($reopenedConnection, 'select value from persisted');
    } finally {
        unlink($path);
    }
});

$stringifiedPdo = new \PDO('sqlite::memory:');
$stringifiedPdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
$stringifiedConnection = new Connection($stringifiedPdo);
TypeDb\quick_query($stringifiedConnection, 'create table no_affinity (integer_value, real_value)');
TypeDb\quick_query(
    $stringifiedConnection,
    'insert into no_affinity (integer_value, real_value) values (?, ?)',
    [TypeDb\to_sql(9), TypeDb\to_sql(9.5)],
);
$cases[] = run_case('stringified values from affinity-neutral columns', fn (): mixed => TypeDb\quick_query(
    $stringifiedConnection,
    'select integer_value, real_value from no_affinity',
));

$stringifiedInfinityPdo = new \PDO('sqlite::memory:');
$stringifiedInfinityPdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
$stringifiedInfinityConnection = new Connection($stringifiedInfinityPdo);
TypeDb\quick_query($stringifiedInfinityConnection, 'create table stringified_infinity (value real)');
TypeDb\quick_query(
    $stringifiedInfinityConnection,
    'insert into stringified_infinity (value) values (?), (?)',
    [TypeDb\to_sql(INF), TypeDb\to_sql(-INF)],
);
$cases[] = run_case('stringified infinity values', function () use ($stringifiedInfinityConnection, $stringifiedInfinityPdo): mixed {
    return [
        'raw_pdo_values' => $stringifiedInfinityPdo
            ->query('select value from stringified_infinity order by rowid')
            ->fetchAll(\PDO::FETCH_COLUMN),
        'quick_query_values' => TypeDb\quick_query(
            $stringifiedInfinityConnection,
            'select value from stringified_infinity order by rowid',
        ),
    ];
});

echo json_encode($cases, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
