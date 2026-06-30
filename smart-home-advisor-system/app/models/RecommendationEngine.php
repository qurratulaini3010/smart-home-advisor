<?php
declare(strict_types=1);

final class RecommendationEngine
{
    public const WEIGHTS = [
        'affordability' => 0.30,
        'security'      => 0.20,
        'smart'         => 0.20,
        'environment'   => 0.15,
        'family'        => 0.15,
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Occupation classifier — single canonical definition used by both the
    // scoring engine and rules.php (via OccupationClassifier helper).
    //
    // FIX (audit item #1/#2): occupation categories are now MUTUALLY EXCLUSIVE.
    // Previously each category (isGovt, isSelfEmployed, isHighIncome, isRetired,
    // isStudent) was computed independently, so a single occupation string like
    // "retired government lecturer" or "self-employed government contractor"
    // could satisfy two or more categories at once. Downstream, the scoring
    // engine and the rules.php badge engine then stacked bonuses/penalties from
    // every matching category simultaneously, with no defined precedence —
    // an undocumented, non-deterministic outcome for any ambiguous title.
    //
    // The classifier now resolves occupation to exactly ONE category, decided
    // by an explicit priority order (most specific / most consequential life
    // stage first):
    //   1. RETIRED        — fixed/pension income overrides any prior job title
    //   2. STUDENT         — no independent income; overrides job-like words
    //   3. SELF_EMPLOYED   — variable income risk profile
    //   4. GOVERNMENT      — stable income profile
    //   5. HIGH_INCOME     — higher repayment capacity, no special risk profile
    //   6. GENERAL         — no category matched
    //
    // Example: "retired civil servant" -> matches both retire-pattern and
    // govt-pattern, but RETIRED is checked first and wins, so the person is
    // scored using retiree rules only (fixed-income / healthcare-proximity
    // logic), not also given the government stable-income bonus.
    //
    // All five original boolean keys are still returned (so existing call
    // sites in this file and in rules.php do not need to change their
    // variable names), but they are now derived from the single resolved
    // category, guaranteeing at most one is ever true.
    // ─────────────────────────────────────────────────────────────────────────
    public const OCCUPATION_CATEGORIES = [
        'RETIRED', 'STUDENT', 'SELF_EMPLOYED', 'GOVERNMENT', 'HIGH_INCOME', 'GENERAL',
    ];

    public static function resolveOccupationCategory(string $occupation): string
    {
        $o = strtolower(trim($occupation));

        // IF occupation matches retirement/pension terms THEN category = RETIRED, STOP.
        if (str_contains($o, 'retire') || str_contains($o, 'pensioner') || str_contains($o, 'pension')) {
            return 'RETIRED';
        }

        // ELSE IF occupation matches student/intern terms THEN category = STUDENT, STOP.
        if (str_contains($o, 'student') || str_contains($o, 'intern')
            || str_contains($o, 'graduate') || str_contains($o, 'scholar')) {
            return 'STUDENT';
        }

        // ELSE IF occupation matches self-employment terms THEN category = SELF_EMPLOYED, STOP.
        if (str_contains($o, 'self')             || str_contains($o, 'freelance')
            || str_contains($o, 'business owner') || str_contains($o, 'entrepreneur')
            || str_contains($o, 'contractor')     || str_contains($o, 'consultant')
            || str_contains($o, 'trader')         || str_contains($o, 'hawker')) {
            return 'SELF_EMPLOYED';
        }

        // ELSE IF occupation matches government/public-sector terms THEN category = GOVERNMENT, STOP.
        if (str_contains($o, 'civil')           || str_contains($o, 'government')
            || str_contains($o, 'public sector') || str_contains($o, 'polis')
            || str_contains($o, 'army')          || str_contains($o, 'tentera')
            || str_contains($o, 'teacher')       || str_contains($o, 'lecturer')
            || str_contains($o, 'professor')     || str_contains($o, 'nurse')
            || str_contains($o, 'doctor')        || str_contains($o, 'physician')) {
            return 'GOVERNMENT';
        }

        // ELSE IF occupation matches high-income professional terms THEN category = HIGH_INCOME, STOP.
        if (str_contains($o, 'engineer')   || str_contains($o, 'lawyer')
            || str_contains($o, 'attorney') || str_contains($o, 'architect')
            || str_contains($o, 'surgeon')  || str_contains($o, 'specialist')
            || str_contains($o, 'director') || str_contains($o, 'manager')
            || str_contains($o, 'executive') || str_contains($o, 'ceo')
            || str_contains($o, 'accountant') || str_contains($o, 'banker')
            || str_contains($o, 'pilot')     || str_contains($o, 'pharmacist')) {
            return 'HIGH_INCOME';
        }

        // ELSE category = GENERAL (no special occupation-based adjustments apply).
        return 'GENERAL';
    }

    public static function classifyOccupation(string $occupation): array
    {
        $category = self::resolveOccupationCategory($occupation);
        return [
            'category'       => $category,
            'isGovt'         => $category === 'GOVERNMENT',
            'isSelfEmployed' => $category === 'SELF_EMPLOYED',
            'isHighIncome'   => $category === 'HIGH_INCOME',
            'isRetired'      => $category === 'RETIRED',
            'isStudent'      => $category === 'STUDENT',
        ];
    }

    public static function score(array $assessment, array $property, ?array $weights = null): array
    {
        $weights = $weights ?: self::WEIGHTS;
        $budget  = max((float) $assessment['budget'], 1);
        $price   = (float) ($property['median_price'] ?? $property['price'] ?? 0);
        $location = (string) ($property['area'] ?? $property['location'] ?? '');
        $type    = (string) ($property['type'] ?? $property['property_type'] ?? '');
        $bedrooms = (int) ($property['bedrooms'] ?? 0);

        // Use net income (gaji bersih) for all repayment-capacity calculations.
        $grossIncome = (float) ($assessment['monthly_income'] ?? 0);
        $commitment  = (float) ($assessment['monthly_commitment'] ?? 0);
        $netIncome   = (float) ($assessment['net_income']
                        ?? max(0, $grossIncome - $commitment));

        // ── Base affordability: budget vs price ───────────────────────────────
        // FIX (audit item #3): the previous two-branch formula was discontinuous
        // exactly at price == budget. The over-budget branch
        // (100 - (price-budget)/budget*100) reaches 100 only when price == 0
        // and equals 0 at price == 2*budget, while the under-budget branch
        // independently produces 86 at price == budget and up to 100 at
        // price == 0. A property priced 1% under budget could therefore score
        // very differently from one priced exactly at budget, and the two
        // formulas were never required to agree at the boundary they share.
        //
        // Both branches now derive from the same ratio (price / budget) and
        // are pinned to meet at exactly 86 when ratio == 1.0, so the curve is
        // continuous across the budget boundary:
        //   IF price/budget <= 1.0 THEN score = 86 + (1 - ratio) * 14   (86 -> 100)
        //   ELSE                    score = 86 - (ratio - 1) * 100      (86 -> 0)
        $priceRatio = $price / $budget;
        if ($priceRatio <= 1.0) {
            $affordability = min(100, 86 + (1 - $priceRatio) * 14);
        } else {
            $affordability = max(0, 86 - ($priceRatio - 1) * 100);
        }

        // Adjust affordability using net income (not gross).
        $mortgage = (float) ($property['est_monthly_mortgage_rm'] ?? 0);
        if ($netIncome > 0 && $mortgage > 0) {
            $netRatio = $mortgage / $netIncome;
            if ($netRatio <= 0.25) {
                $affordability = min(100, $affordability + 8);   // Very comfortable
            } elseif ($netRatio <= 0.35) {
                $affordability = min(100, $affordability + 3);   // Manageable
            } elseif ($netRatio > 0.50) {
                $affordability = max(0, $affordability - 12);    // Strained
            } elseif ($netRatio > 0.40) {
                $affordability = max(0, $affordability - 6);     // Tight
            }
        }

        // ── Occupation-based scoring adjustments ──────────────────────────────
        // FIX: Use the canonical classifyOccupation() helper so both the scoring
        // engine and rules.php use identical keyword lists.
        $occupation = (string) ($assessment['occupation'] ?? '');
        $occ = self::classifyOccupation($occupation);
        $isGovt        = $occ['isGovt'];
        $isSelfEmployed = $occ['isSelfEmployed'];
        $isHighIncome  = $occ['isHighIncome'];
        $isRetired     = $occ['isRetired'];

        // Commitment ratio — used to temper occupation bonuses
        $commitmentRatio = $grossIncome > 0 ? $commitment / $grossIncome : 0;
        $hospitalKm      = (float) ($property['distance_to_hospital_km'] ?? 99);

        // Government employees: stable income bonus when commitments are manageable
        if ($isGovt && $price <= $budget && $commitmentRatio <= 0.35) {
            $affordability = min(100, $affordability + 5);
        }

        // Self-employed: penalise if price exceeds budget (higher bank risk)
        if ($isSelfEmployed && $price > $budget) {
            $affordability = max(0, $affordability - 10);
        }
        // Self-employed: reward conservative picks
        if ($isSelfEmployed && $price <= $budget * 0.85) {
            $affordability = min(100, $affordability + 5);
        }

        // High-income with low commitments — genuinely more net capacity
        if ($isHighIncome && $commitmentRatio <= 0.20) {
            $affordability = min(100, $affordability + 4);
        }
        // High-income but heavy commitments — net capacity eroded
        if ($isHighIncome && $commitmentRatio > 0.40) {
            $affordability = max(0, $affordability - 5);
        }
        // ─────────────────────────────────────────────────────────────────────

        // ── Smart score ───────────────────────────────────────────────────────
        $smartNeed = (
            (int) $assessment['smart_lighting']   +
            (int) $assessment['smart_security']   +
            (int) $assessment['smart_appliances'] +
            (int) $assessment['smart_energy']
        );
        $smart = min(100, (float) $property['smart_readiness_score'] + ($smartNeed * 3));
        if ($isHighIncome && $commitmentRatio <= 0.30) {
            $smart = min(100, $smart + 5);
        }

        // ── Security score ────────────────────────────────────────────────────
        $security = (float) $property['security_score'];
        if ((int) $assessment['smart_security'] === 1) {
            $security = min(100, $security + 8);
        }

        // ── Environment score ─────────────────────────────────────────────────
        // FIX: Removed the dead-code retiree block that previously appeared
        // before line 137 and was immediately overwritten. The retiree
        // adjustment is applied once, correctly, below.
        $environment = (float) $property['sustainability_score'];
        if ((int) $assessment['smart_energy'] === 1) {
            $environment = min(100, $environment + 7);
        }
        if ($isHighIncome && $commitmentRatio <= 0.30) {
            $environment = min(100, $environment + 4);
        }
        // Retirees: hospital proximity affects environment quality of life
        if ($isRetired && $hospitalKm <= 5.0) {
            $environment = min(100, $environment + 8);
        } elseif ($isRetired && $hospitalKm > 15.0) {
            $environment = max(0, $environment - 10);
        }

        // ── Family score ──────────────────────────────────────────────────────
        $family = (float) $property['family_score'];
        if ((int) $assessment['household_size'] >= 4 && $bedrooms >= 3) {
            $family = min(100, $family + 8);
        }
        if ($isRetired && (float) ($property['safety_score'] ?? 0) >= 70) {
            $family = min(100, $family + 6);
        }

        // Location & type match adjustments (apply to both family and affordability)
        if (!empty($assessment['preferred_location'])
            && stripos($location, $assessment['preferred_location']) !== false) {
            $family        = min(100, $family + 5);
            $affordability = min(100, $affordability + 3);
        }
        if (!empty($assessment['property_type'])
            && $assessment['property_type'] !== 'Any'
            && strcasecmp($assessment['property_type'], $type) !== 0) {
            $family = max(0, $family - 10);
        }

        // ── FIX (audit item #5): comfort_priority is now a closed enum ────────
        // Previously matched by str_contains() against arbitrary substrings
        // ('energy', 'security', 'family', 'growth', 'quiet', 'acoustic',
        // 'smart', 'tech'). That's an unbounded text match standing in for what
        // is, in practice, already a closed set: the front end
        // (assets/js/app.js, slidersToAssessmentFields) only ever emits one of
        // three exact strings — "Family growth", "Acoustic comfort", or
        // "Energy efficiency" — and the hidden quick-assessment form
        // (index.php) only ever sends "Energy efficiency". The 'security'/
        // 'smart'/'tech' branches were therefore dead code: no UI path could
        // ever produce a value that reached them, while any unexpected/typo'd
        // value silently matched nothing and granted no bonus at all, with no
        // visibility into the failure.
        //
        // The lookup below is an explicit map from the closed set of values
        // the UI can actually send to the single scoring dimension each one
        // is meant to boost. Anything not in the map (e.g. a malformed value,
        // a future UI bug, or direct API misuse) falls through to the
        // explicit "no match" branch and is a no-op — instead of a silent,
        // partial match against unrelated substrings.
        $comfortPriorityMap = [
            'energy efficiency' => 'environment',
            'acoustic comfort'  => 'environment', // acoustic isn't a standalone
                                                   // scoring dimension; treated
                                                   // as an environment proxy
            'family growth'     => 'family',
        ];
        $comfortPri = strtolower(trim((string) ($assessment['comfort_priority'] ?? '')));
        $comfortDimension = $comfortPriorityMap[$comfortPri] ?? null;

        // IF comfort_priority resolves to a known dimension THEN apply its
        // single +5 bonus. ELSE no bonus is applied (explicit no-op, not a
        // silent partial match).
        if ($comfortDimension === 'environment') {
            $environment = min(100, $environment + 5);
        } elseif ($comfortDimension === 'family') {
            $family = min(100, $family + 5);
        }
        // ─────────────────────────────────────────────────────────────────────

        $total = ($affordability * $weights['affordability'])
               + ($security     * $weights['security'])
               + ($smart        * $weights['smart'])
               + ($environment  * $weights['environment'])
               + ($family       * $weights['family']);

        // FIX: Removed the redundant 'total_score' field — it was always
        // identical to 'match_percentage'. All callers now use 'match_percentage'.
        return [
            'affordability_score' => round($affordability, 2),
            'security_score'      => round($security, 2),
            'smart_score'         => round($smart, 2),
            'environment_score'   => round($environment, 2),
            'family_score'        => round($family, 2),
            'match_percentage'    => round($total, 2),
        ];
    }
}
