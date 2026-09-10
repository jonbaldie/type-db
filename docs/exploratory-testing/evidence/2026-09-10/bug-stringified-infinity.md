# Bug: stringified SQLite infinities become `SqlFloat(0.0)`

## Impact

When callers enable the supported PDO `ATTR_STRINGIFY_FETCHES` mode, `quick_query()` silently changes both positive and negative infinity into positive zero. This violates the documented infinity round-trip behavior and can corrupt calculations without an exception.

## Starting conditions

- Repository `main` at `2cbe2f9` (`Fix SQLite numeric parameter storage`, 2026-09-10).
- PHP 8.4.1, SQLite 3.43.2, `pdo_sqlite`.
- Fresh `sqlite::memory:` connection with `PDO::ATTR_STRINGIFY_FETCHES = true`.

## Replay

```php
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
$connection = new TypeDb\Connection($pdo);
TypeDb\quick_query($connection, 'create table t (value real)');
TypeDb\quick_query(
    $connection,
    'insert into t (value) values (?), (?)',
    [TypeDb\to_sql(INF), TypeDb\to_sql(-INF)],
);

$raw = $pdo->query('select value from t order by rowid')
    ->fetchAll(PDO::FETCH_COLUMN);
$mapped = TypeDb\quick_query($connection, 'select value from t order by rowid');
```

## Expected

`$raw` is `['INF', '-INF']`, and `$mapped` contains `SqlFloat(INF)` followed by `SqlFloat(-INF)`.

## Actual

`$raw` is `['INF', '-INF']`, but `$mapped` contains `SqlFloat(0.0)` for both rows. The reproducer was run three times from fresh connections; all runs produced the same result.

Evidence harness: [`confirmed-bugs.php`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-10/confirmed-bugs.php), output: [`confirmed-bugs-output.log`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-10/confirmed-bugs-output.log).
