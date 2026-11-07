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

At call time, PCRE backtrack limits below 1,000,000 or recursion limits below 100,000 also select that path. Those are PHP's ordinary defaults in the measured environment. Canonical patterns above 1,024 bytes are always scheduled, since they can produce compile errors even on unrelated names. Safe patterns remain indexed. When any oversized pattern exists, the final rule is also scheduled to preserve the final PCRE error state if later safe rules would otherwise be skipped. Reduced limits retain the old behavior instead of assuming a failed match means no candidate.

If the initial scanner fails, restoration starts the original loop. If a later scan or a safe replacement fails, the loop continues from the next rule, without replaying earlier warnings. An oversized replacement that fails leaves the name unchanged and the ordered scheduler continues. Tests compare returned strings, warnings, and `preg_last_error()` for oversized patterns, low limits, long subjects, and JIT on and off. These are compatibility checks on the installed PCRE, not a guarantee against process memory exhaustion or every possible engine failure.

The fallback is selected by unusual plan shapes or resource conditions. A marker-bearing name by itself never selects a scan of all rules.

## Complexity and limits

Let `B` be total rewrite bytes, `W` be deduplicated rules, `L` be input name bytes, and `K` be distinct candidate rules actually scheduled for a name. Let `P_i` be each selected pattern's length and `U` be changes that need marker rediscovery.

Construction takes O(B) time and storage. For generated plans, which have unique IDs, restoration takes O((K + 1)L + sum(P_i) + K log(K + 1)) under linear literal matching and expected constant-time hash lookup. There are `U + 1` marker scans, with `U <= K`, and K full-template replacements. Generated replacements only shrink the name. Per-call storage is O(L + K).

For bounded names with a bounded number of candidates, unrelated W no longer multiplies the work. Each oversized rule is a candidate for every name, and a plan containing one also executes its final rule. Across C columns, the ordinary restoration stage grows with the name and candidate bytes. Decimal ID length still grows logarithmically with W. A matching marker name and a near-match name both select only their own indexed rules, plus these always-scheduled rules.

This implementation does not promise linear total-byte work for every expression. A name containing K different rewrites still receives K complete template checks and string copies. Extended parameters can also cause K rescans. A very long expression can therefore retain quadratic work in its own length. The implementation accepts that bound to preserve the existing PCRE global-replacement semantics without a mutable substring tree. The benchmark includes this case rather than hiding it.

Direct constructor inputs can associate several rules with one ID. Their discovery cost also includes every visited entry in that ID's list. Compatibility plans retain the original O(W(Lmax + P)) bound per name, where Lmax includes any expansion caused by arbitrary replacements. Reduced PCRE limits also select that bound for plans emitted by `SqlRewriter`.

## Reproduce the checks

Run from the repository root:

```sh
composer install --no-interaction
./vendor/bin/phpunit ./tests --testdox
./vendor/bin/phpstan analyse
./vendor/bin/phpa ./src
php benchmarks/restoration.php --check
php benchmarks/restoration.php > /tmp/restoration-timings.json
php benchmarks/restoration.php --check --profile > /tmp/restoration-profile.json
```

If the sandbox prevents PHPStan from opening its worker socket, use `./vendor/bin/phpstan analyse --debug` to run the same analysis without workers.

`--check` validates exact output and bounds replacement and discovery calls at sizes 50, 200, and 800. It covers plain aliases, matching markers, near matches, unknown IDs, dynamically assembled markers, and a single growing expression. Dynamic cases use W = C + 1. Other column workloads use W = C. The script also executes real `sqlite::memory:` queries and verifies every result name, value, and type. Failure throws and exits nonzero. No absolute timing threshold controls the check.

The script defines benchmark-only wrappers around the two PCRE calls. Their counts and submitted subject bytes expose the work even on a noisy machine. A frozen copy of the original `QueryPlan` from commit `221c41563ae957c1e710bc624609521abadbfde3` supplies the ordered reference in `tests/Support/LegacyQueryPlan.php`. Timed restoration runs alternate reference and indexed order across five repetitions. Plan construction is outside those timings and is reported separately. SQLite timings include rewriting, construction, preparation, execution, and hydration. `--profile` adds time attribution inside the regex wrappers and belongs in a separate run.

## Recorded verification

The final quality checks passed on 2026-11-07. PHPUnit reported 264 tests, 924 assertions, and 13 skipped external-database cases. PHPStan reported no errors. PHPAssumptions reported zero findings across 148 expressions. Composer installed from the lock file without dependency changes.

The environment was PHP 8.5.4, PCRE 10.46 with JIT enabled, and SQLite 3.46.1. The machine had four available CPUs. Load averages rose from 2.47 before work to 4.42 near the final runs. Timings are instrumented local observations, not production latency guarantees. Deterministic operation counts are the regression criterion.

The matching-marker workload produced these results. Times are medians of five runs in milliseconds.

| C = W | Old replacement calls | Indexed replacement calls | Old time, range | Indexed time, range |
| ---: | ---: | ---: | --- | --- |
| 50 | 2,500 | 50 | 1.496, 1.415 to 1.642 | 0.137, 0.124 to 0.156 |
| 200 | 40,000 | 200 | 28.613, 25.885 to 31.190 | 0.604, 0.530 to 0.676 |
| 800 | 640,000 | 800 | 408.005, 402.629 to 428.035 | 2.211, 2.116 to 2.299 |

At 800 names, the dynamic workload used 1,600 replacements and 1,600 scans, versus 640,800 old replacements. Plain aliases and unknown markers used zero template replacements. Near matches used 800.

The single expression with 800 markers took 8.971 ms before and 9.910 ms after. It still performed 800 template replacements, plus one indexed discovery scan. This workload gets no cross-product reduction and pays index scheduling overhead.

Real SQLite at 800 columns measured 9.191 ms for aliases and 13.781 ms for marker-bearing expressions. The earlier unpatched run measured 746.620 ms and 823.677 ms respectively. These end-to-end runs were sequential, so they are supporting evidence rather than an interleaved speedup estimate.

A separate attributed run of an earlier indexed prototype spent 367.843 ms inside the old regex calls out of 793.287 ms total. Indexed regex calls took 1.578 ms out of 6.679 ms. The isolated workload contains no database or I/O work. Its cost is regex execution and the PHP loop, callbacks, and measurement wrappers. The reduced call count explains the change; neither side skips output validation.

The independent design comparison selected the ordered worklist over a linked-byte representation. The latter offered stronger bounds for dense expressions but required mutable byte links, local marker rediscovery, and simulation of PCRE's nonrecursive cursor. Retaining PCRE's complete replacement operation made compatibility easier to verify. The final rescan guard removed an observed dense-expression regression without changing that boundary.
