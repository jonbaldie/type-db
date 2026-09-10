<?php

declare(strict_types=1);

class FloatRoundTripTest extends \PHPUnit\Framework\TestCase
{
    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        try {
            $this->pdo = new \PDO('sqlite::memory:');
        } catch (\PDOException $error) {
            $this->markTestSkipped($error->getMessage());
        }
    }

    /**
     * @test
     * @dataProvider highPrecisionFloats
     */
    public function a_high_precision_float_roundtrips_bit_identically_through_a_real_column(float $value)
    {
        $connection = new \TypeDb\Connection($this->pdo);

        \TypeDb\quick_query($connection, 'create table type_db_frt (c real)');
        \TypeDb\quick_query(
            $connection,
            'insert into type_db_frt (c) values (?)',
            [new \TypeDb\SqlValue\SqlFloat($value)]
        );

        $row = \TypeDb\quick_query($connection, 'select c from type_db_frt')[0]['c'];
        $this->assertInstanceOf(\TypeDb\SqlValue\SqlFloat::class, $row);
        $this->assertSame($value, $row->value);
    }

    /**
     * @test
     */
    public function float_roundtrip_is_independent_of_ini_precision()
    {
        $originalPrecision = ini_get('precision');
        ini_set('precision', '10');

        try {
            $connection = new \TypeDb\Connection($this->pdo);

            \TypeDb\quick_query($connection, 'create table type_db_frt_ini (c real)');
            $value = 0.03229924889323672;
            \TypeDb\quick_query(
                $connection,
                'insert into type_db_frt_ini (c) values (?)',
                [new \TypeDb\SqlValue\SqlFloat($value)]
            );

            $row = \TypeDb\quick_query($connection, 'select c from type_db_frt_ini')[0]['c'];
            $this->assertInstanceOf(\TypeDb\SqlValue\SqlFloat::class, $row);
            $this->assertSame($value, $row->value);
        } finally {
            if ($originalPrecision !== false) {
                ini_set('precision', $originalPrecision);
            }
        }
    }

    /**
     * @test
     * @dataProvider nonFiniteInfinities
     */
    public function infinities_roundtrip_as_sql_floats(float $value)
    {
        $connection = new \TypeDb\Connection($this->pdo);

        \TypeDb\quick_query($connection, 'create table type_db_frt_inf (c real)');
        \TypeDb\quick_query(
            $connection,
            'insert into type_db_frt_inf (c) values (?)',
            [new \TypeDb\SqlValue\SqlFloat($value)]
        );

        $row = \TypeDb\quick_query($connection, 'select c from type_db_frt_inf')[0]['c'];
        $this->assertInstanceOf(\TypeDb\SqlValue\SqlFloat::class, $row);
        $this->assertSame($value, $row->value);
    }

    /**
     * @test
     */
    public function float_roundtrip_is_independent_of_a_comma_decimal_locale()
    {
        $originalLocale = setlocale(LC_NUMERIC, '0');
        $this->assertNotFalse($originalLocale);

        $available = setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'fr_FR.UTF-8', 'nl_NL.UTF-8');

        if ($available === false) {
            setlocale(LC_NUMERIC, $originalLocale);
            $this->markTestSkipped('No comma-decimal locale is installed.');
        }

        $this->assertStringContainsString(',', sprintf('%.1f', 1.5));

        try {
            $connection = new \TypeDb\Connection($this->pdo);

            \TypeDb\quick_query($connection, 'create table type_db_frt_locale (c real)');
            \TypeDb\quick_query(
                $connection,
                'insert into type_db_frt_locale (c) values (?)',
                [new \TypeDb\SqlValue\SqlFloat(1.5)]
            );

            $row = \TypeDb\quick_query($connection, 'select c from type_db_frt_locale')[0]['c'];
            $this->assertInstanceOf(\TypeDb\SqlValue\SqlFloat::class, $row);
            $this->assertSame(1.5, $row->value);

            $storage = $this->pdo
                ->query('select typeof(c) from type_db_frt_locale')
                ->fetchColumn();
            $this->assertSame('real', $storage);
        } finally {
            setlocale(LC_NUMERIC, $originalLocale);
        }
    }

    /**
     * @test
     */
    public function nan_is_rejected_instead_of_being_stored_as_text()
    {
        $connection = new \TypeDb\Connection($this->pdo);

        \TypeDb\quick_query($connection, 'create table type_db_frt_nan (c real)');

        try {
            \TypeDb\quick_query(
                $connection,
                'insert into type_db_frt_nan (c) values (?)',
                [new \TypeDb\SqlValue\SqlFloat(NAN)]
            );

            $this->fail('Expected SqlFloat(NAN) to be rejected.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('NAN', strtoupper($error->getMessage()));
        }

        $count = \TypeDb\quick_query(
            $connection,
            'select count(*) as count from type_db_frt_nan'
        )[0]['count'];
        $this->assertSame(0, $count->value);
    }

    /**
     * @return array<string, array{0: float}>
     */
    public function highPrecisionFloats(): array
    {
        return [
            'issue 8 reproducer' => [0.03229924889323672],
            'negative high precision' => [-0.03229924889323672],
            'pi' => [3.141592653589793],
            'small positive' => [1e-15],
            'nextafter 1.0' => [1.0000000000000002],
            'php float min' => [PHP_FLOAT_MIN],
            'php float max' => [PHP_FLOAT_MAX],
            'php float epsilon' => [PHP_FLOAT_EPSILON],
        ];
    }

    /**
     * @return array<string, array{0: float}>
     */
    public function nonFiniteInfinities(): array
    {
        return [
            'positive infinity' => [INF],
            'negative infinity' => [-INF],
        ];
    }
}
