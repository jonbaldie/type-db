<?php

declare(strict_types=1);

use TypeDb\LegacyQueryPlan;
use TypeDb\QueryPlan;

require_once __DIR__ . '/Support/LegacyQueryPlan.php';

class QueryPlanTest extends \PHPUnit\Framework\TestCase
{
    private static function rewrite(string $parameter, string $id): array
    {
        return [
            'rewritten' => 'CAST(' . $parameter . ' AS REAL) /* type-db:float-rewrite-' . $id . ' */',
            'original' => $parameter,
        ];
    }

    /** @dataProvider orderedCases */
    public function testOrderedRestoration(array $rewrites, string $name, string $expected): void
    {
        $legacy = new LegacyQueryPlan('sql', $rewrites);
        $plan = new QueryPlan('sql', $rewrites);
        self::assertSame($expected, $legacy->restoreColumnName($name));
        self::assertSame($expected, $plan->restoreColumnName($name));
        self::assertSame($expected, $plan->restoreColumnName($name));
    }

    public static function orderedCases(): iterable
    {
        $one = self::rewrite(':p', '1');
        $two = self::rewrite(':p', '2');
        $nested = 'CAST(' . $one['rewritten'] . ' AS REAL) /* type-db:float-rewrite-2 */';
        yield 'issue comment nested replacement' => [[$one, $two], $nested, ':p'];
        yield 'earlier rules are never revisited' => [[$two, $one], $nested, $two['rewritten']];
        yield 'same rule is not recursive' => [[$one], 'CAST(' . $one['rewritten'] . ' AS REAL) /* type-db:float-rewrite-1 */', $one['rewritten']];
        yield 'all occurrences before next rule' => [[$one, $two], $nested . ' + ' . $one['rewritten'], ':p + :p'];
        yield 'captured spelling' => [[$one, $two], strtoupper($one['rewritten']) . ' + ' . $two['rewritten'], ':P + :p'];
        yield 'whole template verification' => [[$one], str_replace(':p', ':q', $one['rewritten']), str_replace(':p', ':q', $one['rewritten'])];
        yield 'spaces are literal' => [[$one], str_replace(' AS ', '  AS ', $one['rewritten']), str_replace(' AS ', '  AS ', $one['rewritten'])];
        yield 'unknown id' => [[$one], $two['rewritten'], $two['rewritten']];
        yield 'leading zeros distinct' => [[$one], self::rewrite(':p', '01')['rewritten'], self::rewrite(':p', '01')['rewritten']];
        yield 'matching leading zeros in constructor' => [[self::rewrite(':p', '01')], self::rewrite(':p', '01')['rewritten'], ':p'];
        yield 'large id does not overflow' => [[self::rewrite(':p', str_repeat('9', 40))], self::rewrite(':p', str_repeat('9', 40))['rewritten'], ':p'];
        yield 'same marker different templates' => [[self::rewrite(':q', '1'), $one], $one['rewritten'], ':p'];
        yield 'quotes are not SQL parsed' => [[$one], "'" . $one['rewritten'] . "'", "':p'"];
        yield 'duplicate pairs retain first position' => [[$two, $one, $two], $nested, $two['rewritten']];
        yield 'arbitrary pairs create indexed match' => [[['rewritten' => 'token', 'original' => $one['rewritten']], $one], 'token', ':p'];
        yield 'arbitrary pairs consume indexed result' => [[$one, ['rewritten' => ':p', 'original' => 'done']], $one['rewritten'], 'done'];
        yield 'arbitrary capture first occurrence' => [[['rewritten' => 'pre-X-X', 'original' => 'X']], 'PRE-x-X', 'x'];
        yield 'arbitrary replacement is not expanded' => [[['rewritten' => 'x', 'original' => '$1\\2']], 'X', '$1\\2'];
        yield 'capture may precede the parameter position' => [[self::rewrite('CAST', '1')], 'cast(CAST AS REAL) /* type-db:float-rewrite-1 */', 'cast'];
        yield 'empty pattern inserts' => [[['rewritten' => '', 'original' => 'x']], 'ab', 'xaxbx'];
        yield 'empty original deletes' => [[['rewritten' => 'a', 'original' => '']], 'Aa', ''];
        yield 'regex metacharacters are literal' => [[self::rewrite(':a($1.\\[x])', '1')], self::rewrite(':a($1.\\[x])', '1')['rewritten'], ':a($1.\\[x])'];
        yield 'NUL and invalid UTF8 are bytes' => [[self::rewrite(":a\0\xff", '1')], self::rewrite(":a\0\xff", '1')['rewritten'], ":a\0\xff"];
        yield 'unicode folding is not enabled' => [[self::rewrite(':k', '1')], self::rewrite(':K', '1')['rewritten'], self::rewrite(':K', '1')['rewritten']];
        yield 'unicode byte spelling' => [[self::rewrite(':é', '1')], self::rewrite(':É', '1')['rewritten'], self::rewrite(':É', '1')['rewritten']];
        yield 'no rewrites' => [[], $nested, $nested];

        $assemble = self::rewrite(':float', '1');
        $seam = 'CAST(:p AS REAL) /* type-db' . $assemble['rewritten'] . '-rewrite-2 */';
        yield 'replacement assembles previously absent marker' => [[$assemble, $two], $seam, ':p'];
        yield 'assembled marker cannot rerun earlier rule' => [[$two, $assemble], $seam, $two['rewritten']];
        foreach (['f', 'fl', 'flo', 'floa', 'float'] as $prefix) {
            $part = self::rewrite(':' . $prefix, '1');
            $assembled = 'CAST(:p AS REAL) /* type-db' . $part['rewritten'] . substr('float', strlen($prefix)) . '-rewrite-2 */';
            yield 'partial marker assembly ' . $prefix => [[$part, $two], $assembled, ':p'];
            yield 'uppercase partial marker assembly ' . $prefix => [[$part, $two], strtoupper($assembled), ':P'];
        }
        $embedded = self::rewrite(':arr(' . $one['rewritten'] . ')', '2');
        yield 'parameter contains another marker' => [[$one, $embedded], $embedded['rewritten'], 'CAST(:arr(:p) AS REAL) /* type-db:float-rewrite-2 */'];
        yield 'outer replacement retains marker in capture' => [[$embedded, $one], $embedded['rewritten'], ':arr(:p)'];

        $long = ':p' . str_repeat('x', 32700);
        $longOne = self::rewrite($long, '1');
        $longTwo = self::rewrite($long, '2');
        $longNested = 'CAST(' . $longOne['rewritten'] . ' AS REAL) /* type-db:float-rewrite-2 */';
        yield 'long nested ordered matches' => [[$longOne, $longTwo], $longNested, $long];
        yield 'long earlier rule stays passed' => [[$longTwo, $longOne], $longNested, $longTwo['rewritten']];
        yield 'long dynamically assembled marker' => [[$assemble, $longTwo],
            'CAST(' . $long . ' AS REAL) /* type-db' . $assemble['rewritten'] . '-rewrite-2 */', $long];
    }

