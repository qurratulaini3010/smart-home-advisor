<?php
declare(strict_types=1);

/**
 * Standalone test for app/models/RecommendationEngine.php
 *
 * Run from anywhere with:
 *   php test_recommendation_engine.php /path/to/your/app/models/RecommendationEngine.php
 *
 * If no path is given, defaults to ../app/models/RecommendationEngine.php
 * relative to this script (assumes you dropped this file in a /tests folder
 * at the project root).
 */

$enginePath = $argv[1] ?? __DIR__ . '/../app/models/RecommendationEngine.php';
if (!file_exists($enginePath)) {
    fwrite(STDERR, "Cannot find RecommendationEngine.php at: $enginePath\n");
    fwrite(STDERR, "Usage: php test_recommendation_engine.php /path/to/RecommendationEngine.php\n");
    exit(1);
}
require $enginePath;

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

// ─────────────────────────────────────────────────────────────────────────
// FIX 1 & 2: Occupation classification is now mutually exclusive
// ─────────────────────────────────────────────────────────────────────────
echo "=== Fix 1/2: Mutually exclusive occupation classification ===\n";

$ambiguous = RecommendationEngine::classifyOccupation('retired government lecturer');
check('retired+govt title resolves to exactly one true flag',
    (int)$ambiguous['isGovt'] + (int)$ambiguous['isSelfEmployed'] + (int)$ambiguous['isHighIncome']
    + (int)$ambiguous['isRetired'] + (int)$ambiguous['isStudent'] === 1);
check('retired+govt title resolves to RETIRED specifically (priority order)',
    $ambiguous['isRetired'] === true && $ambiguous['category'] === 'RETIRED');

$ambiguous2 = RecommendationEngine::classifyOccupation('self-employed government contractor');
check('self-employed+govt title resolves to exactly one true flag',
    (int)$ambiguous2['isGovt'] + (int)$ambiguous2['isSelfEmployed'] + (int)$ambiguous2['isHighIncome']
    + (int)$ambiguous2['isRetired'] + (int)$ambiguous2['isStudent'] === 1);
check('self-employed+govt resolves to SELF_EMPLOYED (priority order)',
    $ambiguous2['isSelfEmployed'] === true && $ambiguous2['category'] === 'SELF_EMPLOYED');

$plain = RecommendationEngine::classifyOccupation('Software Engineer');
check('plain high-income title still classifies correctly',
    $plain['isHighIncome'] === true && $plain['category'] === 'HIGH_INCOME');

$none = RecommendationEngine::classifyOccupation('Artist');
check('unmatched occupation resolves to GENERAL with all flags false',
    $none['category'] === 'GENERAL'
    && !$none['isGovt'] && !$none['isSelfEmployed'] && !$none['isHighIncome']
    && !$none['isRetired'] && !$none['isStudent']);

// ─────────────────────────────────────────────────────────────────────────
// FIX 3: Affordability formula is continuous at price == budget
// ─────────────────────────────────────────────────────────────────────────
echo "\n=== Fix 3: Affordability continuity at the budget boundary ===\n";

function scoreAt(float $price, float $budget): float
{
    $assessment = [
        'budget' => $budget, 'monthly_income' => 8000, 'monthly_commitment' => 0,
        'smart_lighting' => 0, 'smart_security' => 0, 'smart_appliances' => 0, 'smart_energy' => 0,
        'household_size' => 2, 'occupation' => 'Artist',
    ];
    $property = [
        'median_price' => $price, 'security_score' => 50, 'smart_readiness_score' => 50,
        'sustainability_score' => 50, 'family_score' => 50, 'bedrooms' => 2,
    ];
    return RecommendationEngine::score($assessment, $property)['affordability_score'];
}

$budget = 500000.0;
$atBudget  = scoreAt($budget, $budget);
$justUnder = scoreAt($budget * 0.99, $budget);
$justOver  = scoreAt($budget * 1.01, $budget);

echo "  score(price == budget)      = $atBudget\n";
echo "  score(price == 99% budget)  = $justUnder\n";
echo "  score(price == 101% budget) = $justOver\n";

check('score at exactly budget == 86.0 (the pinned boundary value)', abs($atBudget - 86.0) < 0.01);
check('1% under budget is only marginally higher than exactly at budget (continuous)',
    ($justUnder - $atBudget) < 1.0);
check('1% over budget is only marginally lower than exactly at budget (continuous)',
    ($atBudget - $justOver) < 1.5);
check('no cliff-edge jump across the boundary (under vs over differs by < 2 points for a 2% price swing)',
    ($justUnder - $justOver) < 2.0);

// ─────────────────────────────────────────────────────────────────────────
// FIX 5: comfort_priority closed-enum matching (RecommendationEngine side)
// ─────────────────────────────────────────────────────────────────────────
echo "\n=== Fix 5: comfort_priority closed-enum behavior ===\n";

function envScore(string $comfortPriority): float
{
    $assessment = [
        'budget' => 500000, 'monthly_income' => 8000, 'monthly_commitment' => 0,
        'smart_lighting' => 0, 'smart_security' => 0, 'smart_appliances' => 0, 'smart_energy' => 0,
        'household_size' => 2, 'occupation' => 'Artist', 'comfort_priority' => $comfortPriority,
    ];
    $property = [
        'median_price' => 400000, 'security_score' => 50, 'smart_readiness_score' => 50,
        'sustainability_score' => 50, 'family_score' => 50, 'bedrooms' => 2,
    ];
    return RecommendationEngine::score($assessment, $property)['environment_score'];
}

check('"Energy efficiency" (real UI value) boosts environment score by +5',
    abs(envScore('Energy efficiency') - 55.0) < 0.01);
check('"Acoustic comfort" (real UI value) boosts environment score by +5',
    abs(envScore('Acoustic comfort') - 55.0) < 0.01);
check('garbage/unmatched value is a clean no-op (no partial substring match)',
    abs(envScore('xyz123') - 50.0) < 0.01);
check('empty value is a clean no-op',
    abs(envScore('') - 50.0) < 0.01);

echo "\n$pass passed, $fail failed.\n";
exit($fail > 0 ? 1 : 0);
