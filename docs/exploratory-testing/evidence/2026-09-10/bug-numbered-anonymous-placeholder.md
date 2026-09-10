# Bug: a float after a numbered SQLite placeholder is returned as `SqlString`

## Impact

`quick_query()` supports SQLite numbered placeholders and promises that float parameters are represented as `SqlFloat`. Mixing a numbered marker with a later anonymous marker silently loses the float type: the value is returned as text instead of a float, and the same missing REAL cast can change SQLite comparison/storage behavior.

## Starting conditions

- Repository `main` at `2cbe2f9` (`Fix SQLite numeric parameter storage`, 2026-09-10).
- PHP 8.4.1, SQLite 3.43.2, `pdo_sqlite`.
- Fresh `sqlite::memory:` connection; default PDO fetch settings.

## Replay

```php
$connection = new TypeDb\Connection(new PDO('sqlite::memory:'));

$result = TypeDb\quick_query(
    $connection,
    'select ?2 as numbered, ? as anonymous',
    [TypeDb\to_sql(1), TypeDb\to_sql(2), TypeDb\to_sql(3.5)],
)[0];
```

SQLite assigns `?2` to the second value and the later anonymous `?` to the third value.

## Expected

```text
numbered:  SqlInteger(2)
anonymous: SqlFloat(3.5)
```

## Actual

```text
numbered:  SqlInteger(2)
anonymous: SqlString('3.5')
```

The value is correct but the type is wrong because the SQLite float-placeholder rewrite associates the anonymous marker with the first array value after seeing `?2`, so it does not add the required `CAST(? AS REAL)`. The minimal reproducer was run three times from fresh connections; all runs returned `SqlString('3.5')`.

Evidence harness: [`confirmed-bugs.php`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-10/confirmed-bugs.php), output: [`confirmed-bugs-output.log`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-10/confirmed-bugs-output.log).
