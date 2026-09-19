<?php

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload.php';

use TypeDb\Connection;
use function TypeDb\quick_query;
use function TypeDb\to_sql;

function run_reproducer(int $run): void
{
    echo "=== Replay $run ===\n";

    // Reproducer 1: Case normalization violation & type divergence under PDO::CASE_LOWER
    $pdoLower = new PDO('sqlite::memory:');
    $pdoLower->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
    $connLower = new Connection($pdoLower);

    $resLowerFloat = quick_query($connLower, 'select :PARAM', [':PARAM' => to_sql(1.5)]);
    $keyLowerFloat = array_key_first($resLowerFloat[0]);
    $resLowerInt = quick_query($connLower, 'select :PARAM', [':PARAM' => to_sql(1)]);
    $keyLowerInt = array_key_first($resLowerInt[0]);

    echo "CASE_LOWER named 'select :PARAM':\n";
    echo "  Float actual key:   '$keyLowerFloat'\n";
    echo "  Float expected key: ':param'\n";
    echo "  Int actual key:     '$keyLowerInt'\n";

    // Reproducer 2: Case normalization violation & type divergence under PDO::CASE_UPPER
    $pdoUpper = new PDO('sqlite::memory:');
    $pdoUpper->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
    $connUpper = new Connection($pdoUpper);

    $resUpperFloat = quick_query($connUpper, 'select :param', [':param' => to_sql(1.5)]);
    $keyUpperFloat = array_key_first($resUpperFloat[0]);
    $resUpperInt = quick_query($connUpper, 'select :param', [':param' => to_sql(1)]);
    $keyUpperInt = array_key_first($resUpperInt[0]);

    echo "CASE_UPPER named 'select :param':\n";
    echo "  Float actual key:   '$keyUpperFloat'\n";
    echo "  Float expected key: ':PARAM'\n";
    echo "  Int actual key:     '$keyUpperInt'\n";

    // Reproducer 3: Duplicate column detection bypassed under PDO::CASE_LOWER
    try {
        $resDupLower = quick_query($connLower, 'select :param, :PARAM', [
            ':param' => to_sql(1),
            ':PARAM' => to_sql(2.5),
        ]);
        echo "CASE_LOWER duplicate 'select :param, :PARAM' (int + float):\n";
        echo "  Actual:   Did NOT throw! Returned keys: " . json_encode(array_keys($resDupLower[0])) . "\n";
        echo "  Expected: Throws RuntimeException (Duplicate column names in result set: :param)\n";
    } catch (\RuntimeException $e) {
        echo "CASE_LOWER duplicate 'select :param, :PARAM': Threw " . $e->getMessage() . "\n";
    }

    // Reproducer 4: Duplicate column detection bypassed under PDO::CASE_UPPER
    try {
        $resDupUpper = quick_query($connUpper, 'select :param, :PARAM', [
            ':param' => to_sql(1.5),
            ':PARAM' => to_sql(2),
        ]);
        echo "CASE_UPPER duplicate 'select :param, :PARAM' (float + int):\n";
        echo "  Actual:   Did NOT throw! Returned keys: " . json_encode(array_keys($resDupUpper[0])) . "\n";
        echo "  Expected: Throws RuntimeException (Duplicate column names in result set: :PARAM)\n";
    } catch (\RuntimeException $e) {
        echo "CASE_UPPER duplicate 'select :param, :PARAM': Threw " . $e->getMessage() . "\n";
    }

    echo "\n";
}

for ($run = 1; $run <= 3; $run++) {
    run_reproducer($run);
}
