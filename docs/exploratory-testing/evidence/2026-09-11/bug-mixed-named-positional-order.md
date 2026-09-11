# Bug: `quick_query()` corrupts parameters when named parameters precede positional parameters in `$sql_values`

## Impact

`sqlite_float_parameter_sql()` re-indexes `$sql_values` with `array_values($sql_values)` to match positional `?` placeholders sequentially. When an associative array has named keys preceding integer/positional keys, positional placeholder indexing is misaligned against the actual bound parameters.

- If the earlier named parameter is a float, the positional `?` placeholder is erroneously rewritten to `CAST(? AS REAL)`, corrupting non-numeric string values to `SqlFloat(0.0)`.
- If the earlier named parameter is not a float, an actual float parameter destined for `?` is not rewritten to `CAST(? AS REAL)`, causing SQLite to store or return it as `SqlString`.

## Starting conditions

- Repository `main` at `d45125e` (`chore(release): v0.9.5 (#26)`).
- PHP 8.5.9, SQLite 3.53.4, `pdo_sqlite`.
- Fresh `sqlite::memory:` connection; default PDO fetch settings.

## Replay

```php
$connection = new TypeDb\Connection(new PDO('sqlite::memory:'));

// Case 1: Named float precedes positional string
$result1 = TypeDb\quick_query(
    $connection,
    'select ? as pos, :name as named',
    ['name' => TypeDb\to_sql(1.5), 0 => TypeDb\to_sql('test')],
)[0];

// Case 2: Named string precedes positional float
$result2 = TypeDb\quick_query(
    $connection,
    'select ? as pos, :name as named',
    ['name' => TypeDb\to_sql('test'), 0 => TypeDb\to_sql(1.5)],
)[0];
```

## Expected

Case 1:
```text
pos:   SqlString('test')
named: SqlFloat(1.5)
```

Case 2:
```text
pos:   SqlFloat(1.5)
named: SqlString('test')
```

## Actual

Case 1:
```text
pos:   SqlFloat(0.0)
named: SqlFloat(1.5)
```
The string parameter `'test'` is corrupted to `SqlFloat(0.0)`.

Case 2:
```text
pos:   SqlString('1.5')
named: SqlString('test')
```
The float parameter `1.5` loses its float type and returns as `SqlString('1.5')`.

The minimal reproducer was executed three consecutive times from fresh connections; all runs reproduced the defect deterministically.

Evidence harness: [`confirmed-bugs.php`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-11/confirmed-bugs.php), output: [`confirmed-bugs-output.log`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-11/confirmed-bugs-output.log).
