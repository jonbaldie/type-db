<?php

declare(strict_types=1);

namespace TypeDb {
    function preg_replace_callback(string $pattern, callable $callback, string $subject): ?string
    {
        \RestorationWork::$replacements++;
        \RestorationWork::$subjectBytes += strlen($subject);
        $start = \RestorationWork::$profile ? hrtime(true) : 0;
        $result = \preg_replace_callback($pattern, $callback, $subject);
        if (\RestorationWork::$profile) {
            \RestorationWork::$regexNs += hrtime(true) - $start;
        }
        return $result;
    }

    function preg_match(string $pattern, string $subject, ?array &$matches = null, int $flags = 0, int $offset = 0): int|false
    {
        \RestorationWork::$matches++;
        \RestorationWork::$subjectBytes += strlen($subject) - $offset;
        $start = \RestorationWork::$profile ? hrtime(true) : 0;
        $result = \preg_match($pattern, $subject, $matches, $flags, $offset);
        if (\RestorationWork::$profile) {
            \RestorationWork::$regexNs += hrtime(true) - $start;
        }
        return $result;
    }

    function preg_match_all(string $pattern, string $subject, ?array &$matches = null, int $flags = 0): int|false
    {
        \RestorationWork::$scans++;
        \RestorationWork::$subjectBytes += strlen($subject);
        $start = \RestorationWork::$profile ? hrtime(true) : 0;
        $result = \preg_match_all($pattern, $subject, $matches, $flags);
        if (\RestorationWork::$profile) {
            \RestorationWork::$regexNs += hrtime(true) - $start;
        }
        return $result;
    }
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';
    require dirname(__DIR__) . '/tests/Support/LegacyQueryPlan.php';

    final class RestorationWork
    {
        public static int $replacements = 0;
        public static int $scans = 0;
        public static int $matches = 0;
        public static int $subjectBytes = 0;
        public static bool $profile = false;
        public static int $regexNs = 0;

        public static function reset(): void
        {
            self::$replacements = self::$matches = self::$scans = self::$subjectBytes = self::$regexNs = 0;
        }
    }

    function measure(callable $run): array
    {
        RestorationWork::reset();
        $start = hrtime(true);
        $result = $run();
        return [
            'ms' => (hrtime(true) - $start) / 1e6,
            'replacements' => RestorationWork::$replacements,
            'scans' => RestorationWork::$scans,
            'matches' => RestorationWork::$matches,
            'subject_bytes' => RestorationWork::$subjectBytes,
            'profile_regex_ms' => RestorationWork::$profile ? RestorationWork::$regexNs / 1e6 : null,
            'result' => $result,
        ];
    }

    function summary(array $samples): array
    {
        $times = array_column($samples, 'ms');
        sort($times);
        return [
            'median_ms' => $times[intdiv(count($times), 2)],
            'min_ms' => min($times),
            'max_ms' => max($times),
            'replacements' => $samples[0]['replacements'],
            'scans' => $samples[0]['scans'],
            'matches' => $samples[0]['matches'],
            'subject_bytes' => $samples[0]['subject_bytes'],
            'profile_regex_ms' => $samples[0]['profile_regex_ms'],
        ];
    }

    if (in_array('--memory-check', $argv, true)) {
        $rewrites = [];
        foreach ([1, 2] as $id) {
            $rewrites[] = ['rewritten' => 'CAST(:p AS REAL) /* type-db:float-rewrite-' . $id . ' */', 'original' => ':p'];
        }
        $plan = new TypeDb\QueryPlan('', $rewrites);
        $plan->restoreColumnName('warm');
        $name = str_repeat('/* type-db:float-rewrite-999 */', 200000);
        $sample = measure(static fn () => $plan->restoreColumnName($name));
        if ($sample['result'] !== $name || preg_last_error() !== PREG_NO_ERROR
            || $sample['replacements'] !== 0 || $sample['matches'] !== 200001) {
            throw new RuntimeException('Streaming marker memory regression.');
        }
        echo json_encode(['peak_bytes' => memory_get_peak_usage(true), 'markers' => 200000], JSON_THROW_ON_ERROR), "\n";
        exit;
    }

