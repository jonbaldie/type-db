<?php

declare(strict_types=1);

class QuickQueryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @test
     */
    public function it_writes_and_fetches_data()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        \TypeDb\quick_query(
            $connection,
            'create table if not exists type_db_ft ( id int not null, value varchar not null )',
            []
        );

        \TypeDb\quick_query(
            $connection,
            'insert into type_db_ft (id, value) values (?, ?), (?, ?)',
            [\TypeDb\to_sql(1), \TypeDb\to_sql('bar'), \TypeDb\to_sql(2), \TypeDb\to_sql('baz')]
        );

        \TypeDb\quick_query(
            $connection,
            'insert into type_db_ft (id, value) values (?, ?), (?, ?)',
            [\TypeDb\to_sql(3), \TypeDb\to_sql('jar'), \TypeDb\to_sql(4), \TypeDb\to_sql('jaz')]
        );

        \TypeDb\quick_query(
            $connection,
            'delete from type_db_ft where id in (?, ?)',
            [\TypeDb\to_sql(3), \TypeDb\to_sql(4)]
        );

        $result = \TypeDb\quick_query(
            $connection,
            "select id, value from type_db_ft where id = ? or value = ?",
            [\TypeDb\to_sql(1), \TypeDb\to_sql('baz')]
        );

        $expected = [
            [
                'id' => new \TypeDb\SqlValue\SqlInteger(1),
                'value' => new \TypeDb\SqlValue\SqlString('bar'),
            ],
            [
                'id' => new \TypeDb\SqlValue\SqlInteger(2),
                'value' => new \TypeDb\SqlValue\SqlString('baz'),
            ],
        ];

        $this->assertEquals($expected, $result);
    }

    /**
     * @test
     */
    public function it_handles_nulls_from_sql()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        \TypeDb\quick_query(
            $connection,
            'create table if not exists type_db_null ( id int not null, value varchar null )',
            []
        );

        \TypeDb\quick_query(
            $connection,
            'insert into type_db_null (id, value) values (?, ?), (?, ?)',
            [\TypeDb\to_sql(1), \TypeDb\to_sql('bar'), \TypeDb\to_sql(2), \TypeDb\to_sql(null)]
        );

        $result = \TypeDb\quick_query(
            $connection,
            "select id, value from type_db_null where id = ? or value is null",
            [\TypeDb\to_sql(1)]
        );

        $expected = [
            [
                'id' => new \TypeDb\SqlValue\SqlInteger(1),
                'value' => new \TypeDb\SqlValue\SqlString('bar'),
            ],
            [
                'id' => new \TypeDb\SqlValue\SqlInteger(2),
                'value' => new \TypeDb\SqlValue\SqlNull(),
            ],
        ];

        $this->assertEquals($expected, $result);
    }

    /**
     * @test
     */
    public function it_returns_non_associative_data()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        $result = \TypeDb\quick_query(
            $connection,
            "select 1 as `0`, 'baz' as `1`",
        );

        $expected = [
            [
                0 => new \TypeDb\SqlValue\SqlInteger(1),
                1 => new \TypeDb\SqlValue\SqlString('baz'),
            ]
        ];

        $this->assertEquals($expected, $result);
    }

    /**
     * @test
     */
    public function it_returns_associative_data()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        $result = \TypeDb\quick_query(
            $connection,
            "select 1 as id, 'baz' as value",
        );

        $expected = [
            [
                'id' => new \TypeDb\SqlValue\SqlInteger(1),
                'value' => new \TypeDb\SqlValue\SqlString('baz'),
            ]
        ];

        $this->assertEquals($expected, $result);
    }
}
