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
}
