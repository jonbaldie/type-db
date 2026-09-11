# Bug: `quick_query()` coerces subsequent string cells to `SqlInteger(0)` or `SqlFloat(0.0)` when an earlier row contains a number

## Impact

`quick_query()` derives column metadata kinds once using `statement_column_kinds()` before fetching rows. In SQLite, for queries without static column affinity (such as `UNION` queries, calculations, expressions, or affinity-neutral table columns), `PDOStatement::getColumnMeta()` reports the native type of the first row.

Because `quick_query()` passes this first-row kind into `row_sql_values()` for all rows, and `cell_sql_value()` unconditionally casts strings with `(int)` or `(float)` whenever `$kind` is `integer` or `float`, non-numeric strings in subsequent rows are silently corrupted into `SqlInteger(0)` or `SqlFloat(0.0)`.

This causes silent data corruption and makes result types non-deterministic based purely on row ordering.

## Starting conditions

- Repository `main` at `d45125e` (`chore(release): v0.9.5 (#26)`).
- PHP 8.5.9, SQLite 3.53.4, `pdo_sqlite`.
- Fresh `sqlite::memory:` connection; default PDO fetch settings.

## Replay

```php
$connection = new TypeDb\Connection(new PDO('sqlite::memory:'));

// Union reproducer
$result = TypeDb\quick_query(
    $connection,
    "select 1 as val union all select 'hello'",
);
```

Or with an untyped table column:

```php
$connection = new TypeDb\Connection(new PDO('sqlite::memory:'));
TypeDb\quick_query($connection, 'create table items (val)');
TypeDb\quick_query(
    $connection,
    'insert into items (val) values (?), (?)',
    [TypeDb\to_sql(42), TypeDb\to_sql('hello')],
);

$forward = TypeDb\quick_query($connection, 'select val from items order by rowid asc');
$backward = TypeDb\quick_query($connection, 'select val from items order by rowid desc');
```

## Expected

In the union query:
```text
row 0: SqlInteger(1)
row 1: SqlString('hello')
```

In the table query:
- `$forward`: `[SqlInteger(42), SqlString('hello')]`
- `$backward`: `[SqlString('hello'), SqlInteger(42)]`

## Actual

In the union query:
```text
row 0: SqlInteger(1)
row 1: SqlInteger(0)
```

In the table query:
- `$forward`: `[SqlInteger(42), SqlInteger(0)]` (the string `'hello'` is converted to `0`)
- `$backward`: `[SqlString('hello'), SqlInteger(42)]` (both types preserved)

The minimal reproducer was executed three consecutive times from fresh connections; all runs reproduced the defect deterministically.

Evidence harness: [`confirmed-bugs.php`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-11/confirmed-bugs.php), output: [`confirmed-bugs-output.log`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-11/confirmed-bugs-output.log).
