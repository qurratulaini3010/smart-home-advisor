<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

require __DIR__ . '/../../app/config.php';
require __DIR__ . '/../../app/helpers/helpers.php';
require __DIR__ . '/../../app/core/Database.php';
require __DIR__ . '/../../app/core/Auth.php';
// FIX: Required so rules.php can use RecommendationEngine::classifyOccupation()
// for canonical, consistent occupation classification.
require __DIR__ . '/../../app/models/RecommendationEngine.php';

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (!Auth::check()) {
    respond(['success' => false, 'message' => 'Authentication required.'], 401);
}

/**
 * CLIPS-style forward-chaining rules engine.
 * Each rule: [id, domain, condition callable, label, severity, explanation].
 * 106 rules across 8 domains.
 */
function buildRules(float $budget, array $assessment = []): array
{
    // ── Unpack assessment fields ──────────────────────────────────────────────
    $age             = (int)   ($assessment['age']                ?? 0);
    $income          = (float) ($assessment['monthly_income']     ?? 0);
    $commitment      = (float) ($assessment['monthly_commitment'] ?? 0);
    // Gaji bersih: net income after all monthly commitments (car loan, PTPTN, credit card, etc.)
    // If the DB generated column is available use it; otherwise compute it here.
    $netIncome       = (float) ($assessment['net_income']
                        ?? max(0, $income - $commitment));
    $household       = (int)   ($assessment['household_size']   ?? 0);
    $prefLocation    = strtolower(trim((string) ($assessment['preferred_location'] ?? '')));
    $prefType        = strtolower(trim((string) ($assessment['property_type']      ?? 'any')));
    $wantsLighting   = (int)   ($assessment['smart_lighting']   ?? 0) === 1;
    $wantsSecurity   = (int)   ($assessment['smart_security']   ?? 0) === 1;
    $wantsAppliances = (int)   ($assessment['smart_appliances'] ?? 0) === 1;
    $wantsEnergy     = (int)   ($assessment['smart_energy']     ?? 0) === 1;
    $smartCount      = (int)$wantsLighting + (int)$wantsSecurity + (int)$wantsAppliances + (int)$wantsEnergy;
    // FIX (audit item #5): comfort_priority is a closed set in practice — the
    // front end (assets/js/app.js, slidersToAssessmentFields) only ever sends
    // "Family growth", "Acoustic comfort", or "Energy efficiency"; the quick
    // assessment form (index.php) only ever sends "Energy efficiency". Treat
    // it as the enum it actually is instead of matching arbitrary substrings.
    // This also surfaces a real bug the substring version hid silently: no UI
    // path can ever send a value containing "security", so the old H14 rule
    // ("Matches your security comfort priority") was unreachable dead code.
    // It has been removed below rather than left to silently never fire.
    $comfortPriorityMap = [
        'energy efficiency' => 'ENERGY',
        'acoustic comfort'  => 'ACOUSTIC',
        'family growth'     => 'FAMILY',
    ];
    $comfortPri = strtolower(trim((string) ($assessment['comfort_priority'] ?? '')));
    $comfortCategory = $comfortPriorityMap[$comfortPri] ?? null; // null = no match, explicit no-op
    $occupation      = (string) ($assessment['occupation'] ?? '');

    // FIX: Use the canonical occupation classifier from RecommendationEngine so
    // that both the numeric scoring engine and the rules badge engine use the
    // exact same keyword lists. Previously they had slightly different lists
    // which caused inconsistencies (e.g. 'physician' triggered rules but not scores).
    $occ            = RecommendationEngine::classifyOccupation($occupation);
    $isGovt         = $occ['isGovt'];
    $isSelfEmployed = $occ['isSelfEmployed'];
    $isHighIncome   = $occ['isHighIncome'];
    $isStudent      = $occ['isStudent'];
    $isRetired      = $occ['isRetired'];

    // ── Key derived ratios ────────────────────────────────────────────────────
    // Commitment ratio: how much of gross income is already locked in obligations
    // Higher commitment ratio = less room for mortgage regardless of salary level
    $commitmentRatio = $income > 0 ? $commitment / $income : 0;

    // Mortgage-to-NET-income ratio — this is what truly determines cash-flow stress.
    // A high earner (RM 15k) with RM 10k commitments has only RM 5k net;
    // a moderate earner (RM 5k) with zero commitments has full RM 5k net.
    // Using gross income alone is misleading — we must use gaji bersih.
    $mortgageToNetRatio = fn($p) => $netIncome > 0 && (float)($p['est_monthly_mortgage_rm'] ?? 0) > 0
        ? (float)($p['est_monthly_mortgage_rm'] ?? 0) / $netIncome
        : 0;

    // Price-to-annual-net-income ratio (uses net income, more realistic than gross)
    $priceToNetAnnual = fn($p) => $netIncome > 0 && (float)($p['median_price'] ?? $p['price'] ?? 0) > 0
        ? (float)($p['median_price'] ?? $p['price'] ?? 0) / ($netIncome * 12)
        : 0;

    return [

        // ════════════════════════════════════════════════════════════
        // DOMAIN 1: AFFORDABILITY  (A01–A18)
        // All mortgage stress rules now use NET income (gaji bersih)
        // because commitments reduce real repayment capacity regardless
        // of gross salary level.
        // ════════════════════════════════════════════════════════════

        ['A01', 'Affordability',
            fn($p) => $budget > 0 && (float)($p['median_price'] ?? $p['price'] ?? 0) <= $budget,
            'Within your budget', 'positive',
            'Median price is at or below your stated budget.'],

        ['A02', 'Affordability',
            fn($p) => $budget > 0
                && (float)($p['median_price'] ?? $p['price'] ?? 0) > $budget
                && (float)($p['median_price'] ?? $p['price'] ?? 0) <= $budget * 1.10,
            'Up to 10% over budget', 'info',
            'Price is slightly above budget — within 10% stretch.'],

        ['A03', 'Affordability',
            fn($p) => $budget > 0
                && (float)($p['median_price'] ?? $p['price'] ?? 0) > $budget * 1.10
                && (float)($p['median_price'] ?? $p['price'] ?? 0) <= $budget * 1.20,
            '10–20% over budget', 'warning',
            'Price is 10–20% above your budget — significant stretch.'],

        ['A04', 'Affordability',
            fn($p) => $budget > 0
                && (float)($p['median_price'] ?? $p['price'] ?? 0) > $budget * 1.20,
            'Exceeds budget by more than 20%', 'warning',
            'Price is more than 20% above your budget.'],

        ['A05', 'Affordability',
            fn($p) => $budget > 0
                && (float)($p['median_price'] ?? $p['price'] ?? 0) <= $budget * 0.80,
            'Well under budget — 20%+ savings', 'positive',
            'Property is at least 20% below your budget. Good financial headroom.'],

        ['A06', 'Affordability',
            fn($p) => (float)($p['median_psf'] ?? 0) > 0 && (float)($p['median_psf'] ?? 0) < 300,
            'Excellent value per sqft (< RM 300)', 'positive',
            'Median PSF is below RM 300 — excellent value relative to market.'],

        ['A07', 'Affordability',
            fn($p) => (float)($p['median_psf'] ?? 0) >= 300 && (float)($p['median_psf'] ?? 0) < 400,
            'Average market price per sqft', 'info',
            'Median PSF is between RM 300–400 — in line with typical market rates.'],

        ['A08', 'Affordability',
            fn($p) => (float)($p['median_psf'] ?? 0) >= 400,
            'Above-average price per sqft (≥ RM 400)', 'warning',
            'Median PSF is RM 400 or above — premium pricing, ensure value justifies cost.'],

        // A09–A12: Mortgage stress is measured against NET income (gaji bersih),
        // not gross. A person earning RM 12k with RM 9k in commitments has only
        // RM 3k free — they are MORE stressed than someone earning RM 4k with
        // zero commitments. Gross salary alone cannot capture this.
        ['A09', 'Affordability',
            fn($p) => $mortgageToNetRatio($p) > 0 && $mortgageToNetRatio($p) <= 0.30,
            'Healthy mortgage-to-net-income ratio (≤ 30%)', 'positive',
            'Estimated mortgage is 30% or less of your net income (after commitments) — financially comfortable.'],

        ['A10', 'Affordability',
            fn($p) => $mortgageToNetRatio($p) > 0.30 && $mortgageToNetRatio($p) <= 0.40,
            'Mortgage is 30–40% of net income', 'info',
            'Mortgage is 30–40% of your net income — manageable but leaves limited room for emergencies.'],

        ['A11', 'Affordability',
            fn($p) => $mortgageToNetRatio($p) > 0.40,
            'Mortgage exceeds 40% of net income', 'warning',
            'High cash-flow risk — after existing commitments, the mortgage may strain your monthly budget significantly.'],

        ['A12', 'Affordability',
            fn($p) => (float)($p['est_monthly_mortgage_rm'] ?? 0) > 0
                && $netIncome > 0
                && (float)($p['est_monthly_mortgage_rm'] ?? 0) <= $netIncome * 0.25,
            'Very affordable mortgage (≤ 25% of net income)', 'positive',
            'Mortgage is 25% or less of your net income (gaji bersih) — very low financial burden.'],

        ['A13', 'Affordability',
            fn($p) => $age > 0 && $age >= 50
                && (float)($p['est_monthly_mortgage_rm'] ?? 0) > 0,
            'Review loan tenure — age may affect eligibility', 'info',
            'Borrower age of 50+ may limit available loan tenure. Confirm with bank.'],

        // A14–A15: Price-to-income ratio uses net annual income.
        // A high-salary person with heavy commitments has a lower real
        // purchase power than their gross income suggests.
        ['A14', 'Affordability',
            fn($p) => $priceToNetAnnual($p) > 0 && $priceToNetAnnual($p) <= 5,
            'Price-to-net-income ratio is healthy (≤ 5×)', 'positive',
            'Property price is within 5 times your net annual income — within the standard affordability threshold.'],

        ['A15', 'Affordability',
            fn($p) => $priceToNetAnnual($p) > 8,
            'Price-to-net-income ratio is high (> 8×)', 'warning',
            'Property costs more than 8 times your net annual income — consider whether your commitments leave enough room.'],

        ['A16', 'Affordability',
            fn($p) => (float)($p['estimated_rental_yield_pct'] ?? 0) > 0
                && (float)($p['est_monthly_mortgage_rm'] ?? 0) > 0
                && ((float)($p['estimated_rental_yield_pct'] ?? 0) / 100 * (float)($p['median_price'] ?? $p['price'] ?? 0) / 12)
                    >= (float)($p['est_monthly_mortgage_rm'] ?? 0) * 0.80,
            'Rental income could cover 80%+ of mortgage', 'positive',
            'Estimated rental yield can offset a large portion of the monthly mortgage — strong buy-to-let case.'],

        // A17: Low-commitment advantage — person with minimal commitments
        // has stronger real affordability than their salary alone implies.
        ['A17', 'Affordability',
            fn($p) => $commitmentRatio <= 0.10
                && $netIncome > 0
                && (float)($p['est_monthly_mortgage_rm'] ?? 0) > 0
                && (float)($p['est_monthly_mortgage_rm'] ?? 0) <= $netIncome * 0.45,
            'Low-commitment buyer — strong real affordability', 'positive',
            'With minimal existing commitments (≤ 10% of income), your net income comfortably supports this mortgage even at a moderate DSR.'],

        // A18: Heavy-commitment warning — a high earner with large obligations
        // may be riskier than a moderate earner with none.
        //
        // FIX (audit item #3): A18 previously used an INDEPENDENT metric
        // (gross commitmentRatio > 0.40 AND mortgage > netIncome * 0.35) that
        // overlapped with A09-A11's net-income-ratio bands without ever being
        // defined as mutually exclusive with them. A buyer with, e.g.,
        // commitmentRatio = 0.45 and a mortgage at 36% of net income would
        // fire BOTH A10 ("Mortgage is 30-40% of net income", severity=info)
        // and A18 ("bank DSR may be tight", severity=warning) for the exact
        // same property — two badges describing the same underlying fact
        // (mortgage stress) using two different number bases, with no
        // precedence between them.
        //
        // A18 is now defined purely as the highest band of the SAME
        // mortgageToNetRatio metric A09-A11 use, so it is mutually exclusive
        // with A09/A10/A11 by construction (only one net-ratio band can be
        // true at a time), and is tagged conflicts_with A09/A10/A11 as a
        // second line of defense — see evaluateRules() below, which drops
        // any rule listed in another fired rule's conflicts_with set.
        ['A18', 'Affordability',
            fn($p) => $mortgageToNetRatio($p) > 0.40 && $commitmentRatio > 0.40,
            'High existing commitments — bank DSR may be tight', 'warning',
            'Your existing monthly commitments are above 40% of gross income and the mortgage exceeds 40% of net income. Banks calculate DSR on remaining capacity — this mortgage may face stricter bank scrutiny regardless of your salary level.',
            ['A09', 'A10']],


        // ════════════════════════════════════════════════════════════
        // DOMAIN 2: SAFETY & RISK  (S01–S14)
        // ════════════════════════════════════════════════════════════

        ['S01', 'Safety',
            fn($p) => in_array(strtolower((string)($p['flood_risk'] ?? '')), ['low', 'very low'], true),
            'Low flood risk area', 'positive',
            'Area is rated Low or Very Low flood risk.'],

        ['S02', 'Safety',
            fn($p) => strtolower((string)($p['flood_risk'] ?? '')) === 'medium',
            'Moderate flood risk', 'info',
            'Area has medium flood risk — check seasonal history and insurance costs.'],

        ['S03', 'Safety',
            fn($p) => in_array(strtolower((string)($p['flood_risk'] ?? '')), ['high', 'very high'], true),
            'High flood risk — factor in insurance', 'warning',
            'Area has High or Very High flood risk. Insurance and remediation costs apply.'],

        ['S04', 'Safety',
            fn($p) => in_array(strtolower((string)($p['crime_risk'] ?? '')), ['low', 'very low'], true),
            'Low crime risk area', 'positive',
            'Crime index is Low or Very Low for this area.'],

        ['S05', 'Safety',
            fn($p) => strtolower((string)($p['crime_risk'] ?? '')) === 'medium',
            'Moderate crime risk', 'info',
            'Crime level is Medium — standard urban precautions recommended.'],

        ['S06', 'Safety',
            fn($p) => in_array(strtolower((string)($p['crime_risk'] ?? '')), ['high', 'very high'], true),
            'High crime risk — review carefully', 'warning',
            'Crime index is High or Very High. Consider additional security measures.'],

        ['S07', 'Safety',
            fn($p) => (float)($p['safety_score'] ?? 0) >= 80,
            'Excellent safety score (≥ 80)', 'positive',
            'Overall safety score of 80 or above — top-tier safe neighbourhood.'],

        ['S08', 'Safety',
            fn($p) => (float)($p['safety_score'] ?? 0) >= 65 && (float)($p['safety_score'] ?? 0) < 80,
            'Good safety score (65–79)', 'positive',
            'Safety score is between 65–79 — generally safe with standard precautions.'],

        ['S09', 'Safety',
            fn($p) => (float)($p['safety_score'] ?? 0) < 50 && (float)($p['safety_score'] ?? 0) > 0,
            'Below-average safety score (< 50)', 'warning',
            'Safety score is below 50 — exercise caution and research the neighbourhood.'],

        ['S10', 'Safety',
            fn($p) => (float)($p['safety_score'] ?? 0) >= 75
                && in_array(strtolower((string)($p['flood_risk'] ?? '')), ['low', 'very low'], true)
                && in_array(strtolower((string)($p['crime_risk'] ?? '')), ['low', 'very low', 'medium'], true),
            'Verified safe neighbourhood', 'positive',
            'All three safety signals are green: high safety score, low flood risk, acceptable crime level.'],

        ['S11', 'Safety',
            fn($p) => $wantsSecurity && (int)($p['security_score'] ?? 0) >= 80,
            'Matches your security priority', 'positive',
            'You prioritised security and this property scores 80+ on security readiness.'],

        ['S12', 'Safety',
            fn($p) => $wantsSecurity && (int)($p['security_score'] ?? 0) < 65,
            'Security score below your expectation', 'warning',
            'You want strong security but this property scores below 65 on security readiness.'],

        ['S13', 'Safety',
            fn($p) => (float)($p['distance_to_hospital_km'] ?? 99) <= 5.0,
            'Hospital within 5 km', 'positive',
            'Nearest hospital is within 5 km — important for emergencies and elderly residents.'],

        ['S14', 'Safety',
            fn($p) => (float)($p['distance_to_hospital_km'] ?? 99) > 15.0,
            'Hospital is far (> 15 km)', 'warning',
            'Nearest hospital is more than 15 km away — critical for families and elderly.'],


        // ════════════════════════════════════════════════════════════
        // DOMAIN 3: FAMILY SUITABILITY  (F01–F16)
        // ════════════════════════════════════════════════════════════

        ['F01', 'Family',
            fn($p) => $household >= 4 && (int)($p['bedrooms'] ?? 0) >= 3
                && (float)($p['distance_to_school_km'] ?? 99) <= 3.0,
            'Ideal for your family size', 'positive',
            'Household of 4+, at least 3 bedrooms, and a school within 3 km — all family conditions met.'],

        ['F02', 'Family',
            fn($p) => $household >= 5 && (int)($p['bedrooms'] ?? 0) < 4,
            'May be too small — household needs 4+ rooms', 'warning',
            'Household of 5 or more but property has fewer than 4 bedrooms.'],

        ['F03', 'Family',
            fn($p) => (int)($p['bedrooms'] ?? 0) >= 4,
            'Large home — 4+ bedrooms', 'positive',
            'Four or more bedrooms — suits larger families or those wanting extra rooms.'],

        ['F04', 'Family',
            fn($p) => (int)($p['bedrooms'] ?? 0) >= 1 && (int)($p['bedrooms'] ?? 0) <= 2
                && $household >= 4,
            'Too few bedrooms for household size', 'warning',
            'Only 1–2 bedrooms but household size is 4 or more.'],

        ['F05', 'Family',
            fn($p) => (int)($p['bathrooms'] ?? 0) >= 3,
            'Three or more bathrooms', 'positive',
            'Three or more bathrooms — comfortable for larger households.'],

        ['F06', 'Family',
            fn($p) => (int)($p['bathrooms'] ?? 0) === 1 && $household >= 3,
            'Only one bathroom for household of 3+', 'warning',
            'Single bathroom may cause congestion for a household of 3 or more.'],

        ['F07', 'Family',
            fn($p) => (float)($p['distance_to_school_km'] ?? 99) <= 1.0,
            'School within 1 km — walking distance', 'positive',
            'Nearest school is under 1 km — children can walk or cycle.'],

        ['F08', 'Family',
            fn($p) => (float)($p['distance_to_school_km'] ?? 99) > 5.0,
            'School is far (> 5 km)', 'info',
            'Nearest school is more than 5 km — daily transport needed for school-going children.'],

        ['F09', 'Family',
            fn($p) => (float)($p['distance_to_mall_km'] ?? 99) <= 3.0,
            'Mall within 3 km', 'positive',
            'Shopping centre is within 3 km — convenient for daily errands.'],

        ['F10', 'Family',
            fn($p) => (float)($p['distance_to_public_transport_km'] ?? 99) <= 1.0,
            'Public transport within 1 km', 'positive',
            'Very close to public transport — ideal for commuters without a car.'],

        ['F11', 'Family',
            fn($p) => (float)($p['distance_to_public_transport_km'] ?? 99) > 5.0,
            'Public transport is far (> 5 km)', 'info',
            'Public transport is more than 5 km away — car-dependent location.'],

        ['F12', 'Family',
            fn($p) => !empty($prefLocation)
                && (
                    stripos((string)($p['area']      ?? ''), $prefLocation) !== false
                    || stripos((string)($p['township'] ?? ''), $prefLocation) !== false
                    || stripos((string)($p['location'] ?? ''), $prefLocation) !== false
                    || stripos((string)($p['state']    ?? ''), $prefLocation) !== false
                ),
            'In your preferred area', 'positive',
            'Property location matches the area you specified in your assessment.'],

        ['F13', 'Family',
            fn($p) => $prefType !== 'any'
                && (
                    strcasecmp($prefType, (string)($p['type']          ?? '')) === 0
                    || strcasecmp($prefType, (string)($p['property_type'] ?? '')) === 0
                ),
            'Matches your preferred property type', 'positive',
            'Property type matches your stated preference.'],

        ['F14', 'Family',
            fn($p) => strtolower((string)($p['tenure'] ?? '')) === 'freehold',
            'Freehold tenure', 'positive',
            'Freehold ownership — permanent land title, generally higher resale value.'],

        ['F15', 'Family',
            fn($p) => strtolower((string)($p['tenure'] ?? '')) === 'leasehold',
            'Leasehold tenure', 'info',
            'Leasehold property — check remaining lease years before purchasing.'],

        ['F16', 'Family',
            fn($p) => (int)($p['family_score'] ?? 0) >= 85,
            'High family suitability score (≥ 85)', 'positive',
            'Property scores 85 or above on the family suitability index.'],


        // ════════════════════════════════════════════════════════════
        // DOMAIN 4: SMART HOME READINESS  (H01–H18)
        // ════════════════════════════════════════════════════════════

        ['H01', 'Smart Home',
            fn($p) => (int)($p['smart_readiness_score'] ?? 0) >= 90,
            'Exceptional smart-home readiness (≥ 90)', 'positive',
            'Property is in the top tier for smart home infrastructure.'],

        ['H02', 'Smart Home',
            fn($p) => (int)($p['smart_readiness_score'] ?? 0) >= 75
                && (int)($p['smart_readiness_score'] ?? 0) < 90,
            'Good smart-home readiness (75–89)', 'positive',
            'Property supports most smart home requirements.'],

        ['H03', 'Smart Home',
            fn($p) => (int)($p['smart_readiness_score'] ?? 0) < 60
                && (int)($p['smart_readiness_score'] ?? 0) > 0,
            'Limited smart-home readiness (< 60)', 'warning',
            'Property may need significant upgrades to support smart home devices.'],

        ['H04', 'Smart Home',
            fn($p) => $wantsLighting && (int)($p['smart_readiness_score'] ?? 0) >= 75,
            'Smart lighting compatible', 'positive',
            'You want smart lighting and this property has sufficient smart readiness.'],

        ['H05', 'Smart Home',
            fn($p) => $wantsSecurity && (int)($p['security_score'] ?? 0) >= 80,
            'Smart security ready', 'positive',
            'You want smart security and this property scores 80+ on security.'],

        ['H06', 'Smart Home',
            fn($p) => $wantsAppliances && (int)($p['smart_readiness_score'] ?? 0) >= 80,
            'Smart appliances compatible', 'positive',
            'You want smart appliances and the property infrastructure supports it.'],

        ['H07', 'Smart Home',
            fn($p) => $wantsEnergy && (int)($p['sustainability_score'] ?? 0) >= 80,
            'Smart energy monitoring ready', 'positive',
            'You want smart energy monitoring and this property scores 80+ on sustainability.'],

        ['H08', 'Smart Home',
            fn($p) => $smartCount >= 3 && (int)($p['smart_readiness_score'] ?? 0) >= 85,
            'Full smart home package match', 'positive',
            'You want 3+ smart features and this property is highly ready across the board.'],

        ['H09', 'Smart Home',
            fn($p) => $smartCount >= 3 && (int)($p['smart_readiness_score'] ?? 0) < 70,
            'Smart requirements may not be fully met', 'warning',
            'You want multiple smart features but this property has a smart readiness score below 70.'],

        ['H10', 'Smart Home',
            fn($p) => (int)($p['sustainability_score'] ?? 0) >= 85,
            'Highly sustainable property (≥ 85)', 'positive',
            'Sustainability score is 85 or above — energy-efficient and eco-friendly features expected.'],

        ['H11', 'Smart Home',
            fn($p) => (int)($p['sustainability_score'] ?? 0) < 55
                && (int)($p['sustainability_score'] ?? 0) > 0,
            'Low sustainability score (< 55)', 'info',
            'Property scores below 55 on sustainability — may have higher running costs.'],

        ['H12', 'Smart Home',
            fn($p) => $comfortCategory === 'ENERGY'
                && (int)($p['sustainability_score'] ?? 0) >= 80,
            'Matches your energy efficiency priority', 'positive',
            'You prioritised energy efficiency and this property has a sustainability score of 80+.'],

        ['H13', 'Smart Home',
            fn($p) => $comfortCategory === 'ACOUSTIC'
                && (int)($p['acoustic_score'] ?? 0) >= 80,
            'Matches your acoustic comfort priority', 'positive',
            'You prioritised acoustic comfort and this property scores 80+ on acoustics.'],

        // FIX (audit item #5): H14 ("Matches your security comfort priority",
        // triggered by str_contains($comfortPri, 'security')) has been
        // removed. No UI path can produce a comfort_priority value containing
        // "security" — the closed set is "Family growth" / "Acoustic
        // comfort" / "Energy efficiency" only — so this rule was dead code
        // that could never fire. If a security-focused comfort priority is
        // wanted, add "Security comfort" as a real option in
        // assets/js/app.js's slidersToAssessmentFields() and to the
        // $comfortPriorityMap above, then reintroduce this rule keyed off
        // $comfortCategory === 'SECURITY'.

        ['H15', 'Smart Home',
            fn($p) => $comfortCategory === 'FAMILY'
                && (int)($p['family_score'] ?? 0) >= 80,
            'Matches your family growth priority', 'positive',
            'You prioritised family growth and this property scores 80+ on family suitability.'],

        ['H16', 'Smart Home',
            fn($p) => (int)($p['acoustic_score'] ?? 0) >= 85,
            'Excellent acoustic comfort (≥ 85)', 'positive',
            'Acoustic score is 85 or above — well-insulated from noise.'],

        ['H17', 'Smart Home',
            fn($p) => (int)($p['acoustic_score'] ?? 0) < 55 && (int)($p['acoustic_score'] ?? 0) > 0,
            'Poor acoustic comfort (< 55)', 'info',
            'Acoustic score is below 55 — may experience noise issues.'],

        ['H18', 'Smart Home',
            fn($p) => (int)($p['security_score'] ?? 0) >= 90,
            'Top-tier security infrastructure (≥ 90)', 'positive',
            'Security score of 90 or above — highest level of security readiness.'],


        // ════════════════════════════════════════════════════════════
        // DOMAIN 5: SPACE & PHYSICAL SUITABILITY  (P01–P10)
        // ════════════════════════════════════════════════════════════

        ['P01', 'Space',
            fn($p) => max((int)($p['house_size_sqft'] ?? 0), (int)($p['built_up_sqft'] ?? 0)) >= 1500,
            'Spacious home (≥ 1,500 sqft)', 'positive',
            'Total built-up area is 1,500 sqft or more — generous living space.'],

        ['P02', 'Space',
            fn($p) => max((int)($p['house_size_sqft'] ?? 0), (int)($p['built_up_sqft'] ?? 0)) >= 2000,
            'Large home (≥ 2,000 sqft)', 'positive',
            'Total built-up area is 2,000 sqft or more — ideal for large families.'],

        ['P03', 'Space',
            fn($p) => max((int)($p['house_size_sqft'] ?? 0), (int)($p['built_up_sqft'] ?? 0)) < 700
                && $household >= 4,
            'Small unit for household size', 'warning',
            'Under 700 sqft with a household of 4+ — space will be very tight.'],

        ['P04', 'Space',
            fn($p) => max((int)($p['house_size_sqft'] ?? 0), (int)($p['built_up_sqft'] ?? 0)) > 0
                && $household > 0
                && (max((int)($p['house_size_sqft'] ?? 0), (int)($p['built_up_sqft'] ?? 0)) / $household) >= 300,
            'Generous space per person (≥ 300 sqft each)', 'positive',
            'Each household member has 300+ sqft — comfortable per-person space allocation.'],

        ['P05', 'Space',
            fn($p) => max((int)($p['house_size_sqft'] ?? 0), (int)($p['built_up_sqft'] ?? 0)) > 0
                && $household > 0
                && (max((int)($p['house_size_sqft'] ?? 0), (int)($p['built_up_sqft'] ?? 0)) / $household) < 150,
            'Cramped space per person (< 150 sqft each)', 'warning',
            'Less than 150 sqft per person — below comfortable living standards for household size.'],

        ['P06', 'Space',
            fn($p) => (int)($p['bathrooms'] ?? 0) > 0
                && (int)($p['bedrooms'] ?? 0) > 0
                && (int)($p['bathrooms'] ?? 0) >= (int)($p['bedrooms'] ?? 0),
            'At least one bathroom per bedroom', 'positive',
            'Number of bathrooms matches or exceeds number of bedrooms — convenient.'],

        ['P07', 'Space',
            fn($p) => (int)($p['bedrooms'] ?? 0) >= 2 && (int)($p['bedrooms'] ?? 0) <= 3
                && $household <= 3,
            'Right-sized for your household', 'positive',
            '2–3 bedroom home for a household of 1–3 — well-matched size.'],

        ['P08', 'Space',
            fn($p) => (int)($p['bedrooms'] ?? 0) >= 5,
            'Very large home — 5+ bedrooms', 'positive',
            'Five or more bedrooms — suitable for extended families or home office needs.'],

        ['P09', 'Space',
            fn($p) => max((int)($p['house_size_sqft'] ?? 0), (int)($p['built_up_sqft'] ?? 0)) >= 800
                && max((int)($p['house_size_sqft'] ?? 0), (int)($p['built_up_sqft'] ?? 0)) <= 1200
                && $household <= 4,
            'Practical mid-size home', 'positive',
            '800–1,200 sqft — practical and manageable for households up to 4.'],

        ['P10', 'Space',
            fn($p) => (int)($p['bedrooms'] ?? 0) > $household + 2,
            'More bedrooms than needed — potential for rental', 'info',
            'Property has significantly more bedrooms than household size — extra rooms could be rented out.'],


        // ════════════════════════════════════════════════════════════
        // DOMAIN 6: INVESTMENT VALUE  (I01–I14)
        // ════════════════════════════════════════════════════════════

        ['I01', 'Investment',
            fn($p) => (float)($p['estimated_rental_yield_pct'] ?? 0) >= 5.0,
            'Excellent rental yield (≥ 5%)', 'positive',
            'Rental yield of 5% or above — strong passive income potential.'],

        ['I02', 'Investment',
            fn($p) => (float)($p['estimated_rental_yield_pct'] ?? 0) >= 4.0
                && (float)($p['estimated_rental_yield_pct'] ?? 0) < 5.0,
            'Good rental yield (4–5%)', 'positive',
            'Rental yield is in the 4–5% range — solid investment return.'],

        ['I03', 'Investment',
            fn($p) => (float)($p['estimated_rental_yield_pct'] ?? 0) > 0
                && (float)($p['estimated_rental_yield_pct'] ?? 0) < 2.5,
            'Low rental yield (< 2.5%)', 'warning',
            'Rental yield is below 2.5% — poor return for buy-to-let investors.'],

        ['I04', 'Investment',
            fn($p) => (float)($p['historical_capital_appreciation_3yr_pct'] ?? 0) >= 7.0,
            'Strong 3-year capital growth (≥ 7%)', 'positive',
            '3-year capital appreciation is 7% or above — excellent long-term value growth.'],

        ['I05', 'Investment',
            fn($p) => (float)($p['historical_capital_appreciation_3yr_pct'] ?? 0) >= 5.0
                && (float)($p['historical_capital_appreciation_3yr_pct'] ?? 0) < 7.0,
            'Good 3-year capital growth (5–7%)', 'positive',
            'Solid capital appreciation over the past 3 years.'],

        ['I06', 'Investment',
            fn($p) => (float)($p['historical_capital_appreciation_3yr_pct'] ?? 0) > 0
                && (float)($p['historical_capital_appreciation_3yr_pct'] ?? 0) < 2.0,
            'Slow capital growth (< 2%)', 'info',
            '3-year capital appreciation is below 2% — limited price growth.'],

        ['I07', 'Investment',
            fn($p) => (float)($p['historical_capital_appreciation_3yr_pct'] ?? 0) < 0,
            'Negative capital growth — price declining', 'warning',
            'Property value has declined over the past 3 years.'],

        ['I08', 'Investment',
            fn($p) => (int)($p['transactions'] ?? 0) >= 100,
            'Highly active resale market (100+ transactions)', 'positive',
            '100 or more recorded transactions — very liquid market.'],

        ['I09', 'Investment',
            fn($p) => (int)($p['transactions'] ?? 0) >= 50
                && (int)($p['transactions'] ?? 0) < 100,
            'Active resale market (50–99 transactions)', 'positive',
            'Good transaction volume — reasonable market liquidity.'],

        ['I10', 'Investment',
            fn($p) => (int)($p['transactions'] ?? 0) < 10
                && (int)($p['transactions'] ?? 0) >= 0,
            'Low transaction volume — illiquid market', 'info',
            'Fewer than 10 recorded transactions — may be harder to resell quickly.'],

        ['I11', 'Investment',
            fn($p) => (float)($p['estimated_rental_yield_pct'] ?? 0) >= 4.0
                && (float)($p['historical_capital_appreciation_3yr_pct'] ?? 0) >= 5.0,
            'Dual growth — strong yield AND capital gain', 'positive',
            'Both rental yield (≥4%) and capital appreciation (≥5%) are strong — rare combination.'],

        ['I12', 'Investment',
            fn($p) => strtolower((string)($p['tenure'] ?? '')) === 'freehold'
                && (float)($p['historical_capital_appreciation_3yr_pct'] ?? 0) >= 4.0,
            'Freehold with good capital growth', 'positive',
            'Freehold property with 4%+ appreciation — strong long-term store of value.'],

        ['I13', 'Investment',
            fn($p) => strtolower((string)($p['tenure'] ?? '')) === 'leasehold'
                && (float)($p['estimated_rental_yield_pct'] ?? 0) >= 5.0,
            'Leasehold but high rental yield', 'info',
            'Leasehold property with 5%+ yield — good for rental income despite tenure type.'],

        ['I14', 'Investment',
            fn($p) => (float)($p['median_psf'] ?? 0) < 300
                && (float)($p['historical_capital_appreciation_3yr_pct'] ?? 0) >= 4.0,
            'Low PSF with rising value — undervalued', 'positive',
            'Below RM 300 PSF with 4%+ appreciation — signs of an undervalued, growing area.'],


        // ════════════════════════════════════════════════════════════
        // DOMAIN 7: LOCATION QUALITY  (L01–L06)
        // ════════════════════════════════════════════════════════════

        ['L01', 'Location',
            fn($p) => (float)($p['distance_to_public_transport_km'] ?? 99) <= 0.5,
            'Directly next to public transport (< 500m)', 'positive',
            'Within 500 metres of public transport — exceptional commute convenience.'],

        ['L02', 'Location',
            fn($p) => (float)($p['distance_to_mall_km'] ?? 99) <= 1.0,
            'Mall within 1 km', 'positive',
            'Shopping centre is under 1 km — very walkable for daily needs.'],

        ['L03', 'Location',
            fn($p) => (float)($p['distance_to_hospital_km'] ?? 99) <= 3.0,
            'Hospital within 3 km', 'positive',
            'Nearest hospital is within 3 km — excellent for medical emergencies.'],

        ['L04', 'Location',
            fn($p) => (float)($p['distance_to_school_km'] ?? 99) <= 0.5,
            'School within walking distance (< 500m)', 'positive',
            'School is under 500 metres — ideal for young children.'],

        ['L05', 'Location',
            fn($p) => (float)($p['distance_to_public_transport_km'] ?? 99) <= 1.0
                && (float)($p['distance_to_mall_km'] ?? 99) <= 3.0
                && (float)($p['distance_to_school_km'] ?? 99) <= 3.0
                && (float)($p['distance_to_hospital_km'] ?? 99) <= 5.0,
            'All key amenities within reach', 'positive',
            'Transport, mall, school, and hospital are all within close range — prime location score.'],

        ['L06', 'Location',
            fn($p) => (float)($p['distance_to_public_transport_km'] ?? 99) > 3.0
                && (float)($p['distance_to_mall_km'] ?? 99) > 8.0,
            'Remote location — limited amenity access', 'info',
            'Both transport and shopping are more than 3 km and 8 km away respectively — car essential.'],


        // ════════════════════════════════════════════════════════════
        // DOMAIN 8: OCCUPATION SUITABILITY  (O01–O12)
        // All income comparisons use NET income (gaji bersih) so that
        // a high-salary person with heavy obligations is not incorrectly
        // flagged as "low risk" purely because their gross pay is large.
        // ════════════════════════════════════════════════════════════

        ['O01', 'Occupation',
            fn($p) => $isGovt
                && (float)($p['est_monthly_mortgage_rm'] ?? 0) > 0
                && $netIncome > 0
                && ((float)($p['est_monthly_mortgage_rm'] ?? 0) / $netIncome) <= 0.35,
            'Stable government income — mortgage is manageable', 'positive',
            'Government/public sector employees have stable income. After your existing commitments, the mortgage is within 35% of net income — low risk.'],

        ['O02', 'Occupation',
            fn($p) => $isSelfEmployed
                && $budget > 0
                && (float)($p['median_price'] ?? $p['price'] ?? 0) <= $budget * 0.85,
            'Conservative pick for self-employed buyer', 'positive',
            'Self-employed income can be variable. This property is well within budget — reduces financial risk.'],

        ['O03', 'Occupation',
            fn($p) => $isSelfEmployed
                && $budget > 0
                && (float)($p['median_price'] ?? $p['price'] ?? 0) > $budget,
            'Caution: self-employed buyers may face stricter bank scrutiny above budget', 'warning',
            'Banks typically apply stricter DSR rules for self-employed applicants. Going over budget increases loan rejection risk.'],

        ['O04', 'Occupation',
            fn($p) => $isHighIncome
                && $commitmentRatio <= 0.30
                && (float)($p['smart_readiness_score'] ?? 0) >= 80
                && (float)($p['sustainability_score'] ?? 0) >= 75,
            'Premium smart home — suits your professional profile', 'positive',
            'High-income professionals with manageable commitments typically value smart, sustainable homes. This property scores well on both.'],

        ['O05', 'Occupation',
            fn($p) => $isHighIncome
                && (float)($p['estimated_rental_yield_pct'] ?? 0) >= 4.0
                && (float)($p['historical_capital_appreciation_3yr_pct'] ?? 0) >= 4.0,
            'Strong investment asset for high-income buyer', 'positive',
            'High-income earners often use property as an investment vehicle. This property has both good yield and capital growth.'],

        // O05b: Flag when a high earner has heavy commitments — their net capacity
        // may be no better than a moderate-income buyer with none.
        //
        // FIX (audit item #4): O05 (positive: good investment yield) and O05b
        // (warning: heavy commitments) can legitimately both be true for the
        // same property — they describe two different facts (investment
        // quality vs. repayment stress), not a contradiction. Previously they
        // were surfaced as two independent, unranked badges with no signal to
        // the consumer that they relate to the same buyer profile. O05b is now
        // tagged related_to O05 (not conflicts_with, since both can be valid
        // simultaneously) so formatRuleResult() can group them into a single
        // "mixed signal" pairing instead of presenting them as unrelated.
        ['O05b', 'Occupation',
            fn($p) => $isHighIncome
                && $commitmentRatio > 0.40
                && (float)($p['est_monthly_mortgage_rm'] ?? 0) > 0
                && (float)($p['est_monthly_mortgage_rm'] ?? 0) > $netIncome * 0.40,
            'High income but heavy commitments — net capacity may be limited', 'warning',
            'Despite a high salary, your existing obligations consume over 40% of gross income. Your actual repayment capacity (net income) may be similar to a lower-income buyer with no commitments.',
            [], ['O05']],

        ['O06', 'Occupation',
            fn($p) => $isStudent
                && $budget > 0
                && (float)($p['median_price'] ?? $p['price'] ?? 0) <= $budget * 0.80,
            'Budget-friendly choice for a first-time student buyer', 'positive',
            'Student or fresh graduate buyers benefit from well-priced properties with room to grow equity.'],

        ['O07', 'Occupation',
            fn($p) => $isStudent
                && (float)($p['distance_to_public_transport_km'] ?? 99) <= 1.5,
            'Good transport access — important for students', 'positive',
            'Students and graduates often rely on public transport. This property is within 1.5 km of a transit point.'],

        ['O08', 'Occupation',
            fn($p) => $isRetired
                && in_array(strtolower((string)($p['flood_risk'] ?? '')), ['low', 'very low'], true)
                && (float)($p['safety_score'] ?? 0) >= 70,
            'Safe, low-risk area — well suited for retirement', 'positive',
            'Retirees benefit from low flood risk and high safety scores. This property meets both criteria.'],

        ['O09', 'Occupation',
            fn($p) => $isRetired
                && (float)($p['distance_to_hospital_km'] ?? 99) <= 5.0,
            'Hospital nearby — important for retirees', 'positive',
            'Proximity to healthcare is a key factor for retirees. Nearest hospital is within 5 km.'],

        ['O10', 'Occupation',
            fn($p) => $isRetired
                && (float)($p['est_monthly_mortgage_rm'] ?? 0) > 0
                && $netIncome > 0
                && ((float)($p['est_monthly_mortgage_rm'] ?? 0) / $netIncome) > 0.30,
            'Mortgage may be high relative to retirement net income', 'warning',
            'Retirees typically have fixed income. After existing commitments, this mortgage exceeds 30% of net income — elevated risk.'],

        ['O11', 'Occupation',
            fn($p) => $isGovt
                && strtolower((string)($p['tenure'] ?? '')) === 'freehold',
            'Freehold property — good long-term asset for civil servants', 'positive',
            'Government employees with stable long-term income are well-positioned to hold freehold property as a generational asset.'],

        ['O12', 'Occupation',
            fn($p) => $isSelfEmployed
                && (float)($p['estimated_rental_yield_pct'] ?? 0) >= 4.5,
            'Rental income potential helps offset variable self-employment income', 'positive',
            'A strong rental yield can supplement irregular self-employment earnings, improving overall financial resilience.'],

    ];
}

