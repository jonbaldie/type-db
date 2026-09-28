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
    /** @var list<array{pattern: string, original: string}> */
    private array $restorations = [];

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
            $this->restorations[] = self::restoration($rewrite['rewritten'], $rewrite['original']);
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
        foreach ($this->restorations as $restoration) {
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
