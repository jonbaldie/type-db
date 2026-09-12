# Bug: `sqlite_float_parameter_sql()` does not recognise SQLite named parameters containing non-ASCII characters

## Impact

SQLite supports named parameters containing any UTF-8 / non-ASCII character (e.g. `:café`, `:über`, `:π`, `:alpha_β`), which PDO prepares and binds without error. In SQLite's tokenizer (`tokenize.c`), any byte with a value >= 128 (`0x80`..`0xFF`) is considered an identifier character.

`sqlite_float_parameter_sql()` inspects named parameter tokens using `ctype_alnum($sql[$index]) || $sql[$index] === '_'`. In PHP, `ctype_alnum()` is ASCII- and locale-dependent:
1. On UTF-8 continuation bytes (`0x80`..`0xBF`), `ctype_alnum()` always returns `false`. Multi-byte characters in parameter names are sliced mid-token, truncating the parameter name.
2. In the `C` locale (common in CI containers and CLI environments without `LC_CTYPE` configured), even leading non-ASCII bytes (`0xC0`..`0xFF`) return `false`, causing the parser to ignore non-ASCII parameters completely or truncate at the first non-ASCII byte.

This defect causes three distinct failures:
- **Silent type degradation:** A float bound to a parameter containing non-ASCII characters (e.g. `:café` or `:π`) is not rewritten to `CAST(... AS REAL)`. It reaches SQLite as text and is returned by `quick_query()` as `SqlString` instead of `SqlFloat`.
- **Fatal SQL syntax corruption:** Under the `C` locale, if a query contains `:café` and `$sql_values` contains `:caf` as a float, the tokenizer matches `:caf` against the start of `:café` and rewrites it to `CAST(:caf AS REAL)é`, leaving the non-ASCII tail dangling outside the cast as invalid SQL syntax (`SQLSTATE[HY000]: General error: 1 near "as": syntax error`).
- **Pre-flight validation bypass:** Under the `C` locale, if a query uses an `@`- or `$`-prefixed parameter with non-ASCII characters (e.g. `@über`) and the caller supplies the unprefixed key `'über'`, the tokenizer fails to match the parameter name in `$named_parameters`. It bypasses the `InvalidArgumentException` check added in #35 and crashes in PDO with an unhandled `PDOException: SQLSTATE[HY000]: General error: 25 column index out of range`.

## Starting conditions

- Repository `main` at `7228588` (`Reject @ and $ prefixed SQLite named parameters (#35) (#37)`).
- PHP 8.5.9, SQLite 3.53.4, `pdo_sqlite`.
- Fresh `sqlite::memory:` connection; default PDO fetch settings.

## Replay

```php
$connection = new TypeDb\Connection(new PDO('sqlite::memory:'));

// 1. Float degradation on non-ASCII named parameter
$result = TypeDb\quick_query(
    $connection,
    'select :café as res',
    [':café' => TypeDb\to_sql(1.5)],
)[0]['res'];

// 2. Query corruption under C locale
setlocale(LC_ALL, 'C');
TypeDb\quick_query(
    $connection,
    'select :café as res',
    [':caf' => TypeDb\to_sql(1.5)],
);
```

## Expected

```text
1. SqlFloat(1.5)
2. Executes without syntax corruption
```

## Actual

```text
1. SqlString('1.5')
2. PDOException: SQLSTATE[HY000]: General error: 1 near "as": syntax error
   Rewritten SQL: select CAST(:caf AS REAL)é as res
```

The minimal reproducer was run three consecutive times from fresh connections; all runs reproduced the defect deterministically.

Evidence harness: [`confirmed-bugs.php`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-12/confirmed-bugs.php), output: [`confirmed-bugs-output.log`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-12/confirmed-bugs-output.log).
