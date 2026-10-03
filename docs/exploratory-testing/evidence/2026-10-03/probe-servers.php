<?php
declare(strict_types=1);
require __DIR__ . '/../../../../vendor/autoload.php';
use function TypeDb\{to_sql, from_sql};
function fmt(array $r): string { return json_encode(array_map(fn($row) => array_map(fn($v) => (new ReflectionClass($v))->getShortName() . '(' . var_export(from_sql($v), true) . ')', $row), $r)); }
function show(string $label, callable $f): void {
    try { echo $label, ': ', fmt($f()), PHP_EOL; }
    catch (Throwable $e) { echo $label, ': ', $e::class, ': ', $e->getMessage(), PHP_EOL; }
}
$dsns = [
  'mysql' => fn() => new PDO('mysql:host=127.0.0.1;port=33306;dbname=t', 'root', 'pw'),
  'mysql-native' => function () { $p = new PDO('mysql:host=127.0.0.1;port=33306;dbname=t', 'root', 'pw'); $p->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); return $p; },
  'pgsql' => fn() => new PDO('pgsql:host=127.0.0.1;port=35432;dbname=postgres', 'postgres', 'pw'),
];
foreach ($dsns as $name => $make) {
  echo "===== $name\n";
  $c = new TypeDb\Connection($make());
  $c->quickQuery('drop table if exists type_db_ft');
  // README quickstart (varchar without length is pg-only; use varchar(255))
  show('readme create', fn() => $c->quickQuery('create table if not exists type_db_ft ( id int not null, value varchar(255) not null )'));
  show('readme insert', fn() => $c->quickQuery('insert into type_db_ft (id, value) values (?, ?), (?, ?)', [to_sql(1), to_sql('bar'), to_sql(2), to_sql('baz')]));
  show('readme select', fn() => $c->quickQuery('select id, value from type_db_ft where id = ? or value = ?', [to_sql(1), to_sql('baz')]));
  show('readme scalar', fn() => $c->quickQuery("select 1 as id, 'baz' as value"));
  // typed table round trip
  $c->quickQuery('drop table if exists rt');
  $c->quickQuery($name === 'pgsql'
    ? 'create table rt (i bigint, f double precision, s text, n int, d numeric(10,2), b boolean, r real)'
    : 'create table rt (i bigint, f double, s text, n int, d decimal(10,2), b boolean, r float)');
  show('insert typed', fn() => $c->quickQuery('insert into rt (i, f, s, n, d, b, r) values (?, ?, ?, ?, ?, ?, ?)',
     [to_sql(PHP_INT_MAX), to_sql(0.1 + 0.2), to_sql('héllo'), to_sql(null), to_sql(12.5), to_sql(1), to_sql(1.5)]));
  show('select typed', fn() => $c->quickQuery('select i, f, s, n, d, b, r from rt'));
  show('float precision param echo', fn() => $c->quickQuery($name === 'pgsql' ? 'select cast(? as double precision) as v' : 'select ? + 0e0 as v', [to_sql(0.1 + 0.2)]));
  show('float param bare', fn() => $c->quickQuery('select ? as v', [to_sql(1.5)]));
  show('int param bare', fn() => $c->quickQuery('select ? as v', [to_sql(7)]));
  show('null param bare', fn() => $c->quickQuery('select ? as v', [to_sql(null)]));
  show('INF insert', fn() => $c->quickQuery('insert into rt (f) values (?)', [to_sql(INF)]));
  show('-0.0 insert+read', function () use ($c) { $c->quickQuery('delete from rt'); $c->quickQuery('insert into rt (f) values (?)', [to_sql(-0.0)]); $r = $c->quickQuery('select f from rt'); return $r; });
  show('float where match', function () use ($c) { $c->quickQuery('delete from rt'); $c->quickQuery('insert into rt (f) values (?)', [to_sql(0.1+0.2)]); return $c->quickQuery('select count(*) as c from rt where f = ?', [to_sql(0.1+0.2)]); });
  show('named params', fn() => $c->quickQuery('select :a as a, :b as b', [':a' => to_sql(3), 'b' => to_sql('x')]));
  show('dup cols', fn() => $c->quickQuery('select 1 as a, 2 as a'));
  show('expr unaliased', fn() => $c->quickQuery('select 1 + 1'));
  show('syntax error', fn() => $c->quickQuery('select * from'));
}
