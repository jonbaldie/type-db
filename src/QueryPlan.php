<?php

declare(strict_types=1);

namespace TypeDb;

/**
 * SQL ready for preparation, together with what is needed to undo its
 * rewrites in the result column names the driver reports.
 *
 * SQLite names an unaliased result column after its expression text, so a
 * rewritten parameter would otherwise surface as its CAST expression and
 * rewrite marker instead of as the parameter itself.
 */
final class QueryPlan
{
    /** @var list<array{pattern: string, original: string, mayCreateMarker: bool}> */
    private array $restorations = [];

    /** @var array<string, list<int>>|null */
    private ?array $byMarker = [];

    /** @var array<int, true> */
    private array $always = [];

    private ?string $classifiedContext = null;

    /**
     * Build plans with SqlRewriter::rewrite() or QueryPlan::unchanged().
     *
     * @internal
     * @param list<array{rewritten: string, original: string}> $rewrites
     */
    public function __construct(
        private readonly string $preparedSql,
        array $rewrites = [],
    ) {
        $seen = [];

        foreach ($rewrites as $rewrite) {
            $key = $rewrite['rewritten'] . "\0" . $rewrite['original'];

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $restoration = self::restoration($rewrite['rewritten'], $rewrite['original']);
            $restoration['mayCreateMarker'] = self::mayCreateMarker($rewrite['original']);
            $position = count($this->restorations);
            $this->restorations[] = $restoration;

            if ($this->byMarker === null) {
                continue;
            }

            $prefix = 'CAST(' . $rewrite['original'] . ' AS REAL) /* type-db:float-rewrite-';
            $id = substr($rewrite['rewritten'], strlen($prefix), -3);

            if (!str_starts_with($rewrite['rewritten'], $prefix)
                || !str_ends_with($rewrite['rewritten'], ' */')
                || $id === ''
                || strspn($id, '0123456789') < strlen($id)
            ) {
                $this->byMarker = null;
                continue;
            }

            $this->byMarker['id:' . $id][] = $position;
        }
    }

    public static function unchanged(string $sql): self
    {
        return new self($sql);
    }

    public function getPreparedSql(): string
    {
        return $this->preparedSql;
    }

    public function hasRewrites(): bool
    {
        return count($this->restorations) > 0;
    }

    /**
     * Restore a result column name reported for the prepared SQL to the name
     * the caller's SQL would have produced. Other names pass through.
     *
     * Matching is case-insensitive because `PDO::ATTR_CASE` (`CASE_LOWER` /
     * `CASE_UPPER`) normalizes the case of column names PDO reports, while the
     * rewrite template is generated in a fixed case. The parameter text is
     * taken from the matched name, so that normalization is respected.
     */
    public function restoreColumnName(string $name): string
    {
        if ($this->restorations === []) {
            return $name;
        }

        if ($this->byMarker === null
            || (int) ini_get('pcre.backtrack_limit') < 1000000
            || (int) ini_get('pcre.recursion_limit') < 100000
            || (int) ini_get('pcre.backtrack_limit') > 4294967295
            || (int) ini_get('pcre.recursion_limit') > 4294967295
        ) {
            return $this->restoreSequentially($name);
        }

        $context = self::pcreContext();
        if ($this->classifiedContext === $context) {
            return $this->restoreIndexed($name, $context);
        }
        return $this->classifyWhileRestoring($name, $context);
    }

    private function restoreIndexed(string $name, string $context): string
    {
        // Leading failures can expose the incoming error to warning handlers.
        // Execute that prefix through its first success before discovery. If
        // the first rule is safe, discovery resets the error just as it would.
        $after = -1;
        if (isset($this->always[0])) {
            foreach ($this->restorations as $position => $restoration) {
                $restored = preg_replace_callback(
                    $restoration['pattern'],
                    static fn (array $matches): string => $matches[1] ?? $restoration['original'],
                    $name,
                );
                $after = $position;
                if (!self::contextIsCurrent($context) || ($restored === null && !isset($this->always[$position]))) {
                    $this->classifiedContext = null;
                    return $this->restoreSequentially($restored ?? $name, $position + 1);
                }
                if (is_string($restored)) {
                    $name = $restored;
                    break;
                }
            }
        }
        if ($after === count($this->restorations) - 1) {
            return $name;
        }

        $originalName = $name;
        /** @var \SplMinHeap<int> $pending */
        $pending = new \SplMinHeap();
        $scheduled = [];

        foreach ($this->always as $position => $_) {
            // A following successful rule resets the error before the next
            // warning, even when its template is absent from this name.
            foreach ([$position, $position + 1] as $required) {
                if ($required > $after && $required < count($this->restorations) && !isset($scheduled[$required])) {
                    $scheduled[$required] = true;
                    $pending->insert($required);
                }
            }
        }

        if (!$this->scheduleCandidates($name, $after, $pending, $scheduled)) {
            return $this->restoreSequentially($originalName, $after + 1);
        }

        while (!$pending->isEmpty()) {
            $position = $pending->extract();
            $restoration = $this->restorations[$position];
            $restored = preg_replace_callback(
                $restoration['pattern'],
                static fn (array $matches): string => $matches[1] ?? $restoration['original'],
                $name,
            );

            // A warning handler can change PCRE settings or the locale. The
            // remaining original calls must observe those changes immediately.
            if (!self::contextIsCurrent($context)) {
                $this->classifiedContext = null;
                return $this->restoreSequentially($restored ?? $name, $position + 1);
            }

            if ($restored === null) {
                if (isset($this->always[$position])) {
                    continue;
                }
                $this->classifiedContext = null;
                return $this->restoreSequentially($name, $position + 1);
            }

            if ($restored === $name) {
                continue;
            }

            $name = $restored;

            if ($restoration['mayCreateMarker'] && $position < count($this->restorations) - 1
                && !$this->scheduleCandidates($name, $position, $pending, $scheduled)
            ) {
                return $this->restoreSequentially($name, $position + 1);
            }
        }

        return $name;
    }