    public function testDeterministicDifferentialNames(): void
    {
        mt_srand(90);
        $parameters = [':p', ':q', '?', '?12', ':a$b', ':ns::arr(key)', ':é', ":bad\xff", ':arr(/* type-db:float-rewrite-1 */)'];
        for ($trial = 0; $trial < 300; $trial++) {
            $rewrites = [];
            for ($i = 1; $i <= 8; $i++) {
                $rewrites[] = self::rewrite($parameters[mt_rand(0, count($parameters) - 1)], (string) mt_rand(1, 6));
            }
            $name = '';
            for ($i = 0; $i < 12; $i++) {
                $rewrite = $rewrites[mt_rand(0, 7)];
                $fragment = $rewrite['rewritten'];
                $fragment = match (mt_rand(0, 4)) {
                    0 => strtoupper($fragment),
                    1 => str_replace('REAL', 'TEXT', $fragment),
                    2 => 'CAST(' . $fragment . ' AS REAL) /* type-db:float-rewrite-' . mt_rand(1, 6) . ' */',
                    3 => "'" . $fragment . "'",
                    default => $fragment,
                };
                $name .= $fragment . ' + ';
            }
            $expected = (new LegacyQueryPlan('', $rewrites))->restoreColumnName($name);
            $plan = new QueryPlan('', $rewrites);
            self::assertSame($expected, $plan->restoreColumnName($name), 'cold trial ' . $trial);
            self::assertSame($expected, $plan->restoreColumnName($name), 'warm trial ' . $trial);
        }
    }

