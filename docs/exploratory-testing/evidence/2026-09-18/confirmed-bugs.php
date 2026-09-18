<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload.php';

use TypeDb\Connection;
use function TypeDb\quick_query;
use function TypeDb\to_sql;

function run_reproducer(int $run): void
{
    echo "=== Replay $run ===\n";

    // Reproducer 1: Result key leakage under PDO::CASE_LOWER
    $pdoLower = new PDO('sqlite::memory:');
    $pdoLower->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
    $connLower = new Connection($pdoLower);

    $resLowerPos = quick_query($connLower, 'select ?', [to_sql(1.5)]);
    $keyLowerPos = array_key_first($resLowerPos[0]);
    echo "CASE_LOWER positional 'select ?':\n";
    echo "  Actual:   '$keyLowerPos'\n";
    echo "  Expected: '?'\n";

    $resLowerNamed = quick_query($connLower, 'select :v', [':v' => to_sql(1.5)]);
    $keyLowerNamed = array_key_first($resLowerNamed[0]);
    echo "CASE_LOWER named 'select :v':\n";
    echo "  Actual:   '$keyLowerNamed'\n";
    echo "  Expected: ':v'\n";

    // Reproducer 2: Result key leakage under PDO::CASE_UPPER
    $pdoUpper = new PDO('sqlite::memory:');
    $pdoUpper->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
    $connUpper = new Connection($pdoUpper);

    $resUpperPos = quick_query($connUpper, 'select ?', [to_sql(1.5)]);
    $keyUpperPos = array_key_first($resUpperPos[0]);
    echo "CASE_UPPER positional 'select ?':\n";
    echo "  Actual:   '$keyUpperPos'\n";
    echo "  Expected: '?'\n";

    $resUpperNamed = quick_query($connUpper, 'select :v', [':v' => to_sql(1.5)]);
    $keyUpperNamed = array_key_first($resUpperNamed[0]);
    echo "CASE_UPPER named 'select :v':\n";
    echo "  Actual:   '$keyUpperNamed'\n";
    echo "  Expected: ':V'\n";

    // Reproducer 3: Duplicate column detection bypassed under PDO::CASE_LOWER
    $pdoDup = new PDO('sqlite::memory:');
    $pdoDup->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
    $connDup = new Connection($pdoDup);

    try {
        $resDup = quick_query($connDup, 'select ?, ?', [to_sql(1.5), to_sql(2.5)]);
        echo "CASE_LOWER duplicate 'select ?, ?':\n";
        echo "  Actual:   Did NOT throw! Returned keys: " . json_encode(array_keys($resDup[0])) . "\n";
        echo "  Expected: Throws RuntimeException (Duplicate column names in result set: ?)\n";
    } catch (\RuntimeException $e) {
        echo "CASE_LOWER duplicate 'select ?, ?': Threw " . $e->getMessage() . "\n";
    }

    echo "\n";
}

for ($run = 1; $run <= 3; $run++) {
    run_reproducer($run);
}
