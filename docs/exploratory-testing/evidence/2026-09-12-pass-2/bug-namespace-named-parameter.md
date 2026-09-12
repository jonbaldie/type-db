# Bug: `sqlite_float_parameter_sql()` does not recognise SQLite namespace (`::`) named parameter syntax

## Impact

SQLite supports parameter identifiers using `::` for namespaces (e.g. `:ns::param`, `:pkg::sub::var`), which PDO prepares and binds without error. In SQLite's tokenizer (`tokenize.c`), `::` within a variable name is explicitly handled as part of the parameter token.

`sqlite_float_parameter_sql()` inspects named parameter tokens using `ctype_alnum($sql[$index]) || $sql[$index] === '_'`. When encountering the first `:`, the tokenizer stops scanning the identifier, splitting an identifier like `:ns::param` into `:ns`, followed by a detached `:`, followed by `:param`.

This defect causes three distinct failures:
- **Silent float degradation:** Float values bound to namespace parameters (e.g. `':ns::param' => to_sql(1.5)`) are not rewritten to `CAST(... AS REAL)` because the parser looks up `'ns'` instead of `'ns::param'` in `$named_float_parameters`. SQLite receives the parameter value as text and `quick_query()` returns `SqlString` instead of `SqlFloat`.
- **Fatal SQL syntax corruption:** If a query contains `:ns::param` and `$sql_values` contains a float for `:param` (e.g. `':param' => to_sql(1.5)`), the tokenizer matches the second half `:param` and rewrites it into `:ns:CAST(:param AS REAL)`, emitting invalid SQL that crashes with a SQLite syntax error (`PDOException: SQLSTATE[HY000]: General error: 1 unrecognized token: ":CAST(:param"`). Likewise, if `$sql_values` contains a float for `:ns`, it rewrites the first half into `select CAST(:ns AS REAL)::param`, crashing with `PDOException: SQLSTATE[HY000]: General error: 1 unrecognized token: ":"`.
- **Pre-flight validation bypass:** If a query uses an `@`- or `$`-prefixed namespace parameter (e.g. `@ns::param`) and the caller supplies the unprefixed key `'ns::param'`, the tokenizer truncates the parameter to `@ns`. It fails to match `'ns::param'` in `$named_parameters`, bypassing the `InvalidArgumentException` check added in #35 and crashing in PDO with an unhandled `PDOException: SQLSTATE[HY000]: General error: 25 column index out of range`.

## Starting conditions

- Repository `main` at `8d8097a` (`docs: exploratory testing report and bug evidence for 2026-09-12 (#38, #39)`).
- PHP 8.5.9, SQLite 3.53.4, `pdo_sqlite`.
- Fresh `sqlite::memory:` connection; default PDO fetch settings.

## Replay

```php
$connection = new TypeDb\Connection(new PDO('sqlite::memory:'));

// 1. Float degradation on namespace parameter
$result = TypeDb\quick_query(
    $connection,
    'select :ns::param as r',
    [':ns::param' => TypeDb\to_sql(1.5)],
)[0]['r'];

// 2. Query corruption when :param is a float
TypeDb\quick_query(
    $connection,
    'select :ns::param as r',
    [':param' => TypeDb\to_sql(1.5)],
);

// 3. Pre-flight validation bypass on @ns::param
TypeDb\quick_query(
    $connection,
    'select @ns::param as r',
    ['ns::param' => TypeDb\to_sql(1.5)],
);
```

## Expected

```text
1. SqlFloat(1.5)
2. Executes without syntax corruption
3. InvalidArgumentException: SQLite named parameter "@ns::param" is not supported; use a ":"-prefixed name instead.
```

## Actual

```text
1. SqlString('1.5')
2. PDOException: SQLSTATE[HY000]: General error: 1 unrecognized token: ":CAST(:param"
   Rewritten SQL: select :ns:CAST(:param AS REAL) as r
3. PDOException: SQLSTATE[HY000]: General error: 25 column index out of range
```

The minimal reproducer was run three consecutive times from fresh connections; all runs reproduced the defect deterministically.

Evidence harness: [`confirmed-bugs.php`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-12-pass-2/confirmed-bugs.php), output: [`confirmed-bugs-output.log`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-12-pass-2/confirmed-bugs-output.log).
