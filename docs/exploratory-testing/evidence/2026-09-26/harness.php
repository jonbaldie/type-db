<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../../vendor/autoload.php';

use TypeDb\Connection;
use TypeDb\RowHydrator;
use TypeDb\SqlValue\SqlFloat;
use TypeDb\SqlValue\SqlInteger;
use TypeDb\SqlValue\SqlNull;
use TypeDb\SqlValue\SqlString;
use TypeDb\SqlValue\SqlValue;

$stats = ['passed' => 0, 'failed' => 0];

function assertStep(string $name, bool $condition, string $detail = ''): void {
    global $stats;
    if ($condition) {
        $stats['passed']++;
        echo "  [PASS] $name\n";
    } else {
        $stats['failed']++;
        echo "  [FAIL] $name: $detail\n";
    }
}

echo "Starting exploratory test suite for TypeDb...\n\n";

// =============================================================
// Journey 1: Result Hydration, Affinities, and Topologies
// =============================================================
echo "--- Journey 1: Result Hydration, Affinities, and Topologies ---\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn = new Connection($pdo);

// 1.1 DDL and DML operations return empty lists
$resDdl = $conn->quickQuery('CREATE TABLE j1_items (id INT, val TEXT, price REAL)');
$resDml = $conn->quickQuery('INSERT INTO j1_items VALUES (1, "item_1", 19.99), (2, "item_2", 29.99)');
assertStep('1.1 DDL and DML return empty lists', $resDdl === [] && $resDml === []);

// 1.2 Empty SELECT results return empty list
$resEmpty = $conn->quickQuery('SELECT * FROM j1_items WHERE 0');
assertStep('1.2 Empty SELECT results return empty list', $resEmpty === []);

// 1.3 Polymorphic dynamic column storage classes row-by-row
$conn->quickQuery('CREATE TABLE j1_poly (v ANY)');
$conn->quickQuery('INSERT INTO j1_poly VALUES (42), (12.34), ("dynamo"), (NULL)');
$rowsPoly = $conn->quickQuery('SELECT v FROM j1_poly');
$polyTypes = array_map(fn($r) => get_class($r['v']), $rowsPoly);
assertStep(
    '1.3 Polymorphic storage classes row-by-row',
    $polyTypes === [SqlInteger::class, SqlFloat::class, SqlString::class, SqlNull::class]
);

// 1.4 Declared column affinities across all SQLite affinities
$conn->quickQuery('CREATE TABLE j1_affinities (
    c_int INTEGER,
    c_bigint BIGINT,
    c_real REAL,
    c_float FLOAT,
    c_double DOUBLE PRECISION,
    c_numeric NUMERIC,
    c_decimal DECIMAL(10,2),
    c_boolean BOOLEAN,
    c_datetime DATETIME,
    c_varchar VARCHAR(255),
    c_text TEXT,
    c_blob BLOB
)');
$conn->quickQuery("INSERT INTO j1_affinities VALUES (
    42, 9000000000, 1.25, 2.5, 3.75, 4.5, 5.5, 1, '2026-09-26 12:00:00', 'vchar', 'long text', X'DEADBEEF'
)");
$rowsAff = $conn->quickQuery('SELECT * FROM j1_affinities');
$rAff = $rowsAff[0];
$affMatches = (
    $rAff['c_int'] instanceof SqlInteger && $rAff['c_int']->value === 42 &&
    $rAff['c_bigint'] instanceof SqlInteger && $rAff['c_bigint']->value === 9000000000 &&
    $rAff['c_real'] instanceof SqlFloat && $rAff['c_real']->value === 1.25 &&
    $rAff['c_float'] instanceof SqlFloat && $rAff['c_float']->value === 2.5 &&
    $rAff['c_double'] instanceof SqlFloat && $rAff['c_double']->value === 3.75 &&
    $rAff['c_numeric'] instanceof SqlFloat && $rAff['c_numeric']->value === 4.5 &&
    $rAff['c_decimal'] instanceof SqlFloat && $rAff['c_decimal']->value === 5.5 &&
    $rAff['c_boolean'] instanceof SqlInteger && $rAff['c_boolean']->value === 1 &&
    $rAff['c_datetime'] instanceof SqlString && $rAff['c_datetime']->value === '2026-09-26 12:00:00' &&
    $rAff['c_varchar'] instanceof SqlString && $rAff['c_varchar']->value === 'vchar' &&
    $rAff['c_text'] instanceof SqlString && $rAff['c_text']->value === 'long text' &&
    $rAff['c_blob'] instanceof SqlString && bin2hex($rAff['c_blob']->value) === 'deadbeef'
);
assertStep('1.4 Declared column affinities mapping fidelity', $affMatches);

