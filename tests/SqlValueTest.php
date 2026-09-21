<?php

declare(strict_types=1);

class SqlValueTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @test
     * @dataProvider strings
     */
    public function it_converts_strings(string $string)
    {
        $sql_value = \TypeDb\to_sql($string);

        $this->assertInstanceof(\TypeDb\SqlValue\SqlString::class, $sql_value);
    }

    /**
     * @test
     * @dataProvider floats
     */
    public function it_converts_floats(float $float)
    {
        $sql_value = \TypeDb\to_sql($float);

        $this->assertInstanceof(\TypeDb\SqlValue\SqlFloat::class, $sql_value);
    }

    /**
     * @test
     * @dataProvider integers
     */
    public function it_converts_integers(int $integer)
    {
        $sql_value = \TypeDb\to_sql($integer);

        $this->assertInstanceof(\TypeDb\SqlValue\SqlInteger::class, $sql_value);
    }

    /**
     * @test
     * @dataProvider strings
     */
    public function it_interprets_strings(string $string)
    {
        $value = \TypeDb\from_sql(new \TypeDb\SqlValue\SqlString($string));

        $this->assertIsString($value);
    }

    /**
     * @test
     * @dataProvider floats
     */
    public function it_interprets_floats(float $float)
    {
        $value = \TypeDb\from_sql(new \TypeDb\SqlValue\SqlFloat($float));

        $this->assertIsFloat($value);
    }

    /**
     * @test
     * @dataProvider integers
     */
    public function it_interprets_integers(int $integer)
    {
        $value = \TypeDb\from_sql(new \TypeDb\SqlValue\SqlInteger($integer));

        $this->assertIsInt($value);
    }

    /**
     * @test
     */
    public function it_unwraps_shipped_sql_value_classes()
    {
        $this->assertSame('bar', \TypeDb\from_sql(new \TypeDb\SqlValue\SqlString('bar')));
        $this->assertSame(1.5, \TypeDb\from_sql(new \TypeDb\SqlValue\SqlFloat(1.5)));
        $this->assertSame(7, \TypeDb\from_sql(new \TypeDb\SqlValue\SqlInteger(7)));
        $this->assertNull(\TypeDb\from_sql(new \TypeDb\SqlValue\SqlNull()));
    }

    /**
     * @test
     */
    public function shipped_sql_values_expose_polymorphic_unwrap_operations()
    {
        $this->assertSame('bar', (new \TypeDb\SqlValue\SqlString('bar'))->unwrap());
        $this->assertSame(1.5, (new \TypeDb\SqlValue\SqlFloat(1.5))->unwrap());
        $this->assertSame(7, (new \TypeDb\SqlValue\SqlInteger(7))->unwrap());
        $this->assertNull((new \TypeDb\SqlValue\SqlNull())->unwrap());
    }

    /**
     * @test
     */
    public function shipped_sql_values_expose_polymorphic_pdo_operations()
    {
        $string = new \TypeDb\SqlValue\SqlString('bar');
        $float = new \TypeDb\SqlValue\SqlFloat(1.5);
        $integer = new \TypeDb\SqlValue\SqlInteger(7);
        $null = new \TypeDb\SqlValue\SqlNull();

        $this->assertSame('bar', $string->toPdoParameter());
        $this->assertSame('1.5', $float->toPdoParameter());
        $this->assertSame(7, $integer->toPdoParameter());
        $this->assertNull($null->toPdoParameter());

        $this->assertSame(\PDO::PARAM_STR, $string->pdoParameterType());
        $this->assertSame(\PDO::PARAM_STR, $float->pdoParameterType());
        $this->assertSame(\PDO::PARAM_INT, $integer->pdoParameterType());
        $this->assertSame(\PDO::PARAM_NULL, $null->pdoParameterType());
    }

    /**
     * @test
     */
    public function helpers_dispatch_through_operations_on_a_custom_sql_value()
    {
        $custom = new class implements \TypeDb\SqlValue\SqlValue {
            public function unwrap(): string
            {
                return 'custom';
            }

            public function toPdoParameter(): string
            {
                return 'custom';
            }

            public function pdoParameterType(): int
            {
                return \PDO::PARAM_STR;
            }
        };

        $this->assertSame('custom', \TypeDb\from_sql($custom));
        $this->assertSame('custom', \TypeDb\bind_sql_value($custom));
        $this->assertSame(\PDO::PARAM_STR, \TypeDb\sql_value_parameter_type($custom));
    }

    /**
     * @test
     */
    public function it_throws_for_unknown_sql_value_implementations()
    {
        $unknown = new class implements \TypeDb\SqlValue\SqlValue {};

        try {
            \TypeDb\from_sql($unknown);

            $this->fail('Expected from_sql() to throw for an unknown SqlValue implementation.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString($unknown::class, $error->getMessage());
        }
    }

    /**
     * @dataProvider
     */
    public function strings(): array
    {
        return array_map(
            fn (int $i) => ['string' => uniqid()],
            range(1, 10)
        );
    }

    /**
     * @dataProvider
     */
    public function floats(): array
    {
        return array_map(
            fn (int $i) => ['float' => random_int(-100_000, 100_000) / random_int(1, 100_000)],
            range(1, 10)
        );
    }

    /**
     * @dataProvider
     */
    public function integers(): array
    {
        return array_map(
            fn (int $i) => ['integer' => random_int(-100_000, 100_000)],
            range(1, 10)
        );
    }
}
