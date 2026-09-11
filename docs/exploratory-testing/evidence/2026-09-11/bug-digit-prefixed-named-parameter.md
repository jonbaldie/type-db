# Bug: `sqlite_float_parameter_sql()` does not recognise SQLite named parameters starting with digits

## Impact

SQLite supports named parameters whose identifier begins with a digit (e.g. `:1a`, `:1`, `@1a`, `$1a`), which PDO prepares and binds without error.

`sqlite_float_parameter_sql()` enforces that the character immediately following `:`, `@`, or `$` must be an alphabetic character or underscore: `ctype_alpha($sql[$index + 1]) || $sql[$index + 1] === '_'`. As a result, valid parameters starting with digits are not recognized as named parameters and are omitted from the float rewrite (`CAST(... AS REAL)`).

Float values bound to such parameters are received by SQLite as text and returned by `quick_query()` as `SqlString` rather than `SqlFloat`.

## Starting conditions

- Repository `main` at `d45125e` (`chore(release): v0.9.5 (#26)`).
- PHP 8.5.9, SQLite 3.53.4, `pdo_sqlite`.
- Fresh `sqlite::memory:` connection; default PDO fetch settings.

## Replay

```php
$connection = new TypeDb\Connection(new PDO('sqlite::memory:'));

$result = TypeDb\quick_query(
    $connection,
    'select :1a as res',
    [':1a' => TypeDb\to_sql(1.5)],
)[0]['res'];
```

## Expected

```text
SqlFloat(1.5)
```

## Actual

```text
SqlString('1.5')
```

The float value was bound as text without the `CAST(:1a AS REAL)` wrapper because the parser skipped `:1a`. The minimal reproducer was run three consecutive times from fresh connections; all runs reproduced the defect deterministically.

Evidence harness: [`confirmed-bugs.php`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-11/confirmed-bugs.php), output: [`confirmed-bugs-output.log`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-11/confirmed-bugs-output.log).
