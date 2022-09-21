<?php

declare(strict_types=1);

class FeatureTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @test
     */
    public function it_fetches_existing_data()
    {
        $pdo = new \PDO('sqlite::memory:');

        $pdo->prepare('create table if not exists type_db_ft ( id int not null, value varchar not null )')->execute();

        $pdo->prepare('insert into type_db_ft (id, value) values (?, ?), (?, ?)')->execute([1, 'bar', 2, 'baz']);

        $result = \TypeDb\quick_query(
            new \TypeDb\Connection($pdo),
            "select * from type_db_ft where id = ? or value = ?",
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