// FIX (audit item #4): rules previously had no conflict-resolution layer.
// evaluateRules() collected every condition that returned true into one flat
// list, so two rules describing the same underlying fact with opposite
// severities (e.g. the old A10/A18 overlap) could both reach the user as
// independent, unranked badges with no signal that they contradict each
// other.
//
// Each rule tuple may now optionally carry two extra elements:
//   [6] conflicts_with  — array of rule IDs that describe the SAME fact with
//                          a DIFFERENT conclusion. If both fire, the rule
//                          listed later in the rules array wins (rules are
//                          authored in increasing specificity order within
//                          each domain, so "later" means "more specific").
//                          The loser is dropped entirely, not just hidden.
//   [7] related_with     — array of rule IDs that describe a DIFFERENT but
//                          relevant fact about the same profile (both can be
//                          legitimately true at once, e.g. O05/O05b). These
//                          are not suppressed — they are grouped together in
//                          the output so the consumer can render them as a
//                          single "mixed signal" pairing instead of two
//                          unrelated badges.
//
// Rules that omit these elements default to no conflicts and no relations,
// so all 108 pre-existing rule definitions work unchanged.
function evaluateRules(array $property, array $rules): array
{
    $fired = [];
    foreach ($rules as $rule) {
        [$id, $domain, $condition, $label, $severity, $explanation] = $rule;
        $conflictsWith = $rule[6] ?? [];
        $relatedWith   = $rule[7] ?? [];

        if ($condition($property)) {
            $fired[$id] = [
                'rule'           => $id,
                'domain'         => $domain,
                'label'          => $label,
                'severity'       => $severity,
                'explanation'    => $explanation,
                'conflicts_with' => $conflictsWith,
                'related_with'   => $relatedWith,
            ];
        }
    }

    // ── Conflict resolution pass ───────────────────────────────────────────
    // IF a fired rule's conflicts_with list names another fired rule THEN
    // drop the rule that names the conflict (it is the more specific /
    // later-authored refinement and is treated as authoritative for that
    // fact) and drop the rule it targets.
    $firedIds = array_keys($fired);
    foreach ($fired as $id => $rule) {
        if (!isset($fired[$id])) {
            continue; // already removed by an earlier iteration
        }
        foreach ($rule['conflicts_with'] as $conflictId) {
            if (isset($fired[$conflictId])) {
                unset($fired[$conflictId]);
            }
        }
    }

    // ── Relation pass ───────────────────────────────────────────────────────
    // IF a fired rule's related_with list names another fired rule THEN tag
    // both with a shared group_id so the consumer can render them together
    // instead of as unrelated badges.
    foreach ($fired as $id => $rule) {
        foreach ($rule['related_with'] as $relatedId) {
            if (isset($fired[$relatedId])) {
                $groupId = implode('+', array_unique(array_merge(
                    [$id],
                    array_intersect($rule['related_with'], $firedIds)
                )));
                $fired[$id]['related_group'] = $groupId;
                if (isset($fired[$relatedId])) {
                    $fired[$relatedId]['related_group'] = $groupId;
                }
            }
        }
    }

    return array_values($fired);
}

