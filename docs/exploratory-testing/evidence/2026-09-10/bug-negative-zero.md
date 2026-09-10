# Bug: `SqlFloat(-0.0)` loses its sign bit through SQLite

## Impact

The README promises lossless/bit-identical float round trips through numeric-affinity SQLite columns. A valid `SqlFloat(-0.0)` is returned as positive zero, so sign-sensitive calculations and serialized float bits change silently.

## Starting conditions

- Repository `main` at `2cbe2f9` (`Fix SQLite numeric parameter storage`, 2026-09-10).
- PHP 8.4.1, SQLite 3.43.2, `pdo_sqlite`.
- Fresh `sqlite::memory:` connection; default PDO fetch settings.

## Replay

```php
$pdo = new PDO('sqlite::memory:');
$connection = new TypeDb\Connection($pdo);
TypeDb\quick_query($connection, 'create table t (value real)');

$input = -0.0;
TypeDb\quick_query(
    $connection,
    'insert into t (value) values (?)',
    [TypeDb\to_sql($input)],
);

$output = TypeDb\quick_query($connection, 'select value from t')[0]['value'];
var_dump(bin2hex(pack('d', $input)));
var_dump(bin2hex(pack('d', $output->value)));
```

## Expected

The output remains `SqlFloat(-0.0)` and has the same bits as the input: `0000000000000080` on this host.

## Actual

The returned object is `SqlFloat(0.0)` with bits `0000000000000000`. Ordinary numeric equality does not expose the defect because PHP considers both zeros equal; `fdiv(1.0, $output->value)` returns positive `INF`.

The minimal reproducer was run three times from a fresh connection; all three runs lost the sign bit.

Evidence harness: [`confirmed-bugs.php`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-10/confirmed-bugs.php), output: [`confirmed-bugs-output.log`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-10/confirmed-bugs-output.log).
