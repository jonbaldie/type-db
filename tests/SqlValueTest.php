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
