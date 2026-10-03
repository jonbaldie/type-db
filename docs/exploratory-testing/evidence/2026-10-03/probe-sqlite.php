<?php
declare(strict_types=1);
require __DIR__ . '/../../../../vendor/autoload.php';
use function TypeDb\{to_sql, quick_query};
function show(string $label, callable $f): void {
    try { $r = $f(); echo $label, ': ', json_encode(array_map(fn($row) => array_map(fn($v) => (new ReflectionClass($v))->getShortName() . '(' . var_export(TypeDb\from_sql($v), true) . ')', $row), $r)), PHP_EOL; }
    catch (Throwable $e) { echo $label, ': ', $e::class, ': ', $e->getMessage(), PHP_EOL; }
}
function conn(array $attrs = []): TypeDb\Connection { $p = new PDO('sqlite::memory:'); foreach ($attrs as $k => $v) $p->setAttribute($k, $v); return new TypeDb\Connection($p); }

// A. case-colliding aliases
show('A1 default    select 1 as A, \'x\' as a', fn() => conn()->quickQuery("select 1 as A, 'x' as a"));
show('A2 CASE_LOWER select 1 as A, \'x\' as a', fn() => conn([PDO::ATTR_CASE => PDO::CASE_LOWER])->quickQuery("select 1 as A, 'x' as a"));
show('A3 CASE_UPPER select \'x\' as a, 1 as A', fn() => conn([PDO::ATTR_CASE => PDO::CASE_UPPER])->quickQuery("select 'x' as a, 1 as A"));
show('A4 CASE_LOWER select 1 as A, 2 as a (int/int)', fn() => conn([PDO::ATTR_CASE => PDO::CASE_LOWER])->quickQuery("select 1 as A, 2 as a"));
show('A5 CASE_LOWER join t.ID / u.id', function () { $c = conn([PDO::ATTR_CASE => PDO::CASE_LOWER]);
    $c->quickQuery('create table t (ID integer, name text)'); $c->quickQuery('create table u (id integer, label text)');
    $c->quickQuery("insert into t values (1, 'n')"); $c->quickQuery("insert into u values (1, 'lbl')");
    return $c->quickQuery('select t.ID, t.name, u.id, u.label from t join u on t.ID = u.id'); });
show('A6 default   join t.ID / u.id', function () { $c = conn();
    $c->quickQuery('create table t (ID integer, name text)'); $c->quickQuery('create table u (id integer, label text)');
    $c->quickQuery("insert into t values (1, 'n')"); $c->quickQuery("insert into u values (1, 'lbl')");
    return $c->quickQuery('select t.ID, t.name, u.id, u.label from t join u on t.ID = u.id'); });
// misalignment: collapsed key shifts index for later string column with int decl
show('A7 CASE_LOWER select 1 as A, 2 as a, \'text\' as b', fn() => conn([PDO::ATTR_CASE => PDO::CASE_LOWER])->quickQuery("select 1 as A, 2 as a, 'text' as b"));
show('A8 CASE_LOWER typed cols', function () { $c = conn([PDO::ATTR_CASE => PDO::CASE_LOWER]);
    $c->quickQuery('create table v (n integer, N2 text)');
    $c->quickQuery("insert into v values (5, 'hello')");
    return $c->quickQuery('select n as X, n as x, N2 from v'); });

// B. ORACLE_NULLS
show('B1 NULL_TO_STRING null in INTEGER col', function () { $c = conn([PDO::ATTR_ORACLE_NULLS => PDO::NULL_TO_STRING]);
    $c->quickQuery('create table w (n integer, r real, s text)'); $c->quickQuery('insert into w values (null, null, null)');
    return $c->quickQuery('select n, r, s from w'); });
show('B2 NULL_TO_STRING select null', fn() => conn([PDO::ATTR_ORACLE_NULLS => PDO::NULL_TO_STRING])->quickQuery('select null as n'));
show('B3 NULL_EMPTY_STRING select \'\'', fn() => conn([PDO::ATTR_ORACLE_NULLS => PDO::NULL_EMPTY_STRING])->quickQuery("select '' as s"));