    $check = in_array('--check', $argv, true);
    RestorationWork::$profile = in_array('--profile', $argv, true);
    $runs = $check ? 1 : 5;
    $pdo = new PDO('sqlite::memory:');
    $connection = new TypeDb\Connection($pdo);
    $report = [
        'php' => PHP_VERSION,
        'pcre' => PCRE_VERSION,
        'pcre_jit' => ini_get('pcre.jit'),
        'pcre_backtrack_limit' => ini_get('pcre.backtrack_limit'),
        'pcre_recursion_limit' => ini_get('pcre.recursion_limit'),
        'sqlite' => $pdo->query('select sqlite_version()')->fetchColumn(),
        'runs' => $runs,
        'sizes' => [],
    ];
    foreach ([1000, 4000, 16000, 16500, 32700] as $length) {
        foreach ([10, 30, 60] as $size) {
            $rewrites = $parameters = $bindings = [];
            for ($i = 1; $i <= $size; $i++) {
                $parameter = ':' . str_pad('p' . $i, $length - 1, 'x');
                $parameters[] = $parameter;
                $bindings[$parameter] = TypeDb\to_sql((float) $i);
                $rewrites[] = [
                    'rewritten' => 'CAST(' . $parameter . ' AS REAL) /* type-db:float-rewrite-' . $i . ' */',
                    'original' => $parameter,
                ];
            }
            $names = array_column($rewrites, 'rewritten');
            $near = array_map(static fn (string $name): string => str_replace(' AS REAL)', ' AS TEXT)', $name), $names);
            $inputs = ['aliases' => array_map(static fn (int $i): string => 'c' . $i, range(1, $size)),
                'markers' => $names, 'matching_aliases' => $names, 'near_markers' => $near];
            $plans = ['legacy' => new TypeDb\LegacyQueryPlan('', $rewrites), 'indexed' => new TypeDb\QueryPlan('', $rewrites)];
            $entry = [];
            foreach ($inputs as $kind => $input) {
                $outcomes = [];
                foreach ($plans as $side => $plan) {
                    $run = static function () use ($plan, $input): array {
                        $outcomes = [];
                        foreach ($input as $name) {
                            $warnings = [];
                            set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
                                $warnings[] = [$severity, $message];
                                return true;
                            });
                            try {
                                preg_match('/x/', '');
                                $result = $plan->restoreColumnName($name);
                                $outcomes[] = [$result, $warnings, preg_last_error()];
                            } finally {
                                restore_error_handler();
                            }
                        }
                        return $outcomes;
                    };
                    $sample = measure($run);
                    if ($side === 'indexed') {
                        $warm = measure($run);
                        if ($warm['result'] !== $sample['result'] || ($check &&
                            ($warm['replacements'] > $size || $warm['matches'] + $warm['scans'] > 2 * $size))) {
                            throw new RuntimeException('Repeated restoration changed results or repeated classification.');
                        }
                        unset($warm['result']);
                        $entry[$kind]['indexed_warm'] = $warm;
                    }
                    $outcomes[$side] = $sample['result'];
                    unset($sample['result']);
                    $entry[$kind][$side] = $sample;
                }
                $expected = in_array($kind, ['markers', 'matching_aliases'], true) ? $parameters : $input;
                if ($outcomes['legacy'] !== $outcomes['indexed'] || array_column($outcomes['legacy'], 0) !== $expected
                    || array_column($outcomes['legacy'], 1) !== array_fill(0, $size, [])
                    || array_column($outcomes['legacy'], 2) !== array_fill(0, $size, PREG_NO_ERROR)) {
                    throw new RuntimeException('Many-long reference mismatch: ' . $length . '/' . $size . '/' . $kind);
                }
            }
            foreach ($inputs as $kind => $input) {
                $columns = [];
                foreach ($input as $i => $name) {
                    $columns[] = $kind === 'markers' ? $parameters[$i] : '1 AS "' . $name . '"';
                }
                $sql = 'SELECT ' . implode(',', $columns);
                if ($kind !== 'markers') {
                    $sql .= ' WHERE 1 IN (' . implode(',', $parameters) . ')';
                }
                $warnings = [];
                set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
                    $warnings[] = [$severity, $message];
                    return true;
                });
                try {
                    preg_match('/x/', '');
                    $sample = measure(static fn () => $connection->quickQuery($sql, $bindings));
                    $error = preg_last_error();
                } finally {
                    restore_error_handler();
                }
                $keys = in_array($kind, ['markers', 'matching_aliases'], true) ? $parameters : $input;
                if (count($sample['result']) !== 1 || array_keys($sample['result'][0]) !== $keys
                    || $warnings !== [] || $error !== PREG_NO_ERROR
                    || ($check && ($sample['replacements'] > 3 * $size || $sample['scans'] + $sample['matches'] > 4 * $size))) {
                    throw new RuntimeException('Incorrect SQLite many-long names or work count: ' . $length . '/' . $size . '/' . $kind . ' replacements=' . $sample['replacements'] . ' scans=' . $sample['scans'] . ' matches=' . $sample['matches']);
                }
                foreach (array_values($sample['result'][0]) as $i => $value) {
                    if ($kind === 'markers' ? !($value instanceof TypeDb\SqlValue\SqlFloat) || $value->value !== (float) ($i + 1)
                        : !($value instanceof TypeDb\SqlValue\SqlInteger) || $value->value !== 1) {
                        throw new RuntimeException('Incorrect SQLite many-long type or value: ' . $kind);
                    }
                }
                unset($sample['result']);
                $entry['sqlite_' . $kind] = $sample;
            }
            foreach ($inputs as $kind => $_) {
                if ($check && ($entry[$kind]['indexed']['replacements'] > 2 * $size
                    || $entry[$kind]['indexed']['scans'] + $entry[$kind]['indexed']['matches'] > 2 * $size)) {
                    throw new RuntimeException('Many-long work exceeds candidate count: ' . $length . '/' . $size . '/' . $kind
                        . ' replacements=' . $entry[$kind]['indexed']['replacements']
                        . ' scans=' . $entry[$kind]['indexed']['scans']
                        . ' subject_bytes=' . $entry[$kind]['indexed']['subject_bytes']);
                }
            }
            $report['many_long'][] = ['parameter_bytes' => $length, 'size' => $size, 'sample_runs' => 1, 'cases' => $entry];
        }
    }
    foreach ([50, 200, 800] as $size) {
        $rewrites = $names = $aliases = $near = $unknown = [];
        for ($i = 1; $i <= $size; $i++) {
            $template = 'CAST(:p AS REAL) /* type-db:float-rewrite-' . $i . ' */';
            $rewrites[] = ['rewritten' => $template, 'original' => ':p'];
            $names[] = $template;
            $aliases[] = 'c' . $i;
            $near[] = str_replace('AS REAL', 'AS TEXT', $template);
            $unknown[] = '/* type-db:float-rewrite-' . ($i + $size) . ' */';
        }
        $start = hrtime(true);
        $indexed = new TypeDb\QueryPlan('', $rewrites);
        $construction = (hrtime(true) - $start) / 1e6;
        $legacy = new TypeDb\LegacyQueryPlan('', $rewrites);
        $assembler = 'CAST(:float AS REAL) /* type-db:float-rewrite-0 */';
        $dynamicRewrites = array_merge([['rewritten' => $assembler, 'original' => ':float']], $rewrites);
        $dynamicNames = [];
        for ($i = 1; $i <= $size; $i++) {
            $dynamicNames[] = 'CAST(:p AS REAL) /* type-db' . $assembler . '-rewrite-' . $i . ' */';
        }
        $dynamicIndexed = new TypeDb\QueryPlan('', $dynamicRewrites);
        $dynamicLegacy = new TypeDb\LegacyQueryPlan('', $dynamicRewrites);
        $long = ':' . str_repeat('x', 1000);
        $longRewrite = ['rewritten' => 'CAST(' . $long . ' AS REAL) /* type-db:float-rewrite-0 */', 'original' => $long];
        $mixedIndexed = new TypeDb\QueryPlan('', array_merge($rewrites, [$longRewrite]));
        $entry = ['size' => $size, 'construction_ms' => $construction, 'restoration' => []];
        foreach ([
            'aliases' => $aliases,
            'markers' => $names,
            'near_markers' => $near,
            'unknown_markers' => $unknown,
            'dynamic_markers' => $dynamicNames,
            'expression' => [implode(' + ', $names)],
        ] as $kind => $input) {
            $expected = match ($kind) {
                'markers', 'dynamic_markers' => array_fill(0, $size, ':p'),
                'expression' => [implode(' + ', array_fill(0, $size, ':p'))],
                default => $input,
            };
            $samples = ['legacy' => [], 'indexed' => []];
            for ($run = 0; $run < $runs; $run++) {
                foreach ($run % 2 === 0 ? ['legacy', 'indexed'] : ['indexed', 'legacy'] as $side) {
                    $plan = $side === 'legacy' ? $legacy : $indexed;
                    if ($kind === 'dynamic_markers') {
                        $plan = $side === 'legacy' ? $dynamicLegacy : $dynamicIndexed;
                    }
                    $sample = measure(static fn () => array_map([$plan, 'restoreColumnName'], $input));
                    if ($sample['result'] !== $expected) {
                        throw new RuntimeException('Incorrect restoration: ' . $kind . '/' . $side);
                    }
                    unset($sample['result']);
                    $samples[$side][] = $sample;
                }
            }
            $replacementLimit = $kind === 'dynamic_markers' ? 4 * $size : 2 * $size;
            if ($check && ($samples['indexed'][0]['replacements'] > $replacementLimit || $samples['indexed'][0]['scans'] + $samples['indexed'][0]['matches'] > 4 * $size)) {
                throw new RuntimeException('Restoration work exceeds the candidate count: ' . $kind);
            }
            $entry['restoration'][$kind] = array_map('summary', $samples);
        }
        foreach (['aliases' => $aliases, 'markers' => $names, 'near_markers' => $near] as $kind => $input) {
            $sample = measure(static fn () => array_map([$mixedIndexed, 'restoreColumnName'], $input));
            $expected = $kind === 'markers' ? array_fill(0, $size, ':p') : $input;
            if ($sample['result'] !== $expected || ($check && $sample['replacements'] > 3 * $size)) {
                throw new RuntimeException('Incorrect mixed-size restoration or work count: ' . $kind);
            }
            unset($sample['result']);
            $entry['mixed'][$kind] = $sample;
        }
        $invalid = ':' . str_repeat('z', 40000);
        $invalidPlan = new TypeDb\QueryPlan('', array_merge(
            [['rewritten' => 'CAST(' . $invalid . ' AS REAL) /* type-db:float-rewrite-0 */', 'original' => $invalid]],
            $rewrites,
        ));
        $warnings = 0;
        set_error_handler(static function () use (&$warnings): bool { $warnings++; return true; });
        try {
            $sample = measure(static fn () => array_map([$invalidPlan, 'restoreColumnName'], $near));
        } finally {
            restore_error_handler();
        }
        if ($sample['result'] !== $near || $warnings !== $size || ($check && $sample['replacements'] > 4 * $size)) {
            throw new RuntimeException('Incorrect failed-pattern restoration or work count.');
        }
        unset($sample['result']);
        $entry['mixed']['compile_failure'] = $sample + ['warnings' => $warnings];
        $entry['sqlite'] = [];
        foreach (['aliases', 'markers'] as $kind) {
            $columns = [];
            for ($i = 1; $i <= $size; $i++) {
                $columns[] = $kind === 'aliases' ? '1 AS c' . $i : ':p + ' . $i;
            }
            $sql = 'SELECT ' . implode(',', $columns);
            if ($kind === 'aliases') {
                $sql .= ' WHERE 1 IN (' . implode(',', array_fill(0, $size, ':p')) . ')';
            }
            $samples = [];
            for ($run = 0; $run < $runs; $run++) {
                $sample = measure(static fn () => $connection->quickQuery($sql, [':p' => TypeDb\to_sql(1.0)]));
                $rows = $sample['result'];
                if (count($rows) !== 1 || count($rows[0]) !== $size) {
                    throw new RuntimeException('Incorrect SQLite row or column count.');
                }
                for ($i = 1; $i <= $size; $i++) {
                    $key = $kind === 'aliases' ? 'c' . $i : ':p + ' . $i;
                    $value = $rows[0][$key];
                    if ($kind === 'aliases' ? !($value instanceof TypeDb\SqlValue\SqlInteger) || $value->value !== 1
                        : !($value instanceof TypeDb\SqlValue\SqlFloat) || $value->value !== $i + 1.0) {
                        throw new RuntimeException('Incorrect SQLite name, type or value.');
                    }
                }
                unset($sample['result']);
                $samples[] = $sample;
            }
            $entry['sqlite'][$kind] = summary($samples);
        }
        foreach (['aliases', 'markers'] as $kind) {
            $columns = $keys = [];
            for ($i = 1; $i <= $size; $i++) {
                $keys[] = $kind === 'aliases' ? 'c' . $i : 'CAST(:q AS REAL) /* type-db:float-rewrite-' . $i . ' */';
                $columns[] = '1 AS "' . $keys[$i - 1] . '"';
            }
            $sql = 'SELECT ' . implode(',', $columns) . ' WHERE 1 IN ('
                . implode(',', array_fill(0, $size, ':p')) . ',' . $long . ')';
            $sample = measure(static fn () => $connection->quickQuery($sql, [':p' => TypeDb\to_sql(1.0), $long => TypeDb\to_sql(1.0)]));
            if (count($sample['result']) !== 1 || array_keys($sample['result'][0]) !== $keys
                || ($check && $sample['replacements'] > 5 * $size + 1)) {
                throw new RuntimeException('Incorrect SQLite mixed-size result or work count: ' . $kind);
            }
            foreach ($sample['result'][0] as $value) {
                if (!$value instanceof TypeDb\SqlValue\SqlInteger || $value->value !== 1) {
                    throw new RuntimeException('Incorrect SQLite mixed-size value.');
                }
            }
            unset($sample['result']);
            $entry['sqlite_mixed'][$kind] = $sample;
        }
        $report['sizes'][] = $entry;
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
}
