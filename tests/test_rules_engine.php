<?php
declare(strict_types=1);

/**
 * Standalone test for public/api/rules.php
 *
 * rules.php itself starts a session and requires a DB connection at load
 * time, so it can't be require()'d directly in a CLI test. This script
 * extracts just the pure logic (buildRules, evaluateRules, formatRuleResult)
 * between "function buildRules" and the closing "try {" HTTP-handling block,
 * and evaluates it in isolation. No DB, no session, no network needed.
 *
 * Run from anywhere with:
 *   php test_rules_engine.php /path/to/your/public/api/rules.php /path/to/your/app/models/RecommendationEngine.php
 *
 * If no paths are given, defaults to the standard project layout relative
 * to this script (assumes you dropped this file in a /tests folder at the
 * project root):
 *   ../public/api/rules.php
 *   ../app/models/RecommendationEngine.php
 */

$rulesPath  = $argv[1] ?? __DIR__ . '/../public/api/rules.php';
$enginePath = $argv[2] ?? __DIR__ . '/../app/models/RecommendationEngine.php';

foreach (['rules.php' => $rulesPath, 'RecommendationEngine.php' => $enginePath] as $label => $path) {
    if (!file_exists($path)) {
        fwrite(STDERR, "Cannot find $label at: $path\n");
        fwrite(STDERR, "Usage: php test_rules_engine.php /path/to/rules.php /path/to/RecommendationEngine.php\n");
        exit(1);
    }
}

require $enginePath;

// Extract the pure-logic portion of rules.php: from "function buildRules"
// through the line before the closing "try {" HTTP-handling block.
$source = file_get_contents($rulesPath);
$lines = explode("\n", $source);
$startIdx = null;
$endIdx = null;
foreach ($lines as $i => $line) {
    if ($startIdx === null && preg_match('/^function\s+buildRules/', $line)) {
        $startIdx = $i;
    }
    if (preg_match('/^try\s*\{/', $line)) {
        $endIdx = $i;
        break;
    }
}
if ($startIdx === null || $endIdx === null) {
    fwrite(STDERR, "Could not locate buildRules()...try{} boundaries in rules.php. ");
    fwrite(STDERR, "The file structure may have changed — extract the logic manually.\n");
    exit(1);
}
$logicSource = implode("\n", array_slice($lines, $startIdx, $endIdx - $startIdx));

$tmpFile = tempnam(sys_get_temp_dir(), 'rules_logic_') . '.php';
file_put_contents($tmpFile, "<?php\ndeclare(strict_types=1);\n" . $logicSource);
require $tmpFile;
unlink($tmpFile);

$pass = 0;
$fail = 0;
function check(string $label, bool $condition): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "PASS — $label\n";
    } else {
        $fail++;
        echo "FAIL — $label\n";
    }
}

function ruleIds(array $fired): array
{
    return array_column($fired, 'rule');
}

// ─────────────────────────────────────────────────────────────────────────
// FIX 3: A18 is now mutually exclusive with A09/A10 (same net-ratio metric)
// ─────────────────────────────────────────────────────────────────────────
echo "=== Fix 3: A18 vs A09/A10/A11 contradiction scenario ===\n";

$income = 12000.0;
$commitment = 5400.0; // commitmentRatio = 0.45
$netIncome = $income - $commitment; // 6600
$assessment = [
    'monthly_income' => $income, 'monthly_commitment' => $commitment,
    'occupation' => 'Manager', 'household_size' => 2,
];
$budget = 600000.0;

$mortgageAt36pct = $netIncome * 0.36;
$property = [
    'id' => 1, 'median_price' => 550000, 'median_psf' => 350,
    'est_monthly_mortgage_rm' => $mortgageAt36pct,
    'flood_risk' => 'low', 'crime_risk' => 'low', 'safety_score' => 80,
    'bedrooms' => 3, 'bathrooms' => 2, 'distance_to_school_km' => 2,
    'distance_to_mall_km' => 2, 'distance_to_public_transport_km' => 2,
    'smart_readiness_score' => 70, 'sustainability_score' => 70,
    'security_score' => 70, 'family_score' => 70, 'tenure' => 'freehold',
    'distance_to_hospital_km' => 4,
];

$rules = buildRules($budget, $assessment);
$fired = evaluateRules($property, $rules);
$ids = ruleIds($fired);

echo "  Fired rule IDs (36% net ratio): " . implode(', ', $ids) . "\n";
check('A10 (info, 30-40% band) fires for this scenario', in_array('A10', $ids));
check('A18 does NOT fire at 36% net ratio (below its 40% threshold)', !in_array('A18', $ids));
check('A09 (positive, <=30% band) does NOT also fire (mutually exclusive band)', !in_array('A09', $ids));

