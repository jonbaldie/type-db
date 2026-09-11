<?php

declare(strict_types=1);

class QuickQueryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @test
     */
    public function the_connection_method_defaults_sql_values()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        $this->assertEquals(
            [['id' => new \TypeDb\SqlValue\SqlInteger(1)]],
            $connection->quickQuery('select 1 as id')
        );
    }

    /**
     * @test
     */
    public function the_connection_method_accepts_explicit_sql_values()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        $this->assertEquals(
            [['id' => new \TypeDb\SqlValue\SqlString('bar')]],
            $connection->quickQuery('select ? as id', [\TypeDb\to_sql('bar')])
        );
    }

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

    /**
     * @test
     */
    public function it_preserves_sqlite_column_names_for_unaliased_selects()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        $result = \TypeDb\quick_query(
            $connection,
            'select 1'
        );

        $expected = [
            [
                1 => new \TypeDb\SqlValue\SqlInteger(1),
            ],
        ];

        $this->assertEquals($expected, $result);
    }

    /**
     * @test
     */
    public function it_maps_stringified_numeric_columns_from_sql_types()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);

        $connection = new \TypeDb\Connection($pdo);

        \TypeDb\quick_query(
            $connection,
            'create table if not exists type_db_stringify ( id integer not null, amount real not null, label varchar not null )',
            []
        );

        \TypeDb\quick_query(
            $connection,
            'insert into type_db_stringify (id, amount, label) values (?, ?, ?)',
            [\TypeDb\to_sql(1), \TypeDb\to_sql(1.5), \TypeDb\to_sql('1')]
        );

        $result = \TypeDb\quick_query(
            $connection,
            'select id, amount, label from type_db_stringify',
            []
        );

        $expected = [
            [
                'id' => new \TypeDb\SqlValue\SqlInteger(1),
                'amount' => new \TypeDb\SqlValue\SqlFloat(1.5),
                'label' => new \TypeDb\SqlValue\SqlString('1'),
            ],
        ];

        $this->assertEquals($expected, $result);
    }

    /**
     * @test
     */
    public function it_maps_numeric_columns_from_sql_types_without_stringify()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        \TypeDb\quick_query(
            $connection,
            'create table if not exists type_db_types ( id integer not null, amount real not null, label varchar not null )',
            []
        );

        \TypeDb\quick_query(
            $connection,
            'insert into type_db_types (id, amount, label) values (?, ?, ?)',
            [\TypeDb\to_sql(1), \TypeDb\to_sql(1.5), \TypeDb\to_sql('1')]
        );

        $result = \TypeDb\quick_query(
            $connection,
            'select id, amount, label from type_db_types',
            []
        );

        $expected = [
            [
                'id' => new \TypeDb\SqlValue\SqlInteger(1),
                'amount' => new \TypeDb\SqlValue\SqlFloat(1.5),
                'label' => new \TypeDb\SqlValue\SqlString('1'),
            ],
        ];

        $this->assertEquals($expected, $result);
    }

    /**
     * @test
     */
    public function it_preserves_runtime_sqlite_value_kinds_per_cell()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        \TypeDb\quick_query(
            $connection,
            'create table type_db_runtime_kinds ( integer_value integer, real_value real )'
        );

        $pdo->exec("insert into type_db_runtime_kinds (integer_value, real_value) values (1.5, 'abc')");

        $this->assertEquals(
            [
                [
                    'integer_value' => new \TypeDb\SqlValue\SqlFloat(1.5),
                    'real_value' => new \TypeDb\SqlValue\SqlString('abc'),
                ],
            ],
            \TypeDb\quick_query($connection, 'select integer_value, real_value from type_db_runtime_kinds')
        );

        $this->assertEquals(
            [
                ['value' => new \TypeDb\SqlValue\SqlInteger(1)],
                ['value' => new \TypeDb\SqlValue\SqlFloat(1.5)],
            ],
            \TypeDb\quick_query($connection, 'select 1 as value union all select 1.5')
        );
    }

    /**
     * @test
     */
    public function it_preserves_numeric_parameter_storage_classes_without_column_affinity()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        \TypeDb\quick_query(
            $connection,
            'create table type_db_numeric_parameters (integer_value, real_value blob)'
        );

        \TypeDb\quick_query(
            $connection,
            'insert into type_db_numeric_parameters (integer_value, real_value) values (?, ?)',
            [
                new \TypeDb\SqlValue\SqlInteger(7),
                new \TypeDb\SqlValue\SqlFloat(1.5),
            ]
        );

        $this->assertSame(
            ['integer', 'real'],
            $pdo
                ->query('select typeof(integer_value), typeof(real_value) from type_db_numeric_parameters')
                ->fetch(\PDO::FETCH_NUM)
        );

        $this->assertEquals(
            [
                [
                    'integer_value' => new \TypeDb\SqlValue\SqlInteger(7),
                    'real_value' => new \TypeDb\SqlValue\SqlFloat(1.5),
                ],
            ],
            \TypeDb\quick_query(
                $connection,
                'select integer_value, real_value from type_db_numeric_parameters'
            )
        );
    }

    /**
     * @test
     */
    public function it_preserves_parameter_types_when_named_values_precede_positional_values()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        $namedFloatBeforePositionalString = \TypeDb\quick_query(
            $connection,
            'select ? as pos, :name as named',
            [
                'name' => \TypeDb\to_sql(1.5),
                0 => \TypeDb\to_sql('test'),
            ]
        );

        $namedStringBeforePositionalFloat = \TypeDb\quick_query(
            $connection,
            'select ? as pos, :name as named',
            [
                'name' => \TypeDb\to_sql('test'),
                0 => \TypeDb\to_sql(1.5),
            ]
        );

        $this->assertEquals(
            [
                [
                    'pos' => new \TypeDb\SqlValue\SqlString('test'),
                    'named' => new \TypeDb\SqlValue\SqlFloat(1.5),
                ],
            ],
            $namedFloatBeforePositionalString
        );

        $this->assertEquals(
            [
                [
                    'pos' => new \TypeDb\SqlValue\SqlFloat(1.5),
                    'named' => new \TypeDb\SqlValue\SqlString('test'),
                ],
            ],
            $namedStringBeforePositionalFloat
        );
    }

    /**
     * @test
     */
    public function it_casts_float_values_bound_to_digit_prefixed_named_parameters()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        $this->assertEquals(
            [['res' => new \TypeDb\SqlValue\SqlFloat(1.5)]],
            \TypeDb\quick_query(
                $connection,
                'select :1a as res',
                [':1a' => \TypeDb\to_sql(1.5)]
            )
        );

        $this->assertEquals(
            [['res' => new \TypeDb\SqlValue\SqlFloat(2.5)]],
            \TypeDb\quick_query(
                $connection,
                'select :1 as res',
                [':1' => \TypeDb\to_sql(2.5)]
            )
        );

        $this->assertEquals(
            [['res' => new \TypeDb\SqlValue\SqlString('test')]],
            \TypeDb\quick_query(
                $connection,
                'select :1s as res',
                [':1s' => \TypeDb\to_sql('test')]
            )
        );
    }

    /**
     * @test
     */
    public function it_maps_nulls_when_fetches_are_stringified()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);

        $connection = new \TypeDb\Connection($pdo);

        \TypeDb\quick_query(
            $connection,
            'create table if not exists type_db_stringify_null ( id integer not null, amount real null, label varchar null )',
            []
        );

        \TypeDb\quick_query(
            $connection,
            'insert into type_db_stringify_null (id, amount, label) values (?, ?, ?)',
            [\TypeDb\to_sql(1), \TypeDb\to_sql(null), \TypeDb\to_sql(null)]
        );

        $result = \TypeDb\quick_query(
            $connection,
            'select id, amount, label from type_db_stringify_null',
            []
        );

        $expected = [
            [
                'id' => new \TypeDb\SqlValue\SqlInteger(1),
                'amount' => new \TypeDb\SqlValue\SqlNull(),
                'label' => new \TypeDb\SqlValue\SqlNull(),
            ],
        ];

        $this->assertEquals($expected, $result);
    }

    /**
     * @test
     */
    public function it_maps_stringified_integer_after_a_null_row()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);

        $connection = new \TypeDb\Connection($pdo);

        \TypeDb\quick_query(
            $connection,
            'create table if not exists type_db_stringify_null_first ( id integer )',
            []
        );

        \TypeDb\quick_query(
            $connection,
            'insert into type_db_stringify_null_first (id) values (?), (?)',
            [\TypeDb\to_sql(null), \TypeDb\to_sql(1)]
        );

        $result = \TypeDb\quick_query(
            $connection,
            'select id from type_db_stringify_null_first order by id is not null',
            []
        );

        $expected = [
            ['id' => new \TypeDb\SqlValue\SqlNull()],
            ['id' => new \TypeDb\SqlValue\SqlInteger(1)],
        ];

        $this->assertEquals($expected, $result);
    }

    /**
     * @test
     */
    public function it_throws_when_prepare_returns_false_in_silent_mode()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);

        $connection = new \TypeDb\Connection($pdo);

        try {
            \TypeDb\quick_query(
                $connection,
                'select * from'
            );

            $this->fail('Expected quick_query() to throw when prepare() returns false.');
        } catch (\RuntimeException $error) {
            $message = $error->getMessage();

            $this->assertStringContainsString('Failed to prepare query [', $message);
            $this->assertStringContainsString(']: ', $message);
            $this->assertStringContainsString('. SQL: select * from', $message);
        }
    }

    /**
     * @test
     */
    public function it_throws_when_execute_returns_false_in_silent_mode()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);

        $connection = new \TypeDb\Connection($pdo);

        \TypeDb\quick_query(
            $connection,
            'create table if not exists type_db_exec_fail ( id int not null, value varchar not null )',
            []
        );

        try {
            \TypeDb\quick_query(
                $connection,
                'insert into type_db_exec_fail (id, value) values (?, ?)',
                [\TypeDb\to_sql(1), \TypeDb\to_sql(null)]
            );

            $this->fail('Expected quick_query() to throw when execute() returns false.');
        } catch (\RuntimeException $error) {
            $message = $error->getMessage();

            $this->assertStringContainsString('Failed to execute query [23000/19]: ', $message);
            $this->assertStringContainsString('NOT NULL constraint failed: type_db_exec_fail.value', $message);
            $this->assertStringContainsString('. SQL: insert into type_db_exec_fail (id, value) values (?, ?)', $message);
        }
    }

    /**
     * @test
     */
    public function it_throws_when_selected_columns_share_a_name()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        try {
            \TypeDb\quick_query(
                $connection,
                'select 1 as id, 2 as id'
            );

            $this->fail('Expected quick_query() to throw when selected columns share a name.');
        } catch (\RuntimeException $error) {
            $message = $error->getMessage();

            $this->assertStringContainsString('Failed to interpret query [', $message);
            $this->assertStringContainsString(']: ', $message);
            $this->assertStringContainsString('id', $message);
            $this->assertStringContainsString('. SQL: select 1 as id, 2 as id', $message);
        }
    }

    /**
     * @test
     */
    public function it_throws_when_joined_columns_share_names()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        \TypeDb\quick_query($connection, 'create table t (id integer, value text)');
        \TypeDb\quick_query($connection, 'create table u (id integer, value text)');
        \TypeDb\quick_query($connection, 'insert into t (id, value) values (?, ?)', [\TypeDb\to_sql(1), \TypeDb\to_sql('a')]);
        \TypeDb\quick_query($connection, 'insert into u (id, value) values (?, ?)', [\TypeDb\to_sql(1), \TypeDb\to_sql('b')]);

        try {
            \TypeDb\quick_query(
                $connection,
                'select t.id, u.id, t.value, u.value from t join u on t.id = u.id'
            );

            $this->fail('Expected quick_query() to throw when joined columns share names.');
        } catch (\RuntimeException $error) {
            $message = $error->getMessage();

            $this->assertStringContainsString('Failed to interpret query [', $message);
            $this->assertStringContainsString(']: ', $message);
            $this->assertStringContainsString('id', $message);
            $this->assertStringContainsString('value', $message);
            $this->assertStringContainsString('. SQL: select t.id, u.id, t.value, u.value from t join u on t.id = u.id', $message);
        }
    }

    /**
     * @test
     */
    public function it_throws_and_writes_nothing_when_binding_unknown_sql_value()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);
        $unknown = new class implements \TypeDb\SqlValue\SqlValue {};

        \TypeDb\quick_query(
            $connection,
            'create table if not exists type_db_unknown ( id int not null, value varchar null )',
            []
        );

        try {
            \TypeDb\quick_query(
                $connection,
                'insert into type_db_unknown (id, value) values (?, ?)',
                [\TypeDb\to_sql(1), $unknown]
            );

            $this->fail('Expected quick_query() to throw when binding an unknown SqlValue.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString($unknown::class, $error->getMessage());
        }

        $this->assertEquals(
            [],
            \TypeDb\quick_query(
                $connection,
                'select id, value from type_db_unknown',
                []
            )
        );
    }

    /**
     * @test
     */
    public function it_returns_distinct_column_names()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        $this->assertEquals(
            [
                [
                    'id' => new \TypeDb\SqlValue\SqlInteger(1),
                    'value' => new \TypeDb\SqlValue\SqlInteger(2),
                ],
            ],
            \TypeDb\quick_query($connection, 'select 1 as id, 2 as value')
        );
    }

    /**
     * @test
     */
    public function it_casts_anonymous_float_placeholders_after_numbered_placeholders()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        $this->assertEquals(
            [
                [
                    'numbered' => new \TypeDb\SqlValue\SqlInteger(2),
                    'anonymous' => new \TypeDb\SqlValue\SqlFloat(3.5),
                ],
            ],
            \TypeDb\quick_query(
                $connection,
                'select ?2 as numbered, ? as anonymous',
                [\TypeDb\to_sql(1), \TypeDb\to_sql(2), \TypeDb\to_sql(3.5)]
            )
        );
    }

    /**
     * @test
     */
    public function it_casts_anonymous_float_placeholders_before_numbered_placeholders()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        $this->assertEquals(
            [
                [
                    'anonymous' => new \TypeDb\SqlValue\SqlFloat(3.5),
                    'numbered' => new \TypeDb\SqlValue\SqlInteger(1),
                ],
            ],
            \TypeDb\quick_query(
                $connection,
                'select ? as anonymous, ?2 as numbered',
                [\TypeDb\to_sql(3.5), \TypeDb\to_sql(1)]
            )
        );
    }

    /**
     * @test
     */
    public function it_numbers_anonymous_placeholders_after_the_largest_numbered_placeholder()
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $connection = new \TypeDb\Connection($pdo);

        $this->assertEquals(
            [
                [
                    'third' => new \TypeDb\SqlValue\SqlInteger(3),
                    'first' => new \TypeDb\SqlValue\SqlInteger(1),
                    'anonymous' => new \TypeDb\SqlValue\SqlFloat(4.5),
                ],
            ],
            \TypeDb\quick_query(
                $connection,
                'select ?3 as third, ?1 as first, ? as anonymous',
                [
                    \TypeDb\to_sql(1),
                    \TypeDb\to_sql(2),
                    \TypeDb\to_sql(3),
                    \TypeDb\to_sql(4.5),
                ]
            )
        );
    }

    /**
     * @test
     * @dataProvider stringify_fetch_modes
     */
    public function it_maps_each_row_of_a_mixed_type_column_by_its_own_type(bool $stringify)
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, $stringify);

        $result = \TypeDb\quick_query(
            new \TypeDb\Connection($pdo),
            "select 1 as val union all select 'hello' union all select 2.5 union all select 'world'",
        );

        $this->assertEquals(
            [
                ['val' => new \TypeDb\SqlValue\SqlInteger(1)],
                ['val' => new \TypeDb\SqlValue\SqlString('hello')],
                ['val' => new \TypeDb\SqlValue\SqlFloat(2.5)],
                ['val' => new \TypeDb\SqlValue\SqlString('world')],
            ],
            $result
        );
    }

    /**
     * @test
     * @dataProvider stringify_fetch_modes
     */
    public function it_maps_untyped_column_rows_independently_of_row_order(bool $stringify)
    {
        try {
            $pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }

        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, $stringify);
        $connection = new \TypeDb\Connection($pdo);

        \TypeDb\quick_query($connection, 'create table items (val)');
        \TypeDb\quick_query(
            $connection,
            'insert into items (val) values (?), (?)',
            [\TypeDb\to_sql(42), \TypeDb\to_sql('hello')],
        );

        $forward = \TypeDb\quick_query($connection, 'select val from items order by rowid asc');
        $backward = \TypeDb\quick_query($connection, 'select val from items order by rowid desc');

        $this->assertEquals(
            [
                ['val' => new \TypeDb\SqlValue\SqlInteger(42)],
                ['val' => new \TypeDb\SqlValue\SqlString('hello')],
            ],
            $forward
        );
        $this->assertEquals(array_reverse($forward), $backward);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function stringify_fetch_modes(): array
    {
        return [
            'native fetches' => [false],
            'stringified fetches' => [true],
        ];
    }
}
