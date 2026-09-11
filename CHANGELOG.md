# Changelog

All notable changes to this project will be documented here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/). This project uses [Semantic Versioning](https://semver.org/).

## [v0.9.5] — 2026-09-11

### Fixed
- Preserve runtime SQLite value kinds per cell by inspecting each fetched scalar's runtime type before column metadata, and prefer native metadata for stringified values (#17, #20).
- Format floats using the C locale decimal point in `quick_query()` float binding to prevent decimal corruption under comma-decimal locales (#18, #21).
- Preserve numeric storage classes for `SqlInteger` and `SqlFloat` parameters in affinity-neutral SQLite columns (#19, #22).

### Documentation
- Record exploratory testing findings and reproduction evidence (#23, #24, #25).

## [v0.9.4] — 2026-09-09

### Fixed
- Default `$sql_values` to `[]` in `Connection::quickQuery()` to match documentation and `quick_query()` helper (#4, #10).
- Classify `quick_query()` result cells from SQL column types when PDO stringifies fetches (`PDO::ATTR_STRINGIFY_FETCHES`) (#5, #11).
- Throw `RuntimeException` when `quick_query()` result columns share names instead of silently dropping earlier columns (#6, #12).
- Throw `InvalidArgumentException` when `from_sql()` is given an unknown `SqlValue` implementation instead of returning `null` and binding SQL `NULL` (#7, #13).
- Bind `SqlFloat` with shortest round-trip decimal representation in `quick_query()` to prevent silent truncation to 14 significant digits (#8, #14).
- Handle non-finite floats in `quick_query()`: encode `INF` and `-INF` as SQLite overflow literals and throw `InvalidArgumentException` for `NAN` (#9, #15).
- Throw `RuntimeException` with PDO SQLSTATE, driver error code/message, and SQL string when `prepare()` or `execute()` fails under `PDO::ERRMODE_SILENT`.

### Changed
- Upgrade tooling dependencies and modernize test suite for PHP 8.4 compatibility.
- Configure agent skills and switch issue tracking to GitHub Issues.

## [v0.9.3] — 2022-09-25

### Documentation
- Clarifications and refinements in README documentation.

## [v0.9.2] — 2022-09-22

### Added
- Add requirement for `ext-pdo` in `composer.json`.

## [v0.9.1] — 2022-09-22

### Added
- Default value for `$sql_values` in `quick_query()` helper.
- Update README checklist.

## [v0.9.0] — 2022-09-22

### Added
- Initial release of TypeDb: type-safe database connector for PHP.
- `Connection` wrapper class for `PDO`.
- `SqlValue` interface with `SqlString`, `SqlFloat`, `SqlInteger`, and `SqlNull` implementations.
- `to_sql()`, `from_sql()`, and `quick_query()` helper functions.

[v0.9.0]: https://github.com/jonbaldie/type-db/releases/tag/v0.9.0
[v0.9.1]: https://github.com/jonbaldie/type-db/compare/v0.9.0...v0.9.1
[v0.9.2]: https://github.com/jonbaldie/type-db/compare/v0.9.1...v0.9.2
[v0.9.3]: https://github.com/jonbaldie/type-db/compare/v0.9.2...v0.9.3
[v0.9.4]: https://github.com/jonbaldie/type-db/compare/v0.9.3...v0.9.4
[v0.9.5]: https://github.com/jonbaldie/type-db/compare/v0.9.4...v0.9.5