// 1.5 Complex SQL constructs: Views, CTEs, Window functions, Aggregates
$conn->quickQuery('CREATE VIEW j1_view AS SELECT c_int as id, c_varchar as name FROM j1_affinities');
$viewRows = $conn->quickQuery('SELECT * FROM j1_view');

$cteRows = $conn->quickQuery('
    WITH RECURSIVE seq(x) AS (
        SELECT 1
        UNION ALL
        SELECT x + 1 FROM seq WHERE x < 3
    )
    SELECT x, row_number() OVER (ORDER BY x DESC) as rn, count(*) OVER () as total FROM seq
');

$aggRows = $conn->quickQuery('SELECT count(*), max(id) FROM j1_items');

$complexPass = (
    count($viewRows) === 1 && $viewRows[0]['id']->value === 42 &&
    count($cteRows) === 3 && $cteRows[0]['rn']->value === 1 && $cteRows[0]['total']->value === 3 &&
    count($aggRows) === 1 && $aggRows[0]['count(*)']->value === 2
);
assertStep('1.5 Views, CTEs, Window functions, and Aggregates', $complexPass);

// 1.6 Duplicate column names detection
$dupCaught = false;
try {
    $conn->quickQuery('SELECT 1 as dup_col, 2 as dup_col');
} catch (RuntimeException $e) {
    $dupCaught = str_contains($e->getMessage(), 'Duplicate column names in result set: dup_col');
}
assertStep('1.6 Duplicate column detection in RowHydrator', $dupCaught);

// 1.7 Exhausted statement and second fetchAll()
$stmtEx = $pdo->prepare('SELECT 1 as id');
$stmtEx->execute();
$hydratorEx = new RowHydrator($stmtEx, fn($n) => $n);
$firstFetch = $hydratorEx->fetchAll();
$secondFetch = $hydratorEx->fetchAll();
assertStep('1.7 Second fetchAll() on exhausted statement returns empty list', count($firstFetch) === 1 && $secondFetch === []);

echo "\n";

// =============================================================
// Journey 2: Public Extension Surface & Custom SqlValue
// =============================================================
echo "--- Journey 2: Public Extension Surface & Custom SqlValue ---\n";

class CustomStringValue implements SqlValue {
    public function __construct(private string $val) {}
    public function unwrap(): string { return $this->val; }
    public function toPdoParameter(): string { return $this->val; }
    public function pdoParameterType(): int { return PDO::PARAM_STR; }
}

class CustomFloatValue implements SqlValue {
    public function __construct(private float $val) {}
    public function unwrap(): float { return $this->val; }
    public function toPdoParameter(): string { return \TypeDb\round_trip_float_string($this->val); }
    public function pdoParameterType(): int { return PDO::PARAM_STR; }
}

// 2.1 Duck-typed custom SqlValue operational dispatch
$cs = new CustomStringValue('custom_val');
$cf = new CustomFloatValue(123.456);
$resCustom = $conn->quickQuery('SELECT ? as s, ? as f', [$cs, $cf]);
$customPass = (
    $resCustom[0]['s'] instanceof SqlString && $resCustom[0]['s']->value === 'custom_val' &&
    $resCustom[0]['f'] instanceof SqlFloat && $resCustom[0]['f']->value === 123.456
);
assertStep('2.1 Duck-typed custom SqlValue operational dispatch', $customPass);

// 2.2 Boundary float conversions and round-trip fidelity
$boundaryFloats = [
    0.0, -0.0, 1.0, -1.0, 3.141592653589793,
    PHP_FLOAT_MIN, PHP_FLOAT_MAX, PHP_FLOAT_EPSILON,
    1.5e-300, 1.5e300, INF, -INF
];
$floatsRoundtrip = true;
foreach ($boundaryFloats as $flt) {
    $sqlVal = \TypeDb\to_sql($flt);
    if (!$sqlVal instanceof SqlFloat) { $floatsRoundtrip = false; break; }
    $unwrapped = \TypeDb\from_sql($sqlVal);
    if (fdiv(1.0, $flt) === -INF) {
        if (fdiv(1.0, (float)$unwrapped) !== -INF) { $floatsRoundtrip = false; break; }
    } elseif ($unwrapped !== $flt) {
        $floatsRoundtrip = false;
        break;
    }
}
assertStep('2.2 Boundary float round-trips through to_sql / from_sql', $floatsRoundtrip);

// 2.3 NAN rejection in query binding and parameter conversion
$nanCaught = false;
try {
    $conn->quickQuery('SELECT ?', [new SqlFloat(NAN)]);
} catch (InvalidArgumentException $e) {
    $nanCaught = str_contains($e->getMessage(), 'NAN cannot be represented by SQLite');
}
assertStep('2.3 NAN rejection in query binding', $nanCaught);

// 2.4 Unsupported custom SqlValue rejection boundary
class IncompleteSqlVal implements SqlValue {}
$unsupportedCaught = false;
try {
    \TypeDb\from_sql(new IncompleteSqlVal());
} catch (InvalidArgumentException $e) {
    $unsupportedCaught = str_contains($e->getMessage(), 'Unsupported SqlValue implementation');
}
assertStep('2.4 Unsupported SqlValue rejection boundary', $unsupportedCaught);

// 2.5 Large 64-bit integer boundary fidelity
$resInts = $conn->quickQuery('SELECT ? as min_i, ? as max_i', [
    \TypeDb\to_sql(PHP_INT_MIN),
    \TypeDb\to_sql(PHP_INT_MAX)
]);
$intsPass = (
    $resInts[0]['min_i'] instanceof SqlInteger && $resInts[0]['min_i']->value === PHP_INT_MIN &&
    $resInts[0]['max_i'] instanceof SqlInteger && $resInts[0]['max_i']->value === PHP_INT_MAX
);
assertStep('2.5 Large 64-bit integer boundary fidelity', $intsPass);

// 2.6 Subnormal float bit-identical roundtrip
$subnormal = 4.9406564584125E-324;
$resSub = $conn->quickQuery('SELECT ? as sn', [\TypeDb\to_sql($subnormal)]);
assertStep('2.6 Subnormal float bit-identical roundtrip', $resSub[0]['sn'] instanceof SqlFloat && $resSub[0]['sn']->value === $subnormal);

// 2.7 Complex strings, astral emojis, and binary data
$astral = "🚀🌟 𠜎 𠜱 café naïve \0 embedded_null \r\n newline";
$binary = random_bytes(256);
$resComplex = $conn->quickQuery('SELECT ? as a, ? as b', [\TypeDb\to_sql($astral), \TypeDb\to_sql($binary)]);
$complexPass = (
    $resComplex[0]['a'] instanceof SqlString && $resComplex[0]['a']->value === $astral &&
    $resComplex[0]['b'] instanceof SqlString && $resComplex[0]['b']->value === $binary
);
assertStep('2.7 Complex strings, astral emojis, and binary data', $complexPass);

echo "\n";

// =============================================================
// Journey 3: Driver Configuration Statefulness & Metamorphic Invariance
// =============================================================
echo "--- Journey 3: Driver Configuration Statefulness & Invariance ---\n";

// 3.1 PDO::ATTR_CASE normalization for identifiers and float parameters
$pdoLower = new PDO('sqlite::memory:');
$pdoLower->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
$connLower = new Connection($pdoLower);
$resLower = $connLower->quickQuery('SELECT 1 as MyCol, :PARAM as MyParam', [':PARAM' => \TypeDb\to_sql(1.5)]);

$pdoUpper = new PDO('sqlite::memory:');
$pdoUpper->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
$connUpper = new Connection($pdoUpper);
$resUpper = $connUpper->quickQuery('SELECT 1 as mycol, :param as myparam', [':param' => \TypeDb\to_sql(1.5)]);

$casePass = (
    isset($resLower[0]['mycol']) && isset($resLower[0]['myparam']) &&
    isset($resUpper[0]['MYCOL']) && isset($resUpper[0]['MYPARAM'])
);
assertStep('3.1 PDO::ATTR_CASE normalization for identifiers and float parameters', $casePass);

// 3.2 PDO::ATTR_STRINGIFY_FETCHES type fidelity including stringified infinities
$pdoStr = new PDO('sqlite::memory:');
$pdoStr->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
$connStr = new Connection($pdoStr);
$resStr = $connStr->quickQuery("SELECT 100 as i, 2.71828 as f, 'hello' as s, NULL as n, 9e999 as p_inf, -9e999 as n_inf");
$rStr = $resStr[0];
$strPass = (
    $rStr['i'] instanceof SqlInteger && $rStr['i']->value === 100 &&
    $rStr['f'] instanceof SqlFloat && abs($rStr['f']->value - 2.71828) < 1e-10 &&
    $rStr['s'] instanceof SqlString && $rStr['s']->value === 'hello' &&
    $rStr['n'] instanceof SqlNull &&
    $rStr['p_inf'] instanceof SqlFloat && is_infinite($rStr['p_inf']->value) && $rStr['p_inf']->value > 0 &&
    $rStr['n_inf'] instanceof SqlFloat && is_infinite($rStr['n_inf']->value) && $rStr['n_inf']->value < 0
);
assertStep('3.2 PDO::ATTR_STRINGIFY_FETCHES type fidelity including infinities', $strPass);

// 3.3 PDO::ATTR_ERRMODE (ERRMODE_SILENT deterministic error translation)
$pdoSilent = new PDO('sqlite::memory:');
$pdoSilent->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
$connSilent = new Connection($pdoSilent);
$silentCaught = false;
try {
    $connSilent->quickQuery('SELECT from WHERE');
} catch (RuntimeException $e) {
    $silentCaught = str_contains($e->getMessage(), 'Failed to prepare query [HY000/1]: near "from": syntax error');
}
assertStep('3.3 ERRMODE_SILENT deterministic error translation', $silentCaught);

// 3.4 Parameter form metamorphic invariance (?, ?NNN, :name, :ns::param, :arr(idx))
$rPos = $conn->quickQuery('SELECT ? as val', [\TypeDb\to_sql(1.5)]);
$rNum = $conn->quickQuery('SELECT ?1 as val', [\TypeDb\to_sql(1.5)]);
$rNamed = $conn->quickQuery('SELECT :param as val', [':param' => \TypeDb\to_sql(1.5)]);
$rNs = $conn->quickQuery('SELECT :ns::param as val', [':ns::param' => \TypeDb\to_sql(1.5)]);
$rArr = $conn->quickQuery('SELECT :arr(idx) as val', [':arr(idx)' => \TypeDb\to_sql(1.5)]);
$paramFormsPass = (
    $rPos[0]['val']->value === 1.5 &&
    $rNum[0]['val']->value === 1.5 &&
    $rNamed[0]['val']->value === 1.5 &&
    $rNs[0]['val']->value === 1.5 &&
    $rArr[0]['val']->value === 1.5
);
assertStep('3.4 Metamorphic invariance across parameter forms', $paramFormsPass);

// 3.5 Metamorphic alias and unaliased expression name invariance
$resUnaliased = $conn->quickQuery('SELECT abs(?)', [\TypeDb\to_sql(-2.5)]);
$resAliased = $conn->quickQuery('SELECT abs(?) as my_abs', [\TypeDb\to_sql(-2.5)]);
$resInt = $conn->quickQuery('SELECT abs(?)', [\TypeDb\to_sql(-2)]);
$aliasPass = (
    array_key_first($resUnaliased[0]) === 'abs(?)' &&
    array_key_first($resInt[0]) === 'abs(?)' &&
    array_key_first($resAliased[0]) === 'my_abs' &&
    $resUnaliased[0]['abs(?)']->value === 2.5
);
assertStep('3.5 Metamorphic alias and unaliased expression name invariance', $aliasPass);

// 3.6 SQLite JSON operator and function result hydration
$resJson = $conn->quickQuery("SELECT '{\"a\": 42, \"b\": \"text\", \"c\": 3.14}' ->> '$.a' as j_int, '{\"a\": 42, \"b\": \"text\", \"c\": 3.14}' ->> '$.b' as j_str, '{\"a\": 42, \"b\": \"text\", \"c\": 3.14}' ->> '$.c' as j_flt");
$jsonPass = (
    $resJson[0]['j_int'] instanceof SqlInteger && $resJson[0]['j_int']->value === 42 &&
    $resJson[0]['j_str'] instanceof SqlString && $resJson[0]['j_str']->value === 'text' &&
    $resJson[0]['j_flt'] instanceof SqlFloat && $resJson[0]['j_flt']->value === 3.14
);
assertStep('3.6 SQLite JSON operator and function result hydration', $jsonPass);

echo "\n=============================================================\n";
echo "Suite complete: {$stats['passed']} passed, {$stats['failed']} failed.\n";
exit($stats['failed'] === 0 ? 0 : 1);
