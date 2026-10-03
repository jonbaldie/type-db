<?php
declare(strict_types=1);
require __DIR__ . '/../../../../vendor/autoload.php';
use function TypeDb\from_sql;
function fmt(array $r): string { return json_encode(array_map(fn($row) => array_map(fn($v) => (new ReflectionClass($v))->getShortName() . '(' . var_export(from_sql($v), true) . ')', $row), $r)); }
$makers = [
  'sqlite' => [fn() => new PDO('sqlite::memory:'), 'create table a (ti integer, si integer, i integer, bi integer, f real, d real, s text)'],
  'mysql'  => [fn() => new PDO('mysql:host=127.0.0.1;port=33306;dbname=t', 'root', 'pw'), 'create table a (ti tinyint, si smallint, i int, bi bigint, f float, d double, s text)'],
  'pgsql'  => [fn() => new PDO('pgsql:host=127.0.0.1;port=35432;dbname=postgres', 'postgres', 'pw'), 'create table a (ti smallint, si smallint, i int, bi bigint, f real, d double precision, s text)'],
];
for ($run = 1; $run <= 3; $run++) { echo "--- run $run\n";
foreach ($makers as $name => [$make, $ddl]) {
  foreach (['default' => [], 'NULL_TO_STRING' => [PDO::ATTR_ORACLE_NULLS => PDO::NULL_TO_STRING], 'STRINGIFY' => [PDO::ATTR_STRINGIFY_FETCHES => true]] as $label => $attrs) {
    $pdo = $make(); foreach ($attrs as $k => $v) $pdo->setAttribute($k, $v);
    $pdo->exec('drop table if exists a'); $pdo->exec($ddl);
    $pdo->exec("insert into a values (1, 2, 3, 4, 1.5, 2.5, 'x'), (null, null, null, null, null, null, null)");
    $c = new TypeDb\Connection($pdo);
    echo str_pad("$name/$label", 22), fmt($c->quickQuery('select ti, si, i, bi, f, d, s from a order by i is null, i')), PHP_EOL;
  }
}}