function formatRuleResult(array $property, array $rules): array
{
    $fired = evaluateRules($property, $rules);

    // Strip internal-only conflict-resolution metadata (conflicts_with,
    // related_with — these are rule-ID arrays used to compute related_group
    // above) before returning to the API consumer. related_group itself is
    // kept since it's the useful output: a shared identifier the frontend can
    // use to visually group rules that describe related-but-distinct facts
    // (e.g. O05 + O05b) instead of presenting them as unconnected badges.
    $publicize = static function (array $rule): array {
        unset($rule['conflicts_with'], $rule['related_with']);
        return $rule;
    };
    $fired = array_map($publicize, $fired);

    return [
        'property_id'   => (int) $property['id'],
        'property_name' => $property['property_name'] ?? $property['township'] ?? 'Property',
        'rules_fired'   => array_values(array_filter($fired, fn ($rule) => $rule['severity'] !== 'warning')),
        'warnings'      => array_values(array_filter($fired, fn ($rule) => $rule['severity'] === 'warning')),
        'rule_count'    => count($fired),
    ];
}

try {
    $pdo    = Database::connect();
    $budget = (float) ($_GET['budget'] ?? 0);
    $assessment = [];

    if (Auth::check()) {
        $stmt = $pdo->prepare(
            'SELECT * FROM assessments WHERE user_id = ? ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([(int) Auth::user()['id']]);
        $assessment = $stmt->fetch() ?: [];
        if ($budget === 0.0 && !empty($assessment['budget'])) {
            $budget = (float) $assessment['budget'];
        }
        // Merge occupation from users table into assessment so rules can use it
        $uStmt = $pdo->prepare('SELECT occupation FROM users WHERE id = ? LIMIT 1');
        $uStmt->execute([(int) Auth::user()['id']]);
        $uRow = $uStmt->fetch();
        if ($uRow && !empty($uRow['occupation'])) {
            $assessment['occupation'] = $uRow['occupation'];
        }
    }

    $rules = buildRules($budget, $assessment);

    $rawIds = $_GET['ids'] ?? [];
    if (!empty($rawIds) && is_array($rawIds)) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds), fn ($id) => $id > 0)));
        $ids = array_slice($ids, 0, 20);

        if ($ids === []) {
            respond(['success' => false, 'message' => 'Provide at least one valid property ID.'], 422);
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM properties WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $properties = $stmt->fetchAll();

        $results = [];
        foreach ($properties as $property) {
            $results[] = formatRuleResult($property, $rules);
        }

        respond(['success' => true, 'count' => count($results), 'data' => $results]);
    }

    $propertyId = (int) ($_GET['property_id'] ?? 0);
    if ($propertyId <= 0) {
        respond(['success' => false, 'message' => 'Provide property_id or ids[] parameter.'], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM properties WHERE id = ? LIMIT 1');
    $stmt->execute([$propertyId]);
    $property = $stmt->fetch();

    if (!$property) {
        respond(['success' => false, 'message' => 'Property not found.'], 404);
    }

    respond(['success' => true] + formatRuleResult($property, $rules));

} catch (Throwable $exception) {
    respond([
        'success' => false,
        'message' => 'Rules engine error.',
        'error'   => $exception->getMessage(),
    ], 500);
}
