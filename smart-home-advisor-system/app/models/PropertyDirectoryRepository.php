<?php
declare(strict_types=1);

final class PropertyDirectoryRepository
{
    private const PROPERTY_COLUMNS = '
        p.id,
        p.township,
        p.area,
        p.property_name,
        p.property_type,
        p.location,
        p.state,
        p.tenure,
        p.type,
        p.price,
        p.description,
        p.image,
        p.median_price,
        p.median_psf,
        p.estimated_rental_yield_pct,
        p.historical_capital_appreciation_3yr_pct,
        p.est_monthly_mortgage_rm,
        p.transactions,
        p.safety_score,
        p.crime_risk,
        p.flood_risk,
        p.distance_to_public_transport_km,
        p.distance_to_mall_km,
        p.distance_to_school_km,
        p.distance_to_hospital_km,
        p.bedrooms,
        p.bathrooms,
        p.built_up_sqft,
        p.house_size_sqft,
        p.smart_readiness_score,
        p.security_score,
        p.sustainability_score,
        p.family_score,
        p.acoustic_score
    ';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function search(array $filters, int $userId): array
    {
        $conditions = [];
        $params = [':user_id' => $userId];

        if (!empty($filters['query'])) {
            $conditions[] = '(p.township LIKE :query_township OR p.area LIKE :query_area OR p.property_name LIKE :query_name OR p.location LIKE :query_location)';
            $queryVal = '%' . trim((string) $filters['query']) . '%';
            $params[':query_township'] = $queryVal;
            $params[':query_area']     = $queryVal;
            $params[':query_name']     = $queryVal;
            $params[':query_location'] = $queryVal;
        }

        if (!empty($filters['township_area'])) {
            $conditions[] = '(p.township LIKE :ta_township OR p.area LIKE :ta_area OR p.location LIKE :ta_location)';
            $taVal = '%' . trim((string) $filters['township_area']) . '%';
            $params[':ta_township'] = $taVal;
            $params[':ta_area']     = $taVal;
            $params[':ta_location'] = $taVal;
        }

        if (!empty($filters['property_type'])) {
            $conditions[] = '(p.property_type = :pt_type1 OR p.type = :pt_type2)';
            $ptVal = trim((string) $filters['property_type']);
            $params[':pt_type1'] = $ptVal;
            $params[':pt_type2'] = $ptVal;
        }

        if (!empty($filters['tenure_preference']) && $filters['tenure_preference'] !== 'Any') {
            $conditions[] = 'p.tenure = :tenure_preference';
            $params[':tenure_preference'] = trim((string) $filters['tenure_preference']);
        }

        foreach ([
            'min_price' => ['COALESCE(p.median_price, p.price) >= :min_price', ':min_price'],
            'max_price' => ['COALESCE(p.median_price, p.price) <= :max_price', ':max_price'],
            'bedrooms' => ['p.bedrooms >= :bedrooms', ':bedrooms'],
            'min_smart_score' => ['p.smart_readiness_score >= :min_smart_score', ':min_smart_score'],
            'min_sustainability_score' => ['p.sustainability_score >= :min_sustainability_score', ':min_sustainability_score'],
            'min_size' => ['COALESCE(p.house_size_sqft, p.built_up_sqft) >= :min_size', ':min_size'],
            'max_size' => ['COALESCE(p.house_size_sqft, p.built_up_sqft) <= :max_size', ':max_size'],
        ] as $key => [$condition, $param]) {
            if (isset($filters[$key]) && $filters[$key] !== '' && (float) $filters[$key] > 0) {
                $conditions[] = $condition;
                $params[$param] = (float) $filters[$key];
            }
        }

        if (!empty($filters['low_flood_risk'])) {
            $conditions[] = "LOWER(p.flood_risk) IN ('low', 'very low')";
        }

        if (!empty($filters['near_school'])) {
            $conditions[] = 'p.distance_to_school_km <= 3';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $sql = 'SELECT ' . self::PROPERTY_COLUMNS . ',
                    CASE WHEN f.id IS NULL THEN 0 ELSE 1 END AS is_favorite
                FROM properties p
                LEFT JOIN favorites f ON f.property_id = p.id AND f.user_id = :user_id'
                . $where .
                ' ORDER BY p.smart_readiness_score DESC, p.sustainability_score DESC, COALESCE(p.median_price, p.price) ASC
                  LIMIT 60';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function find(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::PROPERTY_COLUMNS . ',
                    CASE WHEN f.id IS NULL THEN 0 ELSE 1 END AS is_favorite
             FROM properties p
             LEFT JOIN favorites f ON f.property_id = p.id AND f.user_id = :user_id
             WHERE p.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);

        $property = $stmt->fetch();
        return $property === false ? null : $property;
    }

