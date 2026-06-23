<?php
declare(strict_types=1);

final class RecommendationEngine
{
    public const WEIGHTS = [
        'affordability' => 0.30,
        'security' => 0.20,
        'smart' => 0.20,
        'environment' => 0.15,
        'family' => 0.15,
    ];

    public static function score(array $assessment, array $property, ?array $weights = null): array
    {
        $weights = $weights ?: self::WEIGHTS;
        $budget = max((float) $assessment['budget'], 1);
        $price = (float) ($property['median_price'] ?? $property['price'] ?? 0);
        $location = (string) ($property['area'] ?? $property['location'] ?? '');
        $type = (string) ($property['type'] ?? $property['property_type'] ?? '');
        $bedrooms = (int) ($property['bedrooms'] ?? 0);
        $affordability = max(0, min(100, 100 - (($price - $budget) / $budget * 100)));
        if ($price <= $budget) {
            $affordability = min(100, 86 + (($budget - $price) / $budget * 14));
        }

        // ── Occupation-based scoring adjustments ─────────────────────────────
        $occupation = strtolower(trim((string) ($assessment['occupation'] ?? '')));

        $isGovt = str_contains($occupation, 'civil') || str_contains($occupation, 'government')
               || str_contains($occupation, 'teacher') || str_contains($occupation, 'lecturer')
               || str_contains($occupation, 'nurse') || str_contains($occupation, 'doctor')
               || str_contains($occupation, 'army') || str_contains($occupation, 'polis');

        $isSelfEmployed = str_contains($occupation, 'self') || str_contains($occupation, 'freelance')
               || str_contains($occupation, 'business owner') || str_contains($occupation, 'entrepreneur')
               || str_contains($occupation, 'contractor') || str_contains($occupation, 'consultant')
               || str_contains($occupation, 'trader') || str_contains($occupation, 'hawker');

        $isHighIncome = str_contains($occupation, 'engineer') || str_contains($occupation, 'lawyer')
               || str_contains($occupation, 'architect') || str_contains($occupation, 'surgeon')
               || str_contains($occupation, 'director') || str_contains($occupation, 'manager')
               || str_contains($occupation, 'executive') || str_contains($occupation, 'ceo')
               || str_contains($occupation, 'accountant') || str_contains($occupation, 'banker')
               || str_contains($occupation, 'pilot') || str_contains($occupation, 'pharmacist');

        $isRetired = str_contains($occupation, 'retire') || str_contains($occupation, 'pensioner')
               || str_contains($occupation, 'pension');

        // Government employees: reward stable-income affordability (price well within budget)
        if ($isGovt && $price <= $budget) {
            $affordability = min(100, $affordability + 5);
        }

        // Self-employed: penalise if price exceeds budget (higher bank risk)
        if ($isSelfEmployed && $price > $budget) {
            $affordability = max(0, $affordability - 10);
        }
        // Self-employed: reward conservative picks and high yield (income supplement)
        if ($isSelfEmployed && $price <= $budget * 0.85) {
            $affordability = min(100, $affordability + 5);
        }

        // High-income professionals: boost smart and environment scores (they value it)
        if ($isHighIncome) {
            $smartBase = (float) $property['smart_readiness_score'];
            // already computed below; apply bonus after
        }

        // Retirees: boost safety/environment, penalise if hospital is far
        $hospitalKm = (float) ($property['distance_to_hospital_km'] ?? 99);
        if ($isRetired) {
            $environment = (float) $property['sustainability_score'];
            if ($hospitalKm <= 5.0) {
                $environment = min(100, $environment + 8);
            } elseif ($hospitalKm > 15.0) {
                $environment = max(0, $environment - 10);
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        $smartNeed = (
            (int) $assessment['smart_lighting'] +
            (int) $assessment['smart_security'] +
            (int) $assessment['smart_appliances'] +
            (int) $assessment['smart_energy']
        );
        $smartBase = (float) $property['smart_readiness_score'];
        $smart = min(100, $smartBase + ($smartNeed * 3));
        // High-income professionals: extra smart bonus (value premium features)
        if ($isHighIncome) {
            $smart = min(100, $smart + 5);
        }

        $security = (float) $property['security_score'];
        if ((int) $assessment['smart_security'] === 1) {
            $security = min(100, $security + 8);
        }

        $environment = (float) $property['sustainability_score'];
        if ((int) $assessment['smart_energy'] === 1) {
            $environment = min(100, $environment + 7);
        }
        // High-income: extra sustainability bonus
        if ($isHighIncome) {
            $environment = min(100, $environment + 4);
        }
        // Retirees: hospital proximity already applied above; re-apply here cleanly
        if ($isRetired && $hospitalKm <= 5.0) {
            $environment = min(100, $environment + 8);
        } elseif ($isRetired && $hospitalKm > 15.0) {
            $environment = max(0, $environment - 10);
        }

        $family = (float) $property['family_score'];
        if ((int) $assessment['household_size'] >= 4 && $bedrooms >= 3) {
            $family = min(100, $family + 8);
        }
        // Retirees: boost family score when safety is strong (peaceful living)
        if ($isRetired && (float)($property['safety_score'] ?? 0) >= 70) {
            $family = min(100, $family + 6);
        }

        if (!empty($assessment['preferred_location']) && stripos($location, $assessment['preferred_location']) !== false) {
            $family = min(100, $family + 5);
            $affordability = min(100, $affordability + 3);
        }

        if (!empty($assessment['property_type']) && $assessment['property_type'] !== 'Any' && strcasecmp($assessment['property_type'], $type) !== 0) {
            $family = max(0, $family - 10);
        }

        $total = ($affordability * $weights['affordability'])
            + ($security * $weights['security'])
            + ($smart * $weights['smart'])
            + ($environment * $weights['environment'])
            + ($family * $weights['family']);

        return [
            'affordability_score' => round($affordability, 2),
            'security_score' => round($security, 2),
            'smart_score' => round($smart, 2),
            'environment_score' => round($environment, 2),
            'family_score' => round($family, 2),
            'total_score' => round($total, 2),
            'match_percentage' => round($total, 2),
        ];
    }
}
