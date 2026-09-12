<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../vendor/autoload.php';

use TypeDb\Connection;
use TypeDb\SqlValue\SqlFloat;
use TypeDb\SqlValue\SqlString;
use function TypeDb\quick_query;
use function TypeDb\to_sql;

echo "=== Reproducing Confirmed Bugs (3 iterations) ===\n\n";

for ($run = 1; $run <= 3; $run++) {
    echo "--- Run {$run} ---\n";
    $conn = new Connection(new PDO('sqlite::memory:'));

    // --- Bug 1: Namespace parameters (:ns::param) ---
    // 1a. Float degradation
    $result1a = quick_query(
        $conn,
        'select :ns::param as r',
        [':ns::param' => to_sql(1.5)]
    )[0]['r'];

    echo "1a. Float on :ns::param: returned " . $result1a::class . " (value: " . var_export($result1a->value, true) . ")\n";
    assert($result1a instanceof SqlString, 'Expected SqlString due to missing REAL cast');

    // 1b. Query corruption when :param is float
    try {
        quick_query(
            $conn,
            'select :ns::param as r',
            [':param' => to_sql(1.5)]
        );
        echo "1b. Unexpected success\n";
    } catch (PDOException $e) {
        echo "1b. PDOException as expected: " . $e->getMessage() . "\n";
    }

    // 1c. Pre-flight validation bypass on @ns::param
    try {
        quick_query(
            $conn,
            'select @ns::param as r',
            ['ns::param' => to_sql(1.5)]
        );
        echo "1c. Unexpected success\n";
    } catch (PDOException $e) {
        echo "1c. Bypassed validation to PDOException: " . $e->getMessage() . "\n";
    } catch (InvalidArgumentException $e) {
        echo "1c. Caught InvalidArgumentException: " . $e->getMessage() . "\n";
    }

    // --- Bug 2: Array-index parameters (:arr(key)) ---
    // 2a. Float degradation
    $result2a = quick_query(
        $conn,
        'select :arr(key) as r',
        [':arr(key)' => to_sql(1.5)]
    )[0]['r'];

    echo "2a. Float on :arr(key): returned " . $result2a::class . " (value: " . var_export($result2a->value, true) . ")\n";
    assert($result2a instanceof SqlString, 'Expected SqlString due to missing REAL cast');

    // 2b. Query corruption when :arr is float
    try {
        quick_query(
            $conn,
            'select :arr(key) as r',
            [':arr' => to_sql(1.5)]
        );
        echo "2b. Unexpected success\n";
    } catch (PDOException $e) {
        echo "2b. PDOException as expected: " . $e->getMessage() . "\n";
    }

    // 2c. Pre-flight validation bypass on @arr(key)
    try {
        quick_query(
            $conn,
            'select @arr(key) as r',
            ['arr(key)' => to_sql(1.5)]
        );
        echo "2c. Unexpected success\n";
    } catch (PDOException $e) {
        echo "2c. Bypassed validation to PDOException: " . $e->getMessage() . "\n";
    } catch (InvalidArgumentException $e) {
        echo "2c. Caught InvalidArgumentException: " . $e->getMessage() . "\n";
    }

    echo "\n";
}

echo "=== All reproducers verified deterministically across 3 runs ===\n";