    /**
     * @param array<string, mixed> $preferences
     * @return array<int, array<string, mixed>>
     */
    public function recommend(array $preferences, int $userId): array
    {
        $rows = $this->search([
            'township_area' => $preferences['areas'] ?? '',
            'min_price' => $preferences['min_budget'] ?? '',
            'max_price' => $preferences['max_budget'] ?? '',
            'min_size' => $preferences['min_size'] ?? '',
            'bedrooms' => $preferences['bedrooms'] ?? '',
            'property_type' => ($preferences['property_type'] ?? '') === 'Any' ? '' : ($preferences['property_type'] ?? ''),
            'tenure_preference' => ($preferences['tenure_preference'] ?? '') === 'Any' ? '' : ($preferences['tenure_preference'] ?? ''),
            'low_flood_risk' => !empty($preferences['low_flood_risk']) ? 1 : '',
            'near_school' => !empty($preferences['near_school']) ? 1 : '',
        ], $userId);

        $weights = [
            'smart_readiness_score' => max(0, (int) ($preferences['smart_priority'] ?? 50)),
            'security_score' => max(0, (int) ($preferences['security_priority'] ?? 50)),
            'sustainability_score' => max(0, (int) ($preferences['sustainability_priority'] ?? 50)),
            'family_score' => max(0, (int) ($preferences['family_priority'] ?? 50)),
            'acoustic_score' => max(0, (int) ($preferences['quiet_priority'] ?? 50)),
        ];

        foreach ($rows as &$row) {
            $score = 0.0;
            $weightTotal = 0;

            foreach ($weights as $column => $weight) {
                $score += ((float) $row[$column]) * $weight;
                $weightTotal += $weight;
            }

            $schoolBonus = !empty($preferences['near_school'])
                ? max(0, 10 - ((float) $row['distance_to_school_km'] * 2))
                : 0;
            $floodBonus = !empty($preferences['low_flood_risk']) && strtolower((string) $row['flood_risk']) === 'low' ? 8 : 0;

            // FIX: Cap at 100 — school/flood bonuses could previously push the
            // value above 100, making it display as e.g. "107% match".
            $row['advisor_match_score'] = min(100.0, round(
                ($weightTotal > 0 ? $score / $weightTotal : 0) + $schoolBonus + $floodBonus,
                2
            ));
        }
        unset($row);

        usort($rows, static fn (array $a, array $b): int => $b['advisor_match_score'] <=> $a['advisor_match_score']);

        return array_slice($rows, 0, 12);
    }

    public function addFavorite(int $userId, int $propertyId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO favorites (user_id, property_id) VALUES (:user_id, :property_id)'
        );
        $stmt->execute([':user_id' => $userId, ':property_id' => $propertyId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function favoritesForUser(int $userId, int $limit = 6): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::PROPERTY_COLUMNS . ', 1 AS is_favorite
             FROM favorites f
             JOIN properties p ON p.id = f.property_id
             WHERE f.user_id = :user_id
             ORDER BY f.created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
