# Exploratory testing report: type-db

Date: 2026-09-12 (Pass 2)

Repository: `main` at `8d8097a` (`docs: exploratory testing report and bug evidence for 2026-09-12 (#38, #39)`)

## Interface and environment

This pass evaluated the public interface of TypeDb (`TypeDb\Connection`, `quick_query()`, `to_sql()`, `from_sql()`, and `SqlValue` implementations) across advanced SQLite schema constructs (STRICT tables, WITHOUT ROWID tables, GENERATED ALWAYS columns, and UPSERT conflict handling) and SQLite's native namespace (`::`) and array-index (`(...)`) named parameter syntax on SQLite memory connections.

- PHP 8.5.9
- SQLite 3.53.4 via `pdo_sqlite`
- Composer dependencies installed from lock file
- Working tree clean before the pass

## Baseline

All existing quality checks passed before exploratory probing:

- `./vendor/bin/phpunit ./tests --testdox`: 113 tests, 166 assertions passed
- `./vendor/bin/phpstan analyse`: no errors
- `./vendor/bin/phpa ./src`: 0 assumptions in 92 boolean expressions

## Journeys exercised

### 1. Advanced SQLite Schema Features, Modifiers, and Multi-statement Lifecycles

Goal: create and manipulate schemas using advanced SQLite engine features (`STRICT` tables, `WITHOUT ROWID` clustered primary keys, `GENERATED ALWAYS ... STORED` computed columns, and `INSERT ... ON CONFLICT DO UPDATE` upserts), verifying type preservation and constraint enforcement across writes, reads, and updates.

Result: passed.
- `STRICT` tables preserved `SqlInteger`, `SqlFloat`, and `SqlString` types without coercive decay. Inserting mismatched types into strict columns raised standard database engine errors without library corruption.
- `WITHOUT ROWID` tables roundtripped string keys and float values bit-identically.
- `GENERATED ALWAYS` columns correctly evaluated arithmetic expressions over bound float inputs and returned properly typed `SqlFloat` instances.
- `INSERT ... ON CONFLICT DO UPDATE` successfully rewrote float parameter bindings in the `UPDATE` clause and updated values retained `SqlFloat` fidelity.

### 2. SQLite Namespace-Qualified Named Parameter Identifiers (`:ns::param`)

Goal: evaluate parameter binding using SQLite's native namespace identifier syntax (`:ns::param`, `:pkg::sub::var`). Grounded in SQLite parameter grammar and PDO parameter binding.

Result: defect found ([#40](https://github.com/jonbaldie/type-db/issues/40)).
- `sqlite_float_parameter_sql()` inspects named parameter tokens using `ctype_alnum($sql[$index]) || $sql[$index] === '_'`. When reaching `:`, it terminates the identifier, splitting `:ns::param` into `:ns`, followed by a detached `:`, followed by `:param`.
- As a consequence:
  1. Floats bound to namespace parameters (e.g. `':ns::param' => to_sql(1.5)`) are not rewritten to `CAST(... AS REAL)` because the parser looks up `'ns'` instead of `'ns::param'` in `$named_float_parameters`. SQLite receives the value as text and returns `SqlString` instead of `SqlFloat`.
  2. If a query contains `:ns::param` and `$sql_values` contains a float for `:param` (or `:ns`), the second half is rewritten to `:ns:CAST(:param AS REAL)`, emitting malformed SQL that fails with a SQLite syntax error (`PDOException: SQLSTATE[HY000]: General error: 1 unrecognized token: ":CAST(:param"`).
  3. If a query uses `@ns::param` and the caller passes the unprefixed key `'ns::param'`, the tokenizer truncates the parameter to `@ns`, failing to match the key in `$named_parameters`. It bypasses the pre-flight `InvalidArgumentException` check and crashes in PDO with an unhandled `PDOException: SQLSTATE[HY000]: General error: 25 column index out of range`.

### 3. SQLite Array-Index Parameter Identifiers (`:arr(key)`)

Goal: evaluate parameter binding using SQLite's native array-indexed placeholder syntax (`:arr(idx)`, `:data(field)`). Grounded in SQLite parameter grammar and PDO parameter binding.

Result: defect found ([#41](https://github.com/jonbaldie/type-db/issues/41)).
- `sqlite_float_parameter_sql()` inspects named parameter tokens using `ctype_alnum($sql[$index]) || $sql[$index] === '_'`. When encountering `(`, it terminates the parameter token, splitting `:arr(key)` into `:arr` followed by `(key)`.
- As a consequence:
  1. Floats bound to array-index parameters (e.g. `':arr(key)' => to_sql(1.5)`) are not rewritten to `CAST(... AS REAL)` because the parser looks up `'arr'` instead of `'arr(key)'` in `$named_float_parameters`. SQLite receives the value as text and returns `SqlString` instead of `SqlFloat`.
  2. If a query contains `:arr(key)` and `$sql_values` contains a float for `:arr`, the tokenizer rewrites `:arr` into `select CAST(:arr AS REAL)(key)`, leaving `(key)` trailing the cast as invalid SQL syntax (`PDOException: SQLSTATE[HY000]: General error: 1 near "(": syntax error`).
  3. If a query uses `@arr(key)` and the caller supplies the unprefixed key `'arr(key)'`, the tokenizer truncates the parameter to `@arr`, bypassing the pre-flight `InvalidArgumentException` check and crashing in PDO with an unhandled `PDOException: SQLSTATE[HY000]: General error: 25 column index out of range`.

## Confirmed issues filed

| Issue | Finding | Evidence |
| --- | --- | --- |
| [#40](https://github.com/jonbaldie/type-db/issues/40) | `sqlite_float_parameter_sql()` does not recognise SQLite namespace (`::`) named parameter syntax | `:ns::param` returned as `SqlString('1.5')`; SQL corruption `:ns:CAST(:param AS REAL)` |
| [#41](https://github.com/jonbaldie/type-db/issues/41) | `sqlite_float_parameter_sql()` does not recognise SQLite array-index (`(...)`) named parameter syntax | `:arr(key)` returned as `SqlString('1.5')`; SQL corruption `CAST(:arr AS REAL)(key)` |

## Evidence and replay

- [Minimal confirmed-bug harness](evidence/2026-09-12-pass-2/confirmed-bugs.php)
- [Three replay output](evidence/2026-09-12-pass-2/confirmed-bugs-output.log)
- [Issue body: namespace named parameter](evidence/2026-09-12-pass-2/bug-namespace-named-parameter.md)
- [Issue body: array-index named parameter](evidence/2026-09-12-pass-2/bug-array-index-named-parameter.md)

## Blocked or unexplored

External database servers (MySQL on port 3306 and PostgreSQL on port 5432) were unreachable in the local environment, so driver-specific testing was limited to SQLite (`pdo_sqlite`).
