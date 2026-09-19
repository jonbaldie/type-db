# Bug: `sqlite_result_column_name()` does not respect `PDO::ATTR_CASE` normalization when restoring unaliased named float parameter column names

When unaliased SQL queries or expressions containing named parameters are executed with `SqlFloat` values on SQLite connections using `PDO::ATTR_CASE` (`PDO::CASE_LOWER` or `PDO::CASE_UPPER`), `sqlite_result_column_name()` restores the raw original casing from the SQL query string rather than applying the connection's case normalization.

## User impact

1. **Violation of `PDO::ATTR_CASE` contract**:
   - Under `PDO::ATTR_CASE => PDO::CASE_LOWER`, unaliased queries containing uppercase or mixed-case named float parameters (e.g. `select :PARAM`) return uppercase keys (e.g. `':PARAM'`) instead of lowercased keys (`':param'`).
   - Under `PDO::ATTR_CASE => PDO::CASE_UPPER`, unaliased queries containing lowercase or mixed-case named float parameters (e.g. `select :param`) return lowercase keys (e.g. `':param'`) instead of uppercased keys (`':PARAM'`).

2. **Type-dependent row shape divergence**:
   The resulting row key for the exact same unaliased query differs depending on the runtime type of the bound parameter:
   - Under `PDO::CASE_LOWER`, `select :PARAM` bound to `SqlInteger(1)` yields `':param'`, whereas bound to `SqlFloat(1.5)` it yields `':PARAM'`.
   - Under `PDO::CASE_UPPER`, `select :param` bound to `SqlInteger(1)` yields `':PARAM'`, whereas bound to `SqlFloat(1.5)` it yields `':param'`.

3. **Bypass of duplicate column validation**:
   When a query selects two unaliased named parameters that differ only by case (e.g. `select :param, :PARAM`) and one is bound to `SqlInteger` while the other is bound to `SqlFloat`:
   - Under `PDO::CASE_LOWER`, `select :param, :PARAM` with integer and float parameters does not throw `RuntimeException`; it returns both `':param'` and `':PARAM'` in the result row. (When both are integers, `quick_query()` throws the expected `RuntimeException: Failed to interpret query [HY000/unknown]: Duplicate column names in result set: :param`).
   - Under `PDO::CASE_UPPER`, `select :param, :PARAM` with float and integer parameters does not throw `RuntimeException`; it returns both `':param'` and `':PARAM'` in the result row. (When both are integers, `quick_query()` throws the expected `RuntimeException: Failed to interpret query [HY000/unknown]: Duplicate column names in result set: :PARAM`).

## Starting conditions

- Repository `main` at `32d1ca7` (or after `7ceeabb`).
- PHP 8.5.9, SQLite 3.53.4, `pdo_sqlite`.
- SQLite connection with `PDO::ATTR_CASE` configured as `PDO::CASE_LOWER` or `PDO::CASE_UPPER`.

## Replay

```php
// 1. Violation of CASE_LOWER normalization and type divergence
$pdoLower = new PDO('sqlite::memory:');
$pdoLower->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
$connLower = new TypeDb\Connection($pdoLower);

$resFloat = TypeDb\quick_query($connLower, 'select :PARAM', [':PARAM' => TypeDb\to_sql(1.5)]);
// array_key_first($resFloat[0]) => ':PARAM' (uppercase!)

$resInt = TypeDb\quick_query($connLower, 'select :PARAM', [':PARAM' => TypeDb\to_sql(1)]);
// array_key_first($resInt[0]) => ':param' (lowercase!)

// 2. Violation of CASE_UPPER normalization and type divergence
$pdoUpper = new PDO('sqlite::memory:');
$pdoUpper->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
$connUpper = new TypeDb\Connection($pdoUpper);

$resFloat = TypeDb\quick_query($connUpper, 'select :param', [':param' => TypeDb\to_sql(1.5)]);
// array_key_first($resFloat[0]) => ':param' (lowercase!)

$resInt = TypeDb\quick_query($connUpper, 'select :param', [':param' => TypeDb\to_sql(1)]);
// array_key_first($resInt[0]) => ':PARAM' (uppercase!)

// 3. Duplicate column validation bypassed under CASE_LOWER
TypeDb\quick_query($connLower, 'select :param, :PARAM', [
    ':param' => TypeDb\to_sql(1),
    ':PARAM' => TypeDb\to_sql(2.5),
]);
// Does not throw; returns keys: [':param', ':PARAM']

// 4. Duplicate column validation bypassed under CASE_UPPER
TypeDb\quick_query($connUpper, 'select :param, :PARAM', [
    ':param' => TypeDb\to_sql(1.5),
    ':PARAM' => TypeDb\to_sql(2),
]);
// Does not throw; returns keys: [':param', ':PARAM']
```

## Expected

1. Under `PDO::CASE_LOWER`, unaliased named float parameter column keys are lowercased (e.g. `':param'`).
2. Under `PDO::CASE_UPPER`, unaliased named float parameter column keys are uppercased (e.g. `':PARAM'`).
3. For any unaliased query, result column keys are invariant with respect to whether a numeric parameter is `SqlInteger` or `SqlFloat`.
4. `statement_duplicate_column_names()` detects case collisions under `PDO::CASE_LOWER` and `PDO::CASE_UPPER` regardless of parameter type and throws `RuntimeException: Failed to interpret query [HY000/unknown]: Duplicate column names in result set: ...`.

## Actual

1. Under `PDO::CASE_LOWER`, unaliased named float parameter column keys retain their SQL-literal uppercase characters (`':PARAM'`).
2. Under `PDO::CASE_UPPER`, unaliased named float parameter column keys retain their SQL-literal lowercase characters (`':param'`).
3. Column keys diverge based on value type: `SqlInteger` follows `PDO::ATTR_CASE` normalization, whereas `SqlFloat` preserves SQL-literal case.
4. Duplicate column detection is completely bypassed when one column is `SqlFloat` and the other is `SqlInteger`, returning both casing variants in the result row.

The minimal reproducer was executed across three consecutive clean runs; all runs reproduced the defect deterministically.

Evidence harness: [`confirmed-bugs.php`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-19/confirmed-bugs.php), output: [`confirmed-bugs-output.log`](https://github.com/jonbaldie/type-db/blob/main/docs/exploratory-testing/evidence/2026-09-19/confirmed-bugs-output.log).
