<?php

declare(strict_types=1);

class BindingStyleTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @dataProvider mismatchedBindings
     */
    public function test_it_rejects_mismatched_bindings_before_prepare(string $sql, array $values): void
    {
        $connection = new \TypeDb\Connection(new \PDO('sqlite::memory:'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('binding');
        \TypeDb\quick_query($connection, $sql, $values);
    }

    public function mismatchedBindings(): array
    {
        return [
            ['select :a', [\TypeDb\to_sql(1.5)]],
            ['select @a', [\TypeDb\to_sql(1.5)]],
            ['select $a', [\TypeDb\to_sql(1.5)]],
            ['select :a, ?', [\TypeDb\to_sql(1.5), \TypeDb\to_sql('test')]],
            ['select ?, :a', [\TypeDb\to_sql('test'), 'a' => \TypeDb\to_sql(1.5)]],
            ['select ?', ['a' => \TypeDb\to_sql(1.5)]],
            ['select ?1', ['a' => \TypeDb\to_sql(1.5)]],
            ['select ?1, :a', []],
            ['not valid sql :a', [\TypeDb\to_sql(1.5)]],
        ];
    }

    public function test_it_reports_excess_values(): void
    {
        $connection = new \TypeDb\Connection(new \PDO('sqlite::memory:'));
        $this->expectException(\PDOException::class);
        \TypeDb\quick_query($connection, 'select ?', [\TypeDb\to_sql(1), \TypeDb\to_sql(2)]);
    }

    public function test_it_allows_dollars_in_unquoted_identifiers(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('create table prices (price$usd real)');
        $pdo->exec('insert into prices values (2.5)');
        $connection = new \TypeDb\Connection($pdo);
        $this->assertEquals(
            [['price$usd' => new \TypeDb\SqlValue\SqlFloat(2.5)]],
            \TypeDb\quick_query($connection, 'select price$usd from prices where price$usd > ?', [\TypeDb\to_sql(1.5)])
        );
    }

    public function test_it_preserves_float_values_and_ignores_quoted_placeholders(): void
    {
        $connection = new \TypeDb\Connection(new \PDO('sqlite::memory:'));
        $this->assertEquals(
            [['literal' => new \TypeDb\SqlValue\SqlString(':a ?'), 'value' => new \TypeDb\SqlValue\SqlFloat(1.5)]],
            \TypeDb\quick_query($connection, "select ':a ?' as literal, ? as value -- :ignored\n", [\TypeDb\to_sql(1.5)])
        );
        $this->assertEquals(
            [['value' => new \TypeDb\SqlValue\SqlFloat(1.5)]],
            \TypeDb\quick_query($connection, 'select :a as value /* ? */', ['a' => \TypeDb\to_sql(1.5)])
        );
    }
}
