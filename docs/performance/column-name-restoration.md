# Indexed column-name restoration

Issue 90 concerned the cross-product between SQLite float rewrites and result columns. `QueryPlan` now selects restoration rules by generated marker ID. `SqlRewriter`, `RowHydrator`, and the public query API keep their existing contracts.

## Matching and order

The plan retains its deduplicated, ordered restoration list. A private index maps each marker's decimal text to its positions in that list. Prefixed string keys preserve leading zeros and IDs larger than PHP integers. Positions determine execution order, not numeric marker values.

The marker scanner is a prefilter. Each candidate still runs the original complete, quoted PCRE pattern against the current name. It replaces all nonoverlapping matches and preserves the captured parameter spelling. A comment alone, an unknown ID, a different parameter, or different whitespace cannot authorize a replacement. Exact user-written aliases and literal expression names still match, as they did before.

A min-heap schedules each candidate rule at most once. Rules that have already passed never return. Candidates whose templates do not yet match remain scheduled, so an inner replacement can expose an outer match. In particular, the issue's example still becomes `:p`:

```text
CAST(CAST(:p AS REAL) /* type-db:float-rewrite-1 */ AS REAL) /* type-db:float-rewrite-2 */
```

Reversing the rules leaves the newly exposed earlier template untouched. Nesting the same rule does not recursively replace its own output. Both behaviors follow the old ordered `preg_replace_callback` loop.

Changes that can assemble new marker comments trigger another scan. For example, replacing the middle expression below assembles marker 2, which was absent from the original name:

```text
CAST(:p AS REAL) /* type-dbCAST(:float AS REAL) /* type-db:float-rewrite-1 */-rewrite-2 */
```

`mayCreateMarker()` conservatively avoids that rescan for `?` followed by digits and simple ASCII named parameters whose first name byte is neither `f` nor `F`. The proof concerns the replacement boundaries. A new marker cannot start inside these parameters because they contain no slash. A marker crossing the entire replacement would have to contain `?`, or a colon followed by a letter other than `f`. Neither occurs in the marker grammar. The only colon in that grammar precedes `float`. All other parameter forms rescan after a change, including extended names, non-ASCII names, and `:f` through `:float`. Existing marker candidates are always verified against the updated name.

## PCRE and compatibility fallback

Matching and discovery both use PCRE `/i` without `/u`. The implementation does not substitute Unicode folding or PHP string-case comparison. NUL bytes, invalid UTF-8, metacharacters, and case-normalized parameter spelling retain byte-mode behavior. The constructor does not call PCRE or alter its error state.

The complete original ordered loop remains the compatibility path for any pair that is not exactly the generated template shape. Arbitrary constructor pairs retain capture-first-occurrence behavior, literal replacement strings, duplicate-pair ordering, empty patterns, and replacements that introduce later matches.

At call time, PCRE backtrack limits below 1,000,000 or recursion limits below 100,000 select the original loop. Values above `UINT32_MAX` also select it: PHP passes these settings to PCRE2 as unsigned 32-bit integers, so apparently large settings can wrap to low effective limits. These are compatibility conditions, not pattern-size eligibility limits.

There is no pattern-size cutoff. The first restoration call for a generated plan runs the original ordered loop and records failed rules in an always-run set. Successful patterns, including long literals near the compilation limit, are subsequently selected by marker. Classification is reused until `LC_CTYPE`, JIT, backtrack limit, or recursion limit changes. The next eligible call then performs another real ordered pass. A warning handler changing those settings during a call immediately switches the remaining rules to sequential execution and invalidates the classification.

