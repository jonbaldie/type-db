<?php

declare(strict_types=1);

use TypeDb\RowHydrator;
use TypeDb\SqlValue\SqlFloat;
use TypeDb\SqlValue\SqlInteger;
use TypeDb\SqlValue\SqlNull;
use TypeDb\SqlValue\SqlString;

class RowHydratorTest extends \PHPUnit\Framework\TestCase
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

    private function execute(string $sql): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $this->assertInstanceOf(\PDOStatement::class, $statement);
        $this->assertTrue($statement->execute());

        return $statement;
    }

    private static function identity(): \Closure
    {
        return static fn (string $name): string => $name;
    }

    /**
     * @test
     */
    public function it_hydrates_every_row_into_sql_values()
    {
        $statement = $this->execute(
            "select 1 as i, 2.5 as f, 'x' as s, null as n union all select 2, 3.5, 'y', null"
        );

        $this->assertEquals(
            [
                ['i' => new SqlInteger(1), 'f' => new SqlFloat(2.5), 's' => new SqlString('x'), 'n' => new SqlNull()],
                ['i' => new SqlInteger(2), 'f' => new SqlFloat(3.5), 's' => new SqlString('y'), 'n' => new SqlNull()],
            ],
            (new RowHydrator($statement, self::identity()))->fetchAll()
        );
    }

    /**
     * @test
     */
    public function it_returns_no_rows_for_an_empty_result_set()
    {
        $statement = $this->execute('select 1 as id where 0');

        $this->assertSame([], (new RowHydrator($statement, self::identity()))->fetchAll());
    }

    /**
     * @test
     */
    public function it_applies_the_column_name_normalizer_to_result_keys()
    {
        $statement = $this->execute('select 1 as raw_a, 2 as raw_b');

        $rows = (new RowHydrator(
            $statement,
            static fn (string $name): string => str_replace('raw_', '', $name),
        ))->fetchAll();

        $this->assertEquals([['a' => new SqlInteger(1), 'b' => new SqlInteger(2)]], $rows);
    }

    /**
     * @test
     */
    public function it_rejects_column_names_that_collide_after_normalization()
    {
        $statement = $this->execute('select 1 as x_a, 2 as y_a, 3 as b, 4 as b');

        try {
            new RowHydrator(
                $statement,
                static fn (string $name): string => preg_replace('/^[xy]_/', '', $name) ?? $name,
                'select original',
            );

            $this->fail('Expected RowHydrator to reject duplicate column names.');
        } catch (\RuntimeException $error) {
            $this->assertSame(
                'Failed to interpret query [HY000/unknown]: Duplicate column names in result set: a, b. SQL: select original',
                $error->getMessage()
            );
        }
    }

    /**
     * @test
     */
    public function it_reports_the_statement_sql_when_no_sql_is_given()
    {
        $statement = $this->execute('select 1 as id, 2 as id');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate column names in result set: id. SQL: select 1 as id, 2 as id');

        new RowHydrator($statement, self::identity());
    }

    /**
     * @test
     */
    public function it_reads_each_rows_own_sqlite_storage_class()
    {
        $this->pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $statement = $this->execute("select 7 as v union all select 'abc' union all select 1.5");

        $this->assertEquals(
            [
                ['v' => new SqlInteger(7)],
                ['v' => new SqlString('abc')],
                ['v' => new SqlFloat(1.5)],
            ],
            (new RowHydrator($statement, self::identity()))->fetchAll()
        );
    }

    /**
     * @test
     */
    public function it_resolves_stringified_infinities()
    {
        $this->pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $statement = $this->execute('select 9e999 as p, -9e999 as n, 1.5 as f');

        $row = (new RowHydrator($statement, self::identity()))->fetchAll()[0];

        $this->assertSame(INF, $row['p']->value);
        $this->assertSame(-INF, $row['n']->value);
        $this->assertSame(1.5, $row['f']->value);
    }

    /**
     * @test
     */
    public function it_types_stringified_table_columns()
    {
        $this->pdo->exec('create table type_db_rh_decl (i bigint, f double precision, t varchar(10))');
        $this->pdo->exec("insert into type_db_rh_decl values (5, 2.5, 'x')");
        $this->pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $statement = $this->execute('select i, f, t from type_db_rh_decl');

        $this->assertEquals(
            [['i' => new SqlInteger(5), 'f' => new SqlFloat(2.5), 't' => new SqlString('x')]],
            (new RowHydrator($statement, self::identity()))->fetchAll()
        );
    }

    /**
     * @test
     */
    public function it_reads_column_metadata_only_for_cells_that_need_a_kind()
    {
        $this->pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [MetaCountingStatement::class, []]);
        $statement = $this->execute(
            'select 1 as a, 2.5 as b, null as c union all select 2, 3.5, null union all select 3, 4.5, null'
        );
        $this->assertInstanceOf(MetaCountingStatement::class, $statement);

        (new RowHydrator($statement, self::identity()))->fetchAll();

        $this->assertSame(3, $statement->metaCalls);
    }
}

class MetaCountingStatement extends \PDOStatement
{
    public int $metaCalls = 0;

    protected function __construct()
    {
    }

    public function getColumnMeta(int $column): array|false
    {
        $this->metaCalls++;

        return parent::getColumnMeta($column);
    }
}
