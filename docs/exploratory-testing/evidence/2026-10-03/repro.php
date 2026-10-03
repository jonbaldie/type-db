<?php
// Minimal reproducers for the confirmed bugs of the 2026-10-03 pass.
// Requires: MySQL 8.4 on 127.0.0.1:33306 (root/pw, db t), PostgreSQL 17 on 127.0.0.1:35432 (postgres/pw).
declare(strict_types=1);
require __DIR__ . '/../../../../vendor/autoload.php';
use TypeDb\Connection;
use function TypeDb\{to_sql, from_sql};
function d(mixed $r): string { return json_encode(array_map(fn($row) => array_map(fn($v) => (new ReflectionClass($v))->getShortName() . '(' . var_export(from_sql($v), true) . ')', $row), $r)); }
function run(string $label, callable $f): void { try { echo $label, ' => ', d($f()), PHP_EOL; } catch (Throwable $e) { echo $label, ' => ', $e::class, ': ', strtok($e->getMessage(), "\n"), PHP_EOL; } }
$pg = fn() => new PDO('pgsql:host=127.0.0.1;port=35432;dbname=postgres', 'postgres', 'pw');
$my = fn() => new PDO('mysql:host=127.0.0.1;port=33306;dbname=t', 'root', 'pw');

// BUG 1: PostgreSQL booleans hydrate as SqlNull
run('1 pg boolean', fn() => (new Connection($pg()))->quickQuery('select true as t, false as f'));

// BUG 2: SqlFloat(INF) / NAN use SQLite-only encoding on PostgreSQL
run('2 pg INF', fn() => (new Connection($pg()))->quickQuery('select cast(? as double precision) as v', [to_sql(INF)]));
run('2 pg NAN', fn() => (new Connection($pg()))->quickQuery('select cast(? as double precision) as v', [to_sql(NAN)]));
run('2 pg raw INF read', fn() => (new Connection($pg()))->quickQuery("select 'Infinity'::float8 as v, 'NaN'::float8 as n"));

// BUG 3: PostgreSQL DML returns one empty row per affected row
run('3 pg insert', function () use ($pg) { $c = new Connection($pg()); $c->quickQuery('create temp table r (x int)'); return $c->quickQuery('insert into r values (?), (?)', [to_sql(1), to_sql(2)]); });
run('3 sqlite insert', function () { $c = new Connection(new PDO('sqlite::memory:')); $c->quickQuery('create table r (x int)'); return $c->quickQuery('insert into r values (?), (?)', [to_sql(1), to_sql(2)]); });

// BUG 4: ATTR_ORACLE_NULLS = NULL_TO_STRING turns NULL in numeric columns into 0 / 0.0
run('4 sqlite NULL_TO_STRING', function () { $p = new PDO('sqlite::memory:'); $p->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_TO_STRING); $c = new Connection($p);
  $c->quickQuery('create table n (i integer, f real)'); $c->quickQuery('insert into n values (null, null)'); return $c->quickQuery('select i, f from n'); });
run('4 mysql NULL_TO_STRING', function () use ($my) { $p = $my(); $p->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_TO_STRING); $c = new Connection($p);
  $c->quickQuery('create temporary table n (i int, f double)'); $c->quickQuery('insert into n values (null, null)'); return $c->quickQuery('select i, f from n'); });

// BUG 5: ATTR_STRINGIFY_FETCHES leaves numbers as SqlString on PostgreSQL and for MySQL TINY/SHORT/INT24
run('5 pg STRINGIFY', function () use ($pg) { $p = $pg(); $p->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true); return (new Connection($p))->quickQuery('select 1::int4 as i, 2::int8 as b, 1.5::float8 as f'); });
run('5 mysql STRINGIFY', function () use ($my) { $p = $my(); $p->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true); $c = new Connection($p);
  $c->quickQuery('create temporary table s (t tinyint, sm smallint, m mediumint, i int)'); $c->quickQuery('insert into s values (1, 2, 3, 4)'); return $c->quickQuery('select t, sm, m, i from s'); });
