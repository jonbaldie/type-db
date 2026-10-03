<?php
$m = new PDO('mysql:host=127.0.0.1;port=33306;dbname=t', 'root', 'pw');
$m->exec('drop table if exists mm'); $m->exec('create table mm (ti tinyint, si smallint, mi mediumint, i int, f float, de decimal(5,2), y year)'); $m->exec('insert into mm values (1,2,3,4,1.5,1.25,2020)');
$s = $m->query('select * from mm'); for ($i = 0; $i < $s->columnCount(); $i++) { $x = $s->getColumnMeta($i); echo "mysql {$x['name']}: ", $x['native_type'] ?? '(none)', PHP_EOL; }
$p = new PDO('pgsql:host=127.0.0.1;port=35432;dbname=postgres', 'postgres', 'pw');
$s = $p->query('select 1::int2 a, 1::int4 b, 1::int8 c, 1.5::float4 d, 1.5::float8 e, 1.5::numeric f, true g, null::int4 h'); for ($i = 0; $i < $s->columnCount(); $i++) { $x = $s->getColumnMeta($i); echo "pgsql {$x['name']}: ", $x['native_type'] ?? '(none)', ' pdo_type=', $x['pdo_type'], PHP_EOL; }
$q = new PDO('sqlite::memory:'); $q->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_TO_STRING); $q->exec('create table z (n integer)'); $q->exec('insert into z values (null)');
$s = $q->query('select n from z'); $s->fetch(); print_r($s->getColumnMeta(0));
