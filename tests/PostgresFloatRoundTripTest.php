<?php

declare(strict_types=1);

class PostgresFloatRoundTripTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @test
     * @dataProvider floats
     */
    public function floats_roundtrip_through_postgres(float $value): void
    {
        $dsn = getenv('TYPE_DB_POSTGRES_DSN');
        if ($dsn === false) {
            $this->markTestSkipped('Set TYPE_DB_POSTGRES_DSN to run PostgreSQL tests.');
        }

        $pdo = new \PDO($dsn, getenv('TYPE_DB_POSTGRES_USER') ?: null, getenv('TYPE_DB_POSTGRES_PASSWORD') ?: null);
        $connection = new \TypeDb\Connection($pdo);
        $result = $connection->quickQuery('select cast(? as double precision) as v', [\TypeDb\to_sql($value)])[0]['v'];

        $this->assertInstanceOf(\TypeDb\SqlValue\SqlFloat::class, $result);
        if (is_nan($value)) {
            $this->assertTrue(is_nan($result->value));
        } else {
            $this->assertSame($value, $result->value);
        }
    }

    /** @test */
    public function custom_float_binding_is_preserved(): void
    {
        $dsn = getenv('TYPE_DB_POSTGRES_DSN');
        if ($dsn === false) {
            $this->markTestSkipped('Set TYPE_DB_POSTGRES_DSN to run PostgreSQL tests.');
        }

        $pdo = new \PDO($dsn, getenv('TYPE_DB_POSTGRES_USER') ?: null, getenv('TYPE_DB_POSTGRES_PASSWORD') ?: null);
        $connection = new \TypeDb\Connection($pdo);
        $value = new class(INF) extends \TypeDb\SqlValue\SqlFloat {
            public function toPdoParameter(): string
            {
                return '42';
            }
        };

        $result = $connection->quickQuery('select cast(? as double precision) as v', [$value])[0]['v'];
        $this->assertSame(42.0, $result->value);
    }

    /** @return array<string, array{0: float}> */
    public function floats(): array
    {
        return [
            'positive infinity' => [INF],
            'negative infinity' => [-INF],
            'NaN' => [NAN],
            'finite' => [0.03229924889323672],
        ];
    }
}