    private static function contextIsCurrent(string $context): bool
    {
        return self::pcreContext() === $context;
    }

    private static function pcreContext(): string
    {
        return setlocale(LC_CTYPE, '0') . "\0" . ini_get('pcre.jit') . "\0"
            . ini_get('pcre.backtrack_limit') . "\0" . ini_get('pcre.recursion_limit');
    }

    /**
     * Learn compilation failures during the first real ordered pass. A hidden
     * probe could consume a one-time JIT warning and change the engine cache.
     * Actual replacements preserve warning delivery and the final error state.
     */
    private function classifyWhileRestoring(string $name, string $context): string
    {
        $always = [];
        foreach ($this->restorations as $position => $restoration) {
            $restored = preg_replace_callback(
                $restoration['pattern'],
                static fn (array $matches): string => $matches[1] ?? $restoration['original'],
                $name,
            );
            if ($restored === null) {
                $always[$position] = true;
            } else {
                $name = $restored;
            }

            if (!self::contextIsCurrent($context)) {
                $this->classifiedContext = null;
                return $this->restoreSequentially($name, $position + 1);
            }
        }

        $this->always = $always;
        $this->classifiedContext = $context;
        return $name;
    }

    private static function mayCreateMarker(string $parameter): bool
    {
        if (str_starts_with($parameter, '?')
            && strspn($parameter, '0123456789', 1) === strlen($parameter) - 1
        ) {
            return false;
        }

        return !(strlen($parameter) > 1
            && $parameter[0] === ':'
            && !in_array($parameter[1], ['f', 'F'], true)
            && strspn($parameter, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$', 1) === strlen($parameter) - 1
        );
    }

    /**
     * @param \SplMinHeap<int> $pending
     * @param array<int, true> $scheduled
     */
    private function scheduleCandidates(string $name, int $after, \SplMinHeap $pending, array &$scheduled): bool
    {
        // Stream markers so unknown/repeated IDs do not allocate an array
        // proportional to the number of occurrences in a large subject.
        $offset = 0;
        while (($count = preg_match('/\/\* type-db:float-rewrite-([0-9]+) \*\//i', $name, $matches, PREG_OFFSET_CAPTURE, $offset)) === 1) {
            $id = $matches[1][0];
            $offset = $matches[0][1] + strlen($matches[0][0]);
            foreach ($this->byMarker['id:' . $id] ?? [] as $position) {
                if ($position > $after && !isset($scheduled[$position])) {
                    $scheduled[$position] = true;
                    $pending->insert($position);
                }
            }
        }

        if ($count === false) {
            // Discovery began with NO_ERROR. Its own failure must not leak
            // into a subsequent original warning handler during fallback.
            preg_match('//', '');
            return false;
        }
        return true;
    }

    private function restoreSequentially(string $name, int $start = 0): string
    {
        for ($position = $start; $position < count($this->restorations); $position++) {
            $restoration = $this->restorations[$position];
            $name = preg_replace_callback(
                $restoration['pattern'],
                static fn (array $matches): string => $matches[1] ?? $restoration['original'],
                $name,
            ) ?? $name;
        }

        return $name;
    }

    /**
     * Match the rewritten text case-insensitively, capturing the parameter
     * text inside it so the reported case is kept.
     *
     * @return array{pattern: string, original: string}
     */
    private static function restoration(string $rewritten, string $original): array
    {
        $position = strpos($rewritten, $original);

        if ($position === false) {
            return [
                'pattern' => '/' . preg_quote($rewritten, '/') . '/i',
                'original' => $original,
            ];
        }

        return [
            'pattern' => '/' . preg_quote(substr($rewritten, 0, $position), '/')
                . '(' . preg_quote($original, '/') . ')'
                . preg_quote(substr($rewritten, $position + strlen($original)), '/') . '/i',
            'original' => $original,
        ];
    }
}
