<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TypeDb\Connection;
use TypeDb\SqlValue\SqlFloat;
use TypeDb\SqlValue\SqlInteger;
use TypeDb\SqlValue\SqlString;

class RestorationAcceptanceTest extends TestCase
{
    public function testLongFloatParameterWithMarkerBearingAliases(): void
    {
        $long = ':' . str_repeat('x', 1000);
        $connection = new Connection(new PDO('sqlite::memory:'));
        $this->assertEquals([[
            'plain' => new SqlInteger(1),
            ':p' => new SqlInteger(2),
            'CAST(:q AS REAL) /* type-db:float-rewrite-1 */' => new SqlInteger(3),
        ]], $connection->quickQuery(
            'SELECT 1 AS plain, 2 AS "CAST(:p AS REAL) /* type-db:float-rewrite-1 */", '
            . '3 AS "CAST(:q AS REAL) /* type-db:float-rewrite-1 */" WHERE 1 IN (:p, ' . $long . ')',
            [':p' => new SqlFloat(1.0), $long => new SqlFloat(1.0)]
        ));
    }

    /**
     * @dataProvider caseModes
     */
    public function testFloatExpressionsRespectPdoCase(int $mode, array $expected): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_CASE, $mode);

        $this->assertEquals($expected, (new Connection($pdo))->quickQuery(
            'select :Value, :Value as Amount, :Value + :Other',
            [':Value' => new SqlFloat(1.5), ':Other' => new SqlFloat(2.5)]
        ));
    }

    public static function caseModes(): array
    {
        return [
            'natural' => [PDO::CASE_NATURAL, [[
                ':Value' => new SqlFloat(1.5), 'Amount' => new SqlFloat(1.5), ':Value + :Other' => new SqlFloat(4.0),
            ]]],
            'lower' => [PDO::CASE_LOWER, [[
                ':value' => new SqlFloat(1.5), 'amount' => new SqlFloat(1.5), ':value + :other' => new SqlFloat(4.0),
            ]]],
            'upper' => [PDO::CASE_UPPER, [[
                ':VALUE' => new SqlFloat(1.5), 'AMOUNT' => new SqlFloat(1.5), ':VALUE + :OTHER' => new SqlFloat(4.0),
            ]]],
        ];
    }

    public function testMarkerShapedAliasesAndLiterals(): void
    {
        $connection = new Connection(new PDO('sqlite::memory:'));

        $this->assertEquals([[
            ':p' => new SqlInteger(1),
            'CAST(:q AS REAL) /* type-db:float-rewrite-1 */' => new SqlInteger(2),
            'CAST(:p AS REAL) /* type-db:float-rewrite-99 */' => new SqlInteger(3),
            'literal' => new SqlString('CAST(:p AS REAL) /* type-db:float-rewrite-1 */'),
            'value' => new SqlFloat(1.5),
        ]], $connection->quickQuery(
            'select 1 as "CAST(:p AS REAL) /* type-db:float-rewrite-1 */", '
            . '2 as "CAST(:q AS REAL) /* type-db:float-rewrite-1 */", '
            . '3 as "CAST(:p AS REAL) /* type-db:float-rewrite-99 */", '
            . "'CAST(:p AS REAL) /* type-db:float-rewrite-1 */' as literal, :p as value",
            [':p' => new SqlFloat(1.5)]
        ));
    }

    public function testNestedMarkerAliasesFollowRewriteOrder(): void
    {
        $connection = new Connection(new PDO('sqlite::memory:'));

        $this->assertEquals([[
            ':p' => new SqlInteger(1),
            'CAST(:p AS REAL) /* type-db:float-rewrite-1 */' => new SqlInteger(2),
            'first' => new SqlFloat(1.5),
            'second' => new SqlFloat(1.5),
        ]], $connection->quickQuery(
            'select 1 as "CAST(CAST(:p AS REAL) /* type-db:float-rewrite-1 */ AS REAL) /* type-db:float-rewrite-2 */", '
            . '2 as "CAST(CAST(:p AS REAL) /* type-db:float-rewrite-2 */ AS REAL) /* type-db:float-rewrite-1 */", '
            . ':p as first, :p as second',
            [':p' => new SqlFloat(1.5)]
        ));
    }

    public function testUnaliasedLiteralNamesRestoreWithoutChangingLiteralValues(): void
    {
        $connection = new Connection(new PDO('sqlite::memory:'));

        $this->assertEquals([[
            "':p'" => new SqlString('CAST(:p AS REAL) /* type-db:float-rewrite-1 */'),
            "'CAST(:q AS REAL) /* type-db:float-rewrite-1 */'" => new SqlString('CAST(:q AS REAL) /* type-db:float-rewrite-1 */'),
            'value' => new SqlFloat(1.5),
        ]], $connection->quickQuery(
            "select 'CAST(:p AS REAL) /* type-db:float-rewrite-1 */', "
            . "'CAST(:q AS REAL) /* type-db:float-rewrite-1 */', :p as value",
            [':p' => new SqlFloat(1.5)]
        ));
    }

    /**
     * @dataProvider duplicateQueries
     */
    public function testDuplicateRestoredNamesFailEvenWithoutRows(string $sql, string $message): void
    {
        $connection = new Connection(new PDO('sqlite::memory:'));
        try {
            $connection->quickQuery($sql, [new SqlFloat(1.5), new SqlFloat(2.5)]);
            $this->fail('Expected duplicate column names to fail.');
        } catch (RuntimeException $error) {
            $this->assertSame($message, $error->getMessage());
        }
    }

    public static function duplicateQueries(): array
    {
        return [
            'row' => [
                'select ?, ?',
                'Failed to interpret query [HY000/unknown]: Duplicate column names in result set: ?. SQL: select ?, ?',
            ],
            'empty' => [
                'select ?, ? where 0',
                'Failed to interpret query [HY000/unknown]: Duplicate column names in result set: ?. SQL: select ?, ? where 0',
            ],
        ];
    }

    public function testMarkerShapedNamesWithoutFloatRewritesRemainUnchanged(): void
    {
        $connection = new Connection(new PDO('sqlite::memory:'));

        $this->assertEquals([[
            'CAST(:p AS REAL) /* type-db:float-rewrite-1 */' => new SqlInteger(7),
            'literal' => new SqlString('CAST(:p AS REAL) /* type-db:float-rewrite-1 */'),
        ]], $connection->quickQuery(
            'select ? as "CAST(:p AS REAL) /* type-db:float-rewrite-1 */", '
            . "'CAST(:p AS REAL) /* type-db:float-rewrite-1 */' as literal",
            [new SqlInteger(7)]
        ));
    }
}
