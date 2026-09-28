<?php

declare(strict_types=1);

use TypeDb\QueryPlan;
use TypeDb\SqlRewriter;

class SqlRewriterTest extends \PHPUnit\Framework\TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        try {
            $this->pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }
    }

    /**
     * Prepare the plan against SQLite, bind the values, and return the first
     * column's reported name and value.
     *
     * @param \TypeDb\SqlValue\SqlValue[] $sql_values
     * @return array{string, mixed}
     */
    private function firstColumn(QueryPlan $plan, array $sql_values): array
    {
        $statement = $this->pdo->prepare($plan->getPreparedSql());
        $this->assertInstanceOf(\PDOStatement::class, $statement);

        foreach ($sql_values as $key => $value) {
            $statement->bindValue(
                is_int($key) ? $key + 1 : $key,
                \TypeDb\bind_sql_value($value),
                \TypeDb\sql_value_parameter_type($value),
            );
        }

        $this->assertTrue($statement->execute());
        $meta = $statement->getColumnMeta(0);
        $this->assertIsArray($meta);
        $row = $statement->fetch(\PDO::FETCH_NUM);
        $this->assertIsArray($row);

        return [(string) $meta['name'], $row[0]];
    }

    /**
     * @test
     */
    public function it_leaves_sql_without_float_parameters_unchanged()
    {
        $plan = (new SqlRewriter())->rewrite(
            'select ? as a, :b as b',
            [\TypeDb\to_sql(1), 'b' => \TypeDb\to_sql('text')]
        );

        $this->assertFalse($plan->hasRewrites());
        $this->assertSame('select ? as a, :b as b', $plan->getPreparedSql());
        $this->assertSame('?', $plan->restoreColumnName('?'));
    }

    /**
     * @test
     */
    public function it_casts_positional_float_parameters_to_real()
    {
        $values = [\TypeDb\to_sql(1.5)];
        $plan = (new SqlRewriter())->rewrite('select ?', $values);

        $this->assertTrue($plan->hasRewrites());

        [$name, $value] = $this->firstColumn($plan, $values);

        $this->assertSame(1.5, $value);
        $this->assertSame('?', $plan->restoreColumnName($name));
    }

    /**
     * @test
     */
    public function it_casts_named_float_parameters_to_real()
    {
        $values = [':ratio' => \TypeDb\to_sql(2.5)];
        $plan = (new SqlRewriter())->rewrite('select :ratio', $values);

        $this->assertTrue($plan->hasRewrites());

        [$name, $value] = $this->firstColumn($plan, $values);

        $this->assertSame(2.5, $value);
        $this->assertSame(':ratio', $plan->restoreColumnName($name));
    }

    /**
     * @test
     */
    public function it_restores_case_normalized_column_names()
    {
        $values = [':ratio' => \TypeDb\to_sql(2.5)];
        $plan = (new SqlRewriter())->rewrite('select :ratio', $values);

        [$name] = $this->firstColumn($plan, $values);

        $this->assertSame(':RATIO', $plan->restoreColumnName(strtoupper($name)));
        $this->assertSame(':ratio', $plan->restoreColumnName(strtolower($name)));
    }

    /**
     * @test
     */
    public function it_passes_aliased_and_unrelated_column_names_through()
    {
        $plan = (new SqlRewriter())->rewrite(
            'select ? as ratio, \'CAST(? AS REAL)\' as literal',
            [\TypeDb\to_sql(1.5)]
        );

        $this->assertTrue($plan->hasRewrites());
        $this->assertSame('ratio', $plan->restoreColumnName('ratio'));
        $this->assertSame('CAST(? AS REAL)', $plan->restoreColumnName('CAST(? AS REAL)'));
    }

    /**
     * @test
     */
    public function it_does_not_rewrite_parameters_inside_literals_identifiers_or_comments()
    {
        $plan = (new SqlRewriter())->rewrite(
            "select '?', \":f\", [?], `:f`, -- ?\n /* :f */ 1",
            [\TypeDb\to_sql(1.5), ':f' => \TypeDb\to_sql(2.5)]
        );

        $this->assertFalse($plan->hasRewrites());
        $this->assertSame(
            "select '?', \":f\", [?], `:f`, -- ?\n /* :f */ 1",
            $plan->getPreparedSql()
        );
    }

    /**
     * @test
     */
    public function it_does_not_rewrite_partial_names_of_compound_parameters()
    {
        $rewriter = new SqlRewriter();

        foreach (
            [
                ['select :ns::param as r', ':param'],
                ['select :ns::param as r', ':ns'],
                ['select :arr(key) as r', ':arr'],
                ['select :arr(key) as r', ':key'],
                ['select :ns::arr(key) as r', ':arr'],
                ['select :a$b as r', 'b'],
            ] as [$sql, $key]
        ) {
            $plan = $rewriter->rewrite($sql, [$key => \TypeDb\to_sql(1.5)]);

            $this->assertFalse($plan->hasRewrites(), $sql . ' with ' . $key);
            $this->assertSame($sql, $plan->getPreparedSql());
        }
    }

    /**
     * @test
     */
    public function it_rewrites_array_index_parameters_with_nested_parentheses()
    {
        $rewriter = new SqlRewriter();

        foreach ([':arr(nested(1))', ':arr((key))'] as $parameter) {
            $values = [$parameter => \TypeDb\to_sql(1.5)];
            $plan = $rewriter->rewrite('select ' . $parameter, $values);

            $this->assertTrue($plan->hasRewrites(), $parameter);
            $this->assertStringStartsWith('select CAST(' . $parameter . ' AS REAL)', $plan->getPreparedSql());
            // SQLite names an unaliased result column after its expression text.
            $this->assertSame(
                $parameter,
                $plan->restoreColumnName(substr($plan->getPreparedSql(), strlen('select ')))
            );
        }
    }

    /**
     * @test
     */
    public function it_rejects_at_and_dollar_prefixed_parameter_keys()
    {
        foreach (['@ratio', '$ratio'] as $key) {
            try {
                (new SqlRewriter())->rewrite('select 1', [$key => \TypeDb\to_sql(1.5)]);
                $this->fail('Expected rejection of ' . $key);
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString($key, $error->getMessage());
            }
        }
    }

    /**
     * @test
     */
    public function an_unchanged_plan_prepares_the_original_sql()
    {
        $plan = QueryPlan::unchanged('select :ratio');

        $this->assertFalse($plan->hasRewrites());
        $this->assertSame('select :ratio', $plan->getPreparedSql());
        $this->assertSame('CAST(:ratio AS REAL)', $plan->restoreColumnName('CAST(:ratio AS REAL)'));
    }
}
