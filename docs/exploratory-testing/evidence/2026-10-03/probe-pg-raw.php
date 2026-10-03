<?php
declare(strict_types=1);
require __DIR__ . '/../../../../vendor/autoload.php';
use function TypeDb\{to_sql, from_sql};
function fmt(array $r): string { return json_encode(array_map(fn($row) => array_map(fn($v) => (new ReflectionClass($v))->getShortName() . '(' . var_export(from_sql($v), true) . ')', $row), $r)); }
function show(string $label, callable $f): void {
    try { $r = $f(); echo $label, ': ', is_array($r) && isset($r[0]) && is_array($r[0]) && $r[0] && reset($r[0]) instanceof TypeDb\SqlValue\SqlValue ? fmt($r) : var_export($r, true), PHP_EOL; }
    catch (Throwable $e) { echo $label, ': ', $e::class, ': ', strtok($e->getMessage(), "\n"), PHP_EOL; }
}
for ($run = 1; $run <= 3; $run++) {
echo "--- run $run (fresh pgsql connection, fresh table)\n";
$pdo = new PDO('pgsql:host=127.0.0.1;port=35432;dbname=postgres', 'postgres', 'pw');
$c = new TypeDb\Connection($pdo);
$pdo->exec('drop table if exists p; create table p (id int, b boolean, f double precision)');

// 1. DML result shape
show('typedb insert 2 rows', fn() => $c->quickQuery('insert into p (id) values (?), (?)', [to_sql(1), to_sql(2)]));
show('typedb update 2 rows', fn() => $c->quickQuery('update p set id = id + ?', [to_sql(10)]));
show('typedb delete 2 rows', fn() => $c->quickQuery('delete from p'));
show('typedb create table', fn() => $c->quickQuery('create table if not exists p2 (x int)'));
show('raw insert: columnCount / fetch', function () use ($pdo) { $s = $pdo->prepare('insert into p (id) values (1), (2)'); $s->execute(); return [$s->columnCount(), $s->fetch(PDO::FETCH_ASSOC)]; });
show('raw insert fetchAll', function () use ($pdo) { $s = $pdo->prepare('insert into p (id) values (1), (2)'); $s->execute(); return $s->fetchAll(PDO::FETCH_ASSOC); });
$pdo->exec('delete from p');

// 2. boolean
$pdo->exec("insert into p (id, b) values (1, true), (2, false), (3, null)");
show('typedb select boolean', fn() => $c->quickQuery('select id, b from p order by id'));
show('typedb select true literal', fn() => $c->quickQuery('select true as t, false as f, 1 = 1 as cmp'));
show('raw boolean fetch', function () use ($pdo) { return $pdo->query('select b from p order by id')->fetchAll(PDO::FETCH_COLUMN); });
$pdo->exec('delete from p');

// 3. INF / NAN
show('typedb INF', fn() => $c->quickQuery('insert into p (f) values (?)', [to_sql(INF)]));
show('typedb -INF', fn() => $c->quickQuery('insert into p (f) values (?)', [to_sql(-INF)]));
show('typedb NAN', fn() => $c->quickQuery('insert into p (f) values (?)', [to_sql(NAN)]));
show('raw PDO bind INF', function () use ($pdo) { $s = $pdo->prepare('insert into p (f) values (?)'); $s->bindValue(1, INF); $s->execute(); return 'ok'; });
show('raw PDO bind NAN', function () use ($pdo) { $s = $pdo->prepare('insert into p (f) values (?)'); $s->bindValue(1, NAN); $s->execute(); return 'ok'; });
show('typedb read stored inf/nan', fn() => $c->quickQuery('select f from p'));
}
