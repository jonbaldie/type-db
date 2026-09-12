# Bug: `sqlite_float_parameter_sql()` does not recognise SQLite array-index (`(...)`) named parameter syntax

## Impact

SQLite explicitly supports array references like `:arr(key)` and `$arr(key)` in its parameter syntax (see SQLite `tokenize.c` and documentation on parameter syntax). PDO prepares and binds array-index parameters without error on SQLite.

`sqlite_float_parameter_sql()` tokenizes identifier characters using only `ctype_alnum($sql[$index]) || $sql[$index] === '_'`. When encountering `(`, it terminates the parameter token, splitting an identifier like `:arr(key)` into `:arr` followed by `(key)`.

This defect causes three distinct failures:
- **Silent float degradation:** Float values bound to array-index parameters (e.g. `':arr(key)' => to_sql(1.5)`) are not rewritten to `CAST(... AS REAL)` because the parser looks up `'arr'` instead of `'arr(key)'` in `$named_float_parameters`. SQLite receives the parameter value as text and `quick_query()` returns `SqlString` instead of `SqlFloat`.
- **Fatal SQL syntax corruption:** If a query contains `:arr(key)` and `$sql_values` contains a float for `:arr` (e.g. `':arr' => to_sql(1.5)`), the tokenizer matches `:arr` against the start of `:arr(key)` and rewrites it to `select CAST(:arr AS REAL)(key) as r`, leaving `(key)` trailing the cast as invalid SQL syntax (`PDOException: SQLSTATE[HY000]: General error: 1 near "(": syntax error`).
- **Pre-flight validation bypass:** If a query uses an `@`- or `$`-prefixed array parameter (e.g. `@arr(key)`) and the caller supplies the unprefixed key `'arr(key)'`, the tokenizer truncates the parameter to `@arr`. It fails to match `'arr(key)'` in `$named_parameters`, bypassing the `InvalidArgumentException` check added in #35 and crashing in PDO with an unhandled `PDOException: SQLSTATE[HY000]: General error: 25 column index out of range`.

## Starting conditions

- Repository `main` at `8d8097a` (`docs: exploratory testing report and bug evidence for 2026-09-12 (#38, #39)`).
- PHP 8.5.9, SQLite 3.53.4, `pdo_sqlite`.
- Fresh `sqlite::memory:` connection; default PDO fetch settings.

## Replay

```php
$connection = new TypeDb\Connection(new PDO('sqlite::memory:'));

// 1. Float degradation on array-index parameter
$result = TypeDb\quick_query(
    $connection,
    'select :arr(key) as r',
    [':arr(key)' => TypeDb\to_sql(1.5)],
)[0]['r'];

// 2. Query corruption when :arr is a float
TypeDb\quick_query(
    $connection,
    'select :arr(key) as r',
    [':arr' => TypeDb\to_sql(1.5)],
);

// 3. Pre-flight validation bypass on @arr(key)
TypeDb\quick_query(
    $connection,
    'select @arr(key) as r',
    ['arr(key)' => TypeDb\to_sql(1.5)],
);
```

## Expected

```text
1. SqlFloat(1.5)
2. Executes without syntax corruption
3. InvalidArgumentException: SQLite named parameter "@arr(key)" is not supported; use a ":"-prefixed name instead.
```

## Actual

```text
1. SqlString('1.5')
2. PDOException: SQLSTATE[HY000]: General error: 1 near "(": syntax error
   Rewritten SQL: select CAST(:arr AS REAL)(key) as r
3. PDOException: SQLSTATE[HY000]: General error: 25 column index out of range
```

The minimal reproducer was run three consecutive times from fresh connections; all runs reproduced the defect deterministically.

Evidence harness: [`confirmed-bugs.php`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-12-pass-2/confirmed-bugs.php), output: [`confirmed-bugs-output.log`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-12-pass-2/confirmed-bugs-output.log).
