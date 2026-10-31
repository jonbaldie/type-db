<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload.php';

use TypeDb\Connection;
use function TypeDb\from_sql;
use function TypeDb\quick_query;
use function TypeDb\to_sql;

function show(string $label, mixed $value): void
{
    echo $label, ': ', json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), "\n";
}

function values(array $rows): array
{
    return array_map(static fn (array $row): array => array_map(from_sql(...), $row), $rows);
}

for ($run = 1; $run <= 3; $run++) {
    echo "RUN $run\n";
    $path = tempnam(__DIR__, 'type-db-explore-');
    if ($path === false) {
        throw new RuntimeException('Cannot create scratch database');
    }
    try {
        $pdo = new PDO('sqlite:' . $path);
        $connection = new Connection($pdo);
        $connection->quickQuery('create table items (id integer primary key, value text not null, amount real)');
        $connection->quickQuery('insert into items values (?, ?, ?), (?, ?, ?)', array_map(to_sql(...), [1, 'bar', 0.1 + 0.2, 2, 'baz', null]));
        show('quickstart', values($connection->quickQuery('select id, value from items where id = ? or value = ? order by id', array_map(to_sql(...), [1, 'baz']))));
        unset($connection, $pdo);
        $pdo = new PDO('sqlite:' . $path);
        $connection = new Connection($pdo);
        show('reopened', values(quick_query($connection, 'select id, value, amount from items order by id')));
        show('named float', values(quick_query($connection, 'select :amount as amount, typeof(:amount) as kind', ['amount' => to_sql(1.5)])));
        show('positional to named float', values(quick_query($connection, 'select :amount as amount, typeof(:amount) as kind', [to_sql(1.5)])));
        show('numbered parameters', values(quick_query($connection, 'select ?2 as second, ?1 as first', [to_sql('x'), to_sql(2.5)])));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        try {
            quick_query($connection, 'insert into items (id, value) values (?, ?)', [to_sql(1), to_sql('duplicate')]);
            echo "duplicate: UNEXPECTED SUCCESS\n";
        } catch (RuntimeException $e) {
            show('duplicate exception', get_class($e));
        }
        show('after error', values(quick_query($connection, 'select count(*) as count from items')));
        quick_query($connection, 'update items set value = ? where id = ?', [to_sql('corrected'), to_sql(1)]);
        show('corrected', values(quick_query($connection, 'select value from items where id = ?', [to_sql(1)])));
        unset($connection, $pdo);
        $pdo = new PDO('sqlite:' . $path);
        $connection = new Connection($pdo);
        show('corrected after reopen', values(quick_query($connection, 'select value from items where id = ?', [to_sql(1)])));
        unset($connection, $pdo);
    } finally {
        if (file_exists($path) && !unlink($path)) {
            throw new RuntimeException('Cannot remove scratch database: ' . $path);
        }
    }
}
