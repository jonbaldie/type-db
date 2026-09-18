# Bug: `quick_query()` leaks float parameter rewrite into result column names under `PDO::ATTR_CASE` (`CASE_LOWER` / `CASE_UPPER`)

## Impact

When a `SqlFloat` parameter is bound on SQLite, `quick_query()` rewrites the parameter placeholder in the query into `CAST(<param> AS REAL) /* type-db:float-rewrite-<N> */` to preserve `REAL` storage class. To prevent this internal expression from leaking into result keys for unaliased select expressions, `sqlite_result_column_name()` uses `str_replace()` to restore the original parameter placeholder in column names reported by PDO.

However, `str_replace()` performs strictly case-sensitive matching against the exact uppercase/lowercase template generated during query rewriting (`CAST(... AS REAL) /* type-db:float-rewrite-... */`). When a caller's `PDO` connection has `PDO::ATTR_CASE` configured as `PDO::CASE_LOWER` or `PDO::CASE_UPPER`, PDO normalizes all column names to lowercase or uppercase before `quick_query()` inspects them.

- Under `PDO::CASE_LOWER`, PDO reports the column name as `cast(<param> as real) /* type-db:float-rewrite-<N> */`. `str_replace()` fails to match because `CAST`, `AS`, and `REAL` are in uppercase in the rewrite template.
- Under `PDO::CASE_UPPER`, PDO reports the column name as `CAST(<param> AS REAL) /* TYPE-DB:FLOAT-REWRITE-<N> */`. `str_replace()` fails to match because the internal comment marker `/* type-db:float-rewrite-... */` was uppercased by PDO.

This defect causes three significant failures:

1. **Internal rewrite leaks into result row keys:** For unaliased select expressions (e.g. `select ?` or `select :v`), `quick_query()` returns raw internal SQL rewrite expressions containing comment markers (e.g. `cast(? as real) /* type-db:float-rewrite-1 */`) instead of the logical column keys (`?` or `:v`).
2. **Type-dependent row shape discrepancy:** Under `PDO::CASE_LOWER` or `PDO::CASE_UPPER`, the row key for the exact same unaliased query differs depending on the runtime type of the bound value: `select ?` bound to `SqlInteger(1)` yields row key `'?'`, while bound to `SqlFloat(1.5)` it yields `'cast(? as real) /* type-db:float-rewrite-1 */'`.
3. **Bypass of duplicate column validation:** In `statement_duplicate_column_names()`, because the column names fail to restore, duplicate unaliased float columns (such as `select ?, ?` with two floats, or mixed with an integer) are assigned distinct internal names with differing rewrite numbers (`rewrite-1` vs `rewrite-2`). As a result, duplicate column validation fails to detect the collision, does not throw the required `RuntimeException`, and returns both columns in the result row.

## Starting conditions

- Repository `main` at `1a3b455` (`Fix SQLite float result column names (#47)`).
- PHP 8.5.9, SQLite 3.53.4, `pdo_sqlite`.
- Fresh `sqlite::memory:` connection with `PDO::ATTR_CASE` set to `PDO::CASE_LOWER` or `PDO::CASE_UPPER`.

## Replay

```php
// 1. Result key leakage under PDO::CASE_LOWER
$pdoLower = new PDO('sqlite::memory:');
$pdoLower->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
$connLower = new TypeDb\Connection($pdoLower);

$resPos = TypeDb\quick_query($connLower, 'select ?', [TypeDb\to_sql(1.5)]);
// array_key_first($resPos[0]) => 'cast(? as real) /* type-db:float-rewrite-1 */'

$resNamed = TypeDb\quick_query($connLower, 'select :v', [':v' => TypeDb\to_sql(1.5)]);
// array_key_first($resNamed[0]) => 'cast(:v as real) /* type-db:float-rewrite-1 */'

// 2. Result key leakage under PDO::CASE_UPPER
$pdoUpper = new PDO('sqlite::memory:');
$pdoUpper->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
$connUpper = new TypeDb\Connection($pdoUpper);

$resUpperPos = TypeDb\quick_query($connUpper, 'select ?', [TypeDb\to_sql(1.5)]);
// array_key_first($resUpperPos[0]) => 'CAST(? AS REAL) /* TYPE-DB:FLOAT-REWRITE-1 */'

// 3. Duplicate column validation bypassed
TypeDb\quick_query($connLower, 'select ?, ?', [TypeDb\to_sql(1.5), TypeDb\to_sql(2.5)]);
// Does not throw; returns keys:
// ['cast(? as real) /* type-db:float-rewrite-1 */', 'cast(? as real) /* type-db:float-rewrite-2 */']
```

## Expected

```text
1. array_key_first($resPos[0]) === '?'
   array_key_first($resNamed[0]) === ':v'
2. array_key_first($resUpperPos[0]) === '?'
3. Throws RuntimeException: Failed to interpret query [HY000/unknown]: Duplicate column names in result set: ?. SQL: select ?, ?
```

## Actual

```text
1. array_key_first($resPos[0]) === 'cast(? as real) /* type-db:float-rewrite-1 */'
   array_key_first($resNamed[0]) === 'cast(:v as real) /* type-db:float-rewrite-1 */'
2. array_key_first($resUpperPos[0]) === 'CAST(? AS REAL) /* TYPE-DB:FLOAT-REWRITE-1 */'
3. Did not throw; returns row with keys:
   ['cast(? as real) /* type-db:float-rewrite-1 */', 'cast(? as real) /* type-db:float-rewrite-2 */']
```

The minimal reproducer was executed across three consecutive clean runs; all runs reproduced the defect deterministically.

Evidence harness: [`confirmed-bugs.php`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-18/confirmed-bugs.php), output: [`confirmed-bugs-output.log`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-18/confirmed-bugs-output.log).
