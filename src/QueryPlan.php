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
    private const MAX_INDEXED_PATTERN_BYTES = 1024;

    /** @var list<array{pattern: string, original: string, mayCreateMarker: bool}> */
    private array $restorations = [];

    /** @var array<string, list<int>>|null */
    private ?array $byMarker = [];

    /** @var array<int, true> */
    private array $always = [];

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

            if (strlen($restoration['pattern']) > self::MAX_INDEXED_PATTERN_BYTES) {
                $this->always[$position] = true;
            } else {
                $this->byMarker['id:' . $id][] = $position;
            }
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
        ) {
            return $this->restoreSequentially($name);
        }

        $originalName = $name;
        /** @var \SplMinHeap<int> $pending */
        $pending = new \SplMinHeap();
        $scheduled = [];

        if (count($this->always) > 0) {
            foreach (array_merge(array_keys($this->always), [count($this->restorations) - 1]) as $position) {
                if (!isset($scheduled[$position])) {
                    $scheduled[$position] = true;
                    $pending->insert($position);
                }
            }
        }

        if (!$this->scheduleCandidates($name, -1, $pending, $scheduled)) {
            return $this->restoreSequentially($originalName);
        }

        while (!$pending->isEmpty()) {
            $position = $pending->extract();
            $restoration = $this->restorations[$position];
            $restored = preg_replace_callback(
                $restoration['pattern'],
                static fn (array $matches): string => $matches[1] ?? $restoration['original'],
                $name,
            );

            if ($restored === null) {
                if (isset($this->always[$position])) {
                    continue;
                }
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
        $count = preg_match_all('/\/\* type-db:float-rewrite-([0-9]+) \*\//i', $name, $matches);

        if ($count === false) {
            return false;
        }

        foreach ($matches[1] as $id) {
            foreach ($this->byMarker['id:' . $id] ?? [] as $position) {
                if ($position > $after && !isset($scheduled[$position])) {
                    $scheduled[$position] = true;
                    $pending->insert($position);
                }
            }
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