    public function testPcreLocaleCaseTables(): void
    {
        $previous = setlocale(LC_CTYPE, '0');
        try {
            foreach (['C', 'C.utf8', 'en_US.utf8', 'tr_TR.ISO8859-9'] as $locale) {
                if (setlocale(LC_CTYPE, $locale) === false) {
                    continue;
                }
                $rewrites = [self::rewrite(':Ié', '1')];
                foreach ([':Ié', ':ié', ":\xfdé", ':IÉ'] as $parameter) {
                    $name = self::rewrite($parameter, '1')['rewritten'];
                    self::assertSame(
                        (new LegacyQueryPlan('', $rewrites))->restoreColumnName($name),
                        (new QueryPlan('', $rewrites))->restoreColumnName($name),
                        $locale,
                    );
                }
            }
        } finally {
            setlocale(LC_CTYPE, $previous);
        }
    }

    private static function outcome(object $plan, string $name): array
    {
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $restored = $plan->restoreColumnName($name);
            return [$restored, $warnings, preg_last_error()];
        } finally {
            restore_error_handler();
        }
    }

    public function testOversizedPatternsPreserveWarningsAndContinuation(): void
    {
        $huge = self::rewrite(':' . str_repeat('x', 40000), '1');
        $small = self::rewrite(':p', '2');
        $other = self::rewrite(':q', '3');
        $huge2 = self::rewrite(':' . str_repeat('y', 40000), '4');
        foreach ([[$huge], [$huge, $small], [$small, $huge], [$huge, $small, $huge2, $other], [$small, $huge, $other]] as $rewrites) {
            foreach (['alias', $small['rewritten']] as $name) {
                $legacy = self::outcome(new LegacyQueryPlan('', $rewrites), $name);
                self::assertNotEmpty($legacy[1]);
                self::assertSame($legacy, self::outcome(new QueryPlan('', $rewrites), $name));
            }
        }
    }

    public function testLongPatternsPreserveWarningsErrorsAndOrder(): void
    {
        $jit = ini_get('pcre.jit');
        try {
            foreach (['0', '1'] as $mode) {
                ini_set('pcre.jit', $mode);
                foreach ([1000, 16500, 20000, 32700] as $length) {
                    $one = self::rewrite(':p' . $mode . str_repeat('x', $length), '1');
                    $two = self::rewrite(':q' . $mode . str_repeat('y', $length), '2');
                    $huge = self::rewrite(':' . str_repeat('z', 40000), '3');
                    foreach ([[$one, $two], [$huge, $one, $two], [$one, $huge, $two], [$one, $two, $huge]] as $rewrites) {
                        $legacy = new LegacyQueryPlan('', $rewrites);
                        $plan = new QueryPlan('', $rewrites);
                        foreach (['alias', $one['rewritten'], strtoupper($two['rewritten']),
                            str_replace('AS REAL', 'AS TEXT', $one['rewritten'])] as $name) {
                            $expected = self::outcome($legacy, $name);
                            self::assertSame($expected, self::outcome($plan, $name));
                            self::assertSame($expected, self::outcome($plan, $name));
                        }
                    }
                }
            }
        } finally {
            ini_set('pcre.jit', $jit);
        }
    }

    public function testLongQuotedBytePatterns(): void
    {
        $jit = ini_get('pcre.jit');
        try {
            foreach (['0', '1'] as $mode) {
                ini_set('pcre.jit', $mode);
                foreach ([16500, 25000, 32700] as $bytes) {
                    $parameter = ':p' . $mode . str_repeat("$\\/[]()\0\xff", 500);
                    $rewrite = self::rewrite($parameter, '1');
                    $base = new LegacyQueryPlan('', [$rewrite]);
                    $patterns = (new ReflectionProperty($base, 'restorations'))->getValue($base);
                    $parameter .= str_repeat('x', $bytes - strlen($patterns[0]['pattern']));
                    $rewrite = self::rewrite($parameter, '1');
                    $legacy = new LegacyQueryPlan('', [$rewrite]);
                    $plan = new QueryPlan('', [$rewrite]);
                    foreach (['alias', $rewrite['rewritten'], strtoupper($rewrite['rewritten'])] as $name) {
                        $expected = self::outcome($legacy, $name);
                        self::assertSame([], $expected[1]);
                        self::assertSame(PREG_NO_ERROR, $expected[2]);
                        self::assertSame($expected, self::outcome($plan, $name));
                    }
                }
            }
        } finally {
            ini_set('pcre.jit', $jit);
        }
    }

    public function testConstructionDoesNotCompileLongPatterns(): void
    {
        @preg_match('/[/', '');
        $error = preg_last_error();
        new QueryPlan('', [self::rewrite(':' . str_repeat('x', 40000), '1')]);
        self::assertSame($error, preg_last_error());
    }

    public function testLowPcreLimitsPreserveFailureBehavior(): void
    {
        $backtrack = ini_get('pcre.backtrack_limit');
        $recursion = ini_get('pcre.recursion_limit');
        $jit = ini_get('pcre.jit');
        try {
            foreach (['0', '1', '2', '10', '4294967296', '4294967297'] as $limit) {
                ini_set('pcre.jit', '0');
                ini_set('pcre.backtrack_limit', $limit);
                ini_set('pcre.recursion_limit', $limit);
                foreach ([0, 16500, 32700] as $length) {
                    $rewrites = [self::rewrite(':p' . $limit . str_repeat('x', $length), '1'),
                        self::rewrite(':q' . $limit . str_repeat('y', $length), '2')];
                    foreach (['alias', $rewrites[0]['rewritten'], $rewrites[1]['rewritten']] as $name) {
                        self::assertSame(
                            self::outcome(new LegacyQueryPlan('', $rewrites), $name),
                            self::outcome(new QueryPlan('', $rewrites), $name),
                        );
                    }
                }
            }
        } finally {
            ini_set('pcre.backtrack_limit', $backtrack);
            ini_set('pcre.recursion_limit', $recursion);
            ini_set('pcre.jit', $jit);
        }
    }

    public function testUnchangedPlanPreservesPcreErrorState(): void
    {
        @preg_match('/[/', '');
        $error = preg_last_error();
        self::assertSame('alias', QueryPlan::unchanged('sql')->restoreColumnName('alias'));
        self::assertSame($error, preg_last_error());
    }

    public function testLongSubjectsAndJitModesMatchLegacyErrorsAndResults(): void
    {
        $jit = ini_get('pcre.jit');
        try {
            foreach (['0', '1'] as $mode) {
                ini_set('pcre.jit', $mode);
                $rewrite = self::rewrite(':' . str_repeat('x', 930), $mode . '1');
                $plan = new QueryPlan('', [$rewrite]);
                $legacy = new LegacyQueryPlan('', [$rewrite]);
                foreach ([
                    str_repeat('CAST(' . str_repeat('x', 100), 20000),
                    '/* type-db:float-rewrite-' . str_repeat('1', 2000000) . ' x */',
                    str_repeat($rewrite['rewritten'], 1000),
                ] as $name) {
                    self::assertSame(self::outcome($legacy, $name), self::outcome($plan, $name));
                }
            }
        } finally {
            ini_set('pcre.jit', $jit);
        }
    }

    public function testWarmWarningsObserveTheSamePcreState(): void
    {
        $bad = self::rewrite(':' . str_repeat('x', 40000), '1');
        $bad2 = self::rewrite(':' . str_repeat('y', 40000), '2');
        $good = self::rewrite(':p', '3');
        foreach ([[$bad], [$bad, $bad2], [$bad, $good, $bad2], [$good, $bad, $bad2], [$bad, $bad2, $good]] as $rewrites) {
            $legacy = new LegacyQueryPlan('', $rewrites);
            $plan = new QueryPlan('', $rewrites);
            self::outcome($plan, 'warm');
            $capture = static function (object $plan): array {
                @preg_match('/[/', '');
                $events = [];
                set_error_handler(static function (int $severity, string $message) use (&$events): bool {
                    $events[] = [$severity, $message, preg_last_error()];
                    return true;
                });
                try {
                    return [$plan->restoreColumnName('alias'), $events, preg_last_error()];
                } finally {
                    restore_error_handler();
                }
            };
            self::assertSame($capture($legacy), $capture($plan));
        }
    }

    public function testSamePlanAcrossLocaleAndIniChanges(): void
    {
        $locale = setlocale(LC_CTYPE, '0');
        $jit = ini_get('pcre.jit');
        $backtrack = ini_get('pcre.backtrack_limit');
        $recursion = ini_get('pcre.recursion_limit');
        $rewrite = self::rewrite(':Value' . str_repeat('x', 16500), '1');
        $bad = self::rewrite(':' . str_repeat('y', 40000), '2');
        $plan = new QueryPlan('', [$rewrite, $bad]);
        $legacy = new LegacyQueryPlan('', [$rewrite, $bad]);
        try {
            foreach (['C', 'C.utf8', 'en_US.utf8', 'C'] as $nextLocale) {
                if (setlocale(LC_CTYPE, $nextLocale) === false) {
                    continue;
                }
                foreach (['0', '1', '0'] as $nextJit) {
                    ini_set('pcre.jit', $nextJit);
                    foreach (['1000000', '0', '4294967296', '2000000', '1000000'] as $limit) {
                        ini_set('pcre.backtrack_limit', $limit);
                        ini_set('pcre.recursion_limit', $limit);
                        foreach (['alias', strtoupper($rewrite['rewritten']), 'alias'] as $name) {
                            self::assertSame(self::outcome($legacy, $name), self::outcome($plan, $name));
                        }
                    }
                }
            }
        } finally {
            setlocale(LC_CTYPE, $locale);
            ini_set('pcre.jit', $jit);
            ini_set('pcre.backtrack_limit', $backtrack);
            ini_set('pcre.recursion_limit', $recursion);
        }
    }

    public function testWarningHandlerChangesLimitsDuringWarmRestoration(): void
    {
        $backtrack = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        $bad = self::rewrite(':' . str_repeat('z', 40000), '1');
        $good = self::rewrite(':coldInterpreter' . str_repeat('x', 16500), '2');
        try {
            $outcomes = [];
            foreach ([LegacyQueryPlan::class, QueryPlan::class] as $class) {
                ini_set('pcre.backtrack_limit', $backtrack);
                $plan = new $class('', [$bad, $good]);
                self::outcome($plan, 'warm');
                $warnings = [];
                set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
                    $warnings[] = $message;
                    ini_set('pcre.backtrack_limit', '0');
                    return true;
                });
                try {
                    $outcomes[] = [$plan->restoreColumnName($good['rewritten']), $warnings, preg_last_error()];
                } finally {
                    restore_error_handler();
                }
                ini_set('pcre.backtrack_limit', $backtrack);
                self::assertSame(':coldInterpreter' . str_repeat('x', 16500), self::outcome($plan, $good['rewritten'])[0]);
            }
            self::assertSame($outcomes[0], $outcomes[1]);
        } finally {
            ini_set('pcre.backtrack_limit', $backtrack);
            ini_set('pcre.jit', $jit);
        }
    }

}
