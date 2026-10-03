<?php
declare(strict_types=1);
require __DIR__ . '/../../../../vendor/autoload.php';
use function TypeDb\{to_sql, from_sql};
function fmt(array $r): string { return json_encode(array_map(fn($row) => array_map(fn($v) => (new ReflectionClass($v))->getShortName() . '(' . var_export(from_sql($v), true) . ')', $row), $r)); }
function show(string $label, callable $f): void { try { echo $label, ': ', fmt($f()), PHP_EOL; } catch (Throwable $e) { echo $label, ': ', $e::class, ': ', $e->getMessage(), PHP_EOL; } }
$c = new TypeDb\Connection(new PDO('sqlite::memory:'));
$f = to_sql(1.5);
show('?1 reused twice', fn() => $c->quickQuery('select ?1 as a, ?1 as b', [$f]));
show('?/2 adjacency', fn() => $c->quickQuery('select ?/2 as a', [$f]));
show('?*? adjacency', fn() => $c->quickQuery('select ?*? as a', [$f, $f]));
show('-- after ?', fn() => $c->quickQuery("select ?--c\n as a", [$f]));
show('blob literal then ?', fn() => $c->quickQuery("select x'41' as b, ? as a", [$f]));
show('float in IN list', fn() => $c->quickQuery('select 1.5 in (?, ?) as a', [$f, to_sql(2.0)]));
show('? COLLATE', fn() => $c->quickQuery('select typeof(? collate nocase) as t', [$f]));
show('unaliased ?+?', fn() => $c->quickQuery('select ? + ?', [$f, to_sql(2.5)]));
show('unaliased (?)', fn() => $c->quickQuery('select (?)', [$f]));
show('subquery unaliased', fn() => $c->quickQuery('select (select ?)', [$f]));
show('alias with marker text', fn() => $c->quickQuery('select ? as "CAST(? AS REAL) /* type-db:float-rewrite-1 */"', [$f]));
show('upsert excluded', function () use ($c, $f) { $c->quickQuery('create table u (k int primary key, v real)'); $c->quickQuery('insert into u values (1, ?) on conflict(k) do update set v = excluded.v + ?', [$f, $f]); $c->quickQuery('insert into u values (1, ?) on conflict(k) do update set v = excluded.v + ?', [$f, $f]); return $c->quickQuery('select v, typeof(v) as t from u'); });
show('returning', fn() => $c->quickQuery('insert into u values (2, ?) returning v, ?', [$f, $f]));
show('multi-statement', function () use ($c) { $c->quickQuery('create table ms (x int)'); $c->quickQuery('insert into ms values (1); insert into ms values (2)'); return $c->quickQuery('select count(*) as n from ms'); });
