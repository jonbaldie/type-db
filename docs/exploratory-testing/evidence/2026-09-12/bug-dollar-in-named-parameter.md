# Bug: `sqlite_float_parameter_sql()` does not recognise `$` within SQLite named parameter identifiers

## Impact

SQLite supports parameter identifiers containing `$` after the leading `:` prefix (e.g. `:a$b`, `:item$price`, `:foo$1`), which PDO prepares and binds without error. In SQLite's tokenizer (`tokenize.c`), `$` is explicitly treated as a valid identifier character (`IdChar`).

`sqlite_float_parameter_sql()` tokenizes identifier characters using only `ctype_alnum($sql[$index]) || $sql[$index] === '_'`. As a result, the tokenizer terminates the parameter at the `$` character, splitting an identifier like `:a$b` into a truncated parameter `:a` followed by `$b`.

This defect causes two failures:
- **Silent float degradation:** Float values bound to parameters containing `$` (e.g. `':a$b' => to_sql(1.5)`) are not rewritten to `CAST(... AS REAL)` because the parser looks up `a` instead of `a$b` in `$named_float_parameters`. SQLite receives the value as text and `quick_query()` returns `SqlString` instead of `SqlFloat`.
- **False-positive exception:** If a query contains `:a$b` and `$sql_values` contains a value for key `'b'` (e.g. `'b' => to_sql('test')`), the parser treats the trailing `$b` as a `$`-prefixed parameter and matches `'b'` in `$named_parameters`. It throws a spurious `InvalidArgumentException: SQLite named parameter "$b" is not supported; use a ":"-prefixed name instead.` even though the SQL query did not use a `$`-prefixed parameter (it used the valid colon-prefixed parameter `:a$b`).

## Starting conditions

- Repository `main` at `7228588` (`Reject @ and $ prefixed SQLite named parameters (#35) (#37)`).
- PHP 8.5.9, SQLite 3.53.4, `pdo_sqlite`.
- Fresh `sqlite::memory:` connection; default PDO fetch settings.

## Replay

```php
$connection = new TypeDb\Connection(new PDO('sqlite::memory:'));

// 1. Float degradation on parameter containing $
$result = TypeDb\quick_query(
    $connection,
    'select :a$b as res',
    [':a$b' => TypeDb\to_sql(1.5)],
)[0]['res'];

// 2. False positive exception when another parameter has key 'b'
TypeDb\quick_query(
    $connection,
    'select :a$b as res',
    ['b' => TypeDb\to_sql('test')],
);
```

## Expected

```text
1. SqlFloat(1.5)
2. Executes without throwing (or binds to :a$b if provided)
```

## Actual

```text
1. SqlString('1.5')
2. InvalidArgumentException: SQLite named parameter "$b" is not supported; use a ":"-prefixed name instead.
```

The minimal reproducer was run three consecutive times from fresh connections; all runs reproduced the defect deterministically.

Evidence harness: [`confirmed-bugs.php`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-12/confirmed-bugs.php), output: [`confirmed-bugs-output.log`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-12/confirmed-bugs-output.log).