A suppressed compilation probe was considered and rejected. PHP can emit a JIT warning, disable JIT, and still cache the compiled pattern. Suppressing that warning would consume it; running the pattern again from an always queue would not necessarily reproduce it. See [PHP's compilation and cache path](https://github.com/php/php-src/blob/PHP-8.5/ext/pcre/php_pcre.c#L727-L776). Learning from real replacements needs no private error handler, synthetic subject, warning forwarding, or warning replay. Construction and unchanged plans remain PCRE-free.

Failed rules remain ordered. Each failed rule's immediate successor is also scheduled: a successful rule between failures resets the error even when its text is absent. Leading failures execute before marker discovery, through the first success, so the discovery call cannot reset the incoming error before a warning handler observes it. This preserves warning order, the error seen inside handlers, and the end-of-call error. If all rules fail, no discovery runs. Unexpected replacement failures continue sequentially without replaying earlier warnings. Scanner failure also continues sequentially, after clearing the scanner's own error to its known preceding `PREG_NO_ERROR` state.

Discovery uses repeated offset-based `preg_match` calls. It retains one match at a time and schedules each rule at most once. Unknown and repeated markers do not build an occurrence-sized PHP array. Both discovery and replacements retain PCRE byte-mode `/i` behavior. A malformed huge marker ID is covered by the large-subject tests. Literal restoration patterns contain no branching or repetition; PCRE2 resets its match-call counter for each attempted starting position, so a long subject does not by itself require a new eligibility cutoff ([interpreter implementation](https://github.com/PCRE2Project/pcre2/blob/pcre2-10.46/src/pcre2_match.c#L7222)).

The optimization assumes adequate process/engine memory and stable compilation capability for a previously successful pattern in the same context. PHP can evict compiled patterns; a later allocator or JIT failure could make a previously successful skipped rule newly warning-producing. Exact warning parity under that resource failure is not proven. The real first pass avoids consuming initial warnings but cannot guarantee future skipped compilations would succeed. No size threshold is used to disguise this limitation.

## Complexity and limits

Let `B` be total rewrite bytes, `W` deduplicated rules, `L` input name bytes, `M` marker occurrences, `K` scheduled distinct candidate rules, `A` failed rules, and `U` changes requiring rediscovery. Let `P_i` be a selected pattern's length.

Construction takes O(B) time and storage. Classification performs W real replacements once per observed context, on the first actual subject: O(W L_first + B) under linear literal matching. This is not claimed to be byte-linear preprocessing on an arbitrarily large first name. With bounded names it is a one-time O(W) pass, rather than a W multiplier on every column.

For subsequent names, there are at most A failed calls, A immediate-successor calls, and K candidate calls (deduplicated). Discovery streams M occurrences in O(L + M) time under ordinary PCRE scanning, plus marker-index list visits and heap operations. With U rescans and expected constant-time hash lookup, the bound is O((K + 2A + U + 1)L + sum(P_i) + (K + A) log(K + A + 1)). Generated replacements shrink names. Per-call storage is O(L + K + A), without arrays proportional to repeated unknown marker occurrences. For ordinary names with one candidate, W = C workloads make O(W + C) regex calls, including the first classification pass.

A can equal W for invalid patterns: warning compatibility requires those calls. A successful long literal does not contribute to A. Direct constructor inputs can associate multiple rules with one ID, so discovery includes every visited entry in that ID's list. Arbitrary constructor plans and low effective PCRE limits retain the original sequential bound.

A name containing K different rewrites still receives K complete template checks and string copies. Extended parameters can also require K rescans. Dense expressions can therefore retain quadratic work in their own length. The benchmark includes this case; the change removes the unrelated rule-by-column cross-product, not every possible quadratic expression workload.

## Reproduce the checks

Run from the repository root:

```sh
composer install --no-interaction
./vendor/bin/phpunit ./tests --testdox
./vendor/bin/phpstan analyse
./vendor/bin/phpa ./src
php benchmarks/restoration.php --check
php -d memory_limit=32M benchmarks/restoration.php --memory-check
php benchmarks/restoration.php > /tmp/restoration-timings.json
php benchmarks/restoration.php --check --profile > /tmp/restoration-profile.json
```

If the sandbox prevents PHPStan from opening its worker socket, use `./vendor/bin/phpstan analyse --debug` to run the same analysis without workers.

`--check` validates exact output and bounds all replacement and match calls at sizes 50, 200, and 800. It covers aliases, matching markers, near matches, unknown IDs, dynamically assembled markers, and a single growing expression. The many-long cases use 10, 30, and 60 distinct parameters at each of 1,000, 4,000, 16,000, 16,500, and 32,700 bytes. They exercise aliases, unaliased parameters, matching aliases, and nonmatching marker-bearing aliases. Real `sqlite::memory:` queries verify every name, type, and value, warning lists, and final PCRE state. Repeated isolated calls verify that classification is not repeated per column. No absolute timing threshold controls the check.

Benchmark-only wrappers instrument `preg_replace_callback`, `preg_match`, and `preg_match_all`, including any future hidden probes. Reported subject bytes sum the submitted suffix lengths; they are an upper bound on exposed subject bytes, not measured bytes inspected by PCRE. Streaming calls reuse the original subject with an offset. A frozen original `QueryPlan` in `tests/Support/LegacyQueryPlan.php` supplies the reference. Timed ordinary restoration runs alternate reference/indexed order over five repetitions. Many-long cases report one diagnostic sample per case plus a warm repeat. SQLite timings include rewriting, construction, preparation, execution, and hydration. `--profile` adds wrapper timing overhead and belongs in a separate run.

## Recorded verification

On 2026-11-07, the added scaling check failed on the prior implementation at `16500/10/aliases`. The supplied reproduction produced 200, 1,800, and 7,200 replacements for 10, 30, and 60 distinct long parameters, with no warnings. After real-call classification, it produces 10, 30, and 60 calls: the initial ordered pass only.

At both 16,500 and 32,700 parameter bytes, real SQLite measurements are:

| Parameters and columns | Alias replacements | Marker replacements | Marker match calls |
| ---: | ---: | ---: | ---: |
| 10 | 10 | 29 | 38 |
| 30 | 30 | 89 | 118 |
| 60 | 60 | 179 | 238 |

Matching and near-matching marker aliases have the same call counts. A fresh plan performs one full first pass, then only candidate replacements; metadata and first-row keys each normalize once. All results, warnings, and end-of-call errors match the reference. On this PHP 8.5.4 / PCRE 10.46 / SQLite 3.46.1 build, the 60th template's maximum compilable parameter length is 32,715 bytes; the 32,700-byte regression is 15 bytes below it. This measured boundary is a fixture property, not an implementation cutoff.

The streaming memory check handles 200,000 unknown marker occurrences under `memory_limit=32M`, with an 8,298,496-byte peak. Independent large-subject checks covered 16 MB partial-match subjects, near-full mismatches, and long parameters with JIT on and off. Tests also cover repeated calls, unchanged-plan/constructor error state, locale and INI changes on the same plan, uint32-wrapped limits, warning-handler settings changes, invalid patterns before/between/after valid rules, marker creation and ordering, metacharacters, NUL and invalid UTF-8 bytes, and PDO case modes.

Composer install passed. PHPUnit passed 333 tests with 1,894 assertions and 13 skipped external-database cases. PHPStan passed without errors. PHPAssumptions passed with zero findings across 161 expressions. The scaling and memory checks passed. Remaining correctness limitation: newly failing skipped compilations after cache eviction/resource failure, described above; strict equivalence in that situation is not established.
