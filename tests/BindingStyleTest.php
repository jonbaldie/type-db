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
        ];
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
