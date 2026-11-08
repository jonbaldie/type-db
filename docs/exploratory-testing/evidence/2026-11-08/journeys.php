<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload.php';

use TypeDb\Connection;
use function TypeDb\from_sql;
use function TypeDb\to_sql;

function check(string $label, mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    echo $label, ': ', json_encode($actual, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), "\n";
}

function unpackRows(array $rows): array
{
    return array_map(static fn (array $row): array => array_map(from_sql(...), $row), $rows);
}

for ($run = 1; $run <= 3; $run++) {
    echo "RUN $run\n";
    $path = __DIR__ . '/scratch-' . getmypid() . '.sqlite';
    if (file_exists($path)) {
        throw new RuntimeException('Scratch database already exists');
    }
    try {
        $pdo = new PDO('sqlite:' . $path);
        $c = new Connection($pdo);
        $c->quickQuery('create table items (id integer primary key, name text not null, amount)');
        $c->quickQuery('insert into items values (?, ?, ?), (?, ?, ?)', array_map(to_sql(...), [1, 'bar', 1.25, 2, 'baz', null]));
        check('created rows', unpackRows($c->quickQuery('select id, name, amount, typeof(amount) as storage from items order by id')), [
            ['id' => 1, 'name' => 'bar', 'amount' => 1.25, 'storage' => 'real'],
            ['id' => 2, 'name' => 'baz', 'amount' => null, 'storage' => 'null'],
        ]);
        unset($c, $pdo);
        $pdo = new PDO('sqlite:' . $path);
        $c = new Connection($pdo);
        check('reopened rows', unpackRows($c->quickQuery('select id, name, amount from items where id = ? or name = ? order by id', array_map(to_sql(...), [1, 'baz']))), [
            ['id' => 1, 'name' => 'bar', 'amount' => 1.25],
            ['id' => 2, 'name' => 'baz', 'amount' => null],
        ]);
        check('named repeated float', unpackRows($c->quickQuery('select :n as n, typeof(:n) as kind, :n + 1 as plus', ['n' => to_sql(2.5)])), [['n' => 2.5, 'kind' => 'real', 'plus' => 3.5]]);
        check('numbered float names', unpackRows($c->quickQuery('select ?2, ?1', [to_sql('text'), to_sql(3.5)])), [['?2' => 3.5, '?1' => 'text']]);
        $pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
        check('case and float names', unpackRows($c->quickQuery('select :amount, :other as alias', ['amount' => to_sql(1.5), 'other' => to_sql(4.5)])), [[':AMOUNT' => 1.5, 'ALIAS' => 4.5]]);
        $pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);
        try {
            $c->quickQuery('select :n', [to_sql(1.5)]);
            throw new RuntimeException('mismatched binding unexpectedly accepted');
        } catch (InvalidArgumentException $e) {
            check('mismatched binding rejected', get_class($e), InvalidArgumentException::class);
        }
        $pdo->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_TO_STRING);
        check('null and empty remain distinct', unpackRows($c->quickQuery("select null as n, '' as empty")), [['n' => null, 'empty' => '']]);
        check('null attribute restored', $pdo->getAttribute(PDO::ATTR_ORACLE_NULLS), PDO::NULL_TO_STRING);
        $pdo->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        try {
            $c->quickQuery('insert into items values (?, ?, ?)', array_map(to_sql(...), [1, 'duplicate', 10]));
            throw new RuntimeException('duplicate unexpectedly accepted');
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'UNIQUE')) {
                throw $e;
            }
            check('duplicate rejected', get_class($e), RuntimeException::class);
        }
        check('failed write unchanged', unpackRows($c->quickQuery('select count(*) as n from items')), [['n' => 2]]);
        $c->quickQuery('update items set name = ? where id = ?', array_map(to_sql(...), ['fixed', 1]));
        unset($c, $pdo);
        $c = new Connection(new PDO('sqlite:' . $path));
        check('corrected write persists', unpackRows($c->quickQuery('select name from items where id = ?', [to_sql(1)])), [['name' => 'fixed']]);
        unset($c);
    } finally {
        if (file_exists($path) && !unlink($path)) {
            throw new RuntimeException('Cannot remove scratch database');
        }
    }
}