$mortgageAt45pct = $netIncome * 0.45;
$property2 = $property;
$property2['id'] = 2;
$property2['est_monthly_mortgage_rm'] = $mortgageAt45pct;
$fired2 = evaluateRules($property2, $rules);
$ids2 = ruleIds($fired2);
echo "  Fired rule IDs (45% net ratio): " . implode(', ', $ids2) . "\n";
check('A11 (warning, >40% band) fires when net ratio is 45%', in_array('A11', $ids2));
check('A18 (warning, heavy commitments + >40% net ratio) also fires — both agree (warning)', in_array('A18', $ids2));
check('A09 does NOT fire at 45% net ratio', !in_array('A09', $ids2));
check('A10 does NOT fire at 45% net ratio', !in_array('A10', $ids2));
check('No positive+warning contradiction: A09/A10 and A18 never co-fire',
    !(in_array('A09', $ids2) && in_array('A18', $ids2)) && !(in_array('A10', $ids2) && in_array('A18', $ids2)));

// ─────────────────────────────────────────────────────────────────────────
// FIX 4: O05 / O05b related-group tagging (legitimate co-fire, now labeled)
// ─────────────────────────────────────────────────────────────────────────
echo "\n=== Fix 4: O05/O05b conflict-resolution & relation tagging ===\n";

$highIncomeAssessment = [
    'monthly_income' => 20000, 'monthly_commitment' => 9000,
    'occupation' => 'Engineer', 'household_size' => 2,
];
$netIncome3 = 20000 - 9000;
$budget3 = 800000.0;
$mortgageHeavy = $netIncome3 * 0.45;
$property3 = [
    'id' => 3, 'median_price' => 700000, 'median_psf' => 350,
    'est_monthly_mortgage_rm' => $mortgageHeavy,
    'estimated_rental_yield_pct' => 5.0, 'historical_capital_appreciation_3yr_pct' => 5.0,
    'flood_risk' => 'low', 'crime_risk' => 'low', 'safety_score' => 80,
    'bedrooms' => 3, 'bathrooms' => 2, 'distance_to_school_km' => 2,
    'distance_to_mall_km' => 2, 'distance_to_public_transport_km' => 2,
    'smart_readiness_score' => 70, 'sustainability_score' => 70,
    'security_score' => 70, 'family_score' => 70, 'tenure' => 'freehold',
    'distance_to_hospital_km' => 4,
];

$rules3 = buildRules($budget3, $highIncomeAssessment);
$fired3 = evaluateRules($property3, $rules3);
$ids3 = ruleIds($fired3);
echo "  Fired rule IDs: " . implode(', ', $ids3) . "\n";

check('O05 (positive: strong investment asset) fires', in_array('O05', $ids3));
check('O05b (warning: heavy commitments) also fires — legitimately, both are true', in_array('O05b', $ids3));

$o05 = current(array_filter($fired3, fn($r) => $r['rule'] === 'O05'));
$o05b = current(array_filter($fired3, fn($r) => $r['rule'] === 'O05b'));
check('O05 and O05b share the same related_group tag',
    isset($o05['related_group']) && isset($o05b['related_group'])
    && $o05['related_group'] === $o05b['related_group']);

// ─────────────────────────────────────────────────────────────────────────
// FIX 5: H14 removal + closed-enum comfort_priority in rules.php
// ─────────────────────────────────────────────────────────────────────────
echo "\n=== Fix 5: comfort_priority closed enum in rules.php (H12/H13/H15) ===\n";

function buildSmartHomeAssessment(string $comfort): array
{
    return [
        'monthly_income' => 8000, 'monthly_commitment' => 0,
        'occupation' => 'Artist', 'household_size' => 2,
        'comfort_priority' => $comfort,
    ];
}
$smartProperty = [
    'id' => 4, 'median_price' => 400000, 'median_psf' => 300,
    'est_monthly_mortgage_rm' => 2000,
    'flood_risk' => 'low', 'crime_risk' => 'low', 'safety_score' => 80,
    'bedrooms' => 3, 'bathrooms' => 2, 'distance_to_school_km' => 2,
    'distance_to_mall_km' => 2, 'distance_to_public_transport_km' => 2,
    'smart_readiness_score' => 90, 'sustainability_score' => 90,
    'security_score' => 90, 'family_score' => 90, 'tenure' => 'freehold',
    'distance_to_hospital_km' => 4, 'acoustic_score' => 90,
];

$rulesEnergy = buildRules(500000, buildSmartHomeAssessment('Energy efficiency'));
$idsEnergy = ruleIds(evaluateRules($smartProperty, $rulesEnergy));
check('"Energy efficiency" fires H12', in_array('H12', $idsEnergy));
check('"Energy efficiency" does not fire H13 (acoustic)', !in_array('H13', $idsEnergy));

$rulesAcoustic = buildRules(500000, buildSmartHomeAssessment('Acoustic comfort'));
$idsAcoustic = ruleIds(evaluateRules($smartProperty, $rulesAcoustic));
check('"Acoustic comfort" fires H13', in_array('H13', $idsAcoustic));

$rulesFamily = buildRules(500000, buildSmartHomeAssessment('Family growth'));
$idsFamily = ruleIds(evaluateRules($smartProperty, $rulesFamily));
check('"Family growth" fires H15', in_array('H15', $idsFamily));

check('H14 rule ID no longer exists anywhere in the rule set (dead code removed)',
    !in_array('H14', array_column($rulesFamily, 0)));

echo "\n$pass passed, $fail failed.\n";
exit($fail > 0 ? 1 : 0);
