<?php
declare(strict_types=1);

final class PropertyRepository
{
    private const PUBLIC_COLUMNS = [
        'id',
        'property_name',
        'township',
        'area',
        'state',
        'tenure',
        'type',
        'bedrooms',
        'bathrooms',
        'house_size_sqft',
        'median_price',
        'median_psf',
        'estimated_rental_yield_pct',
        'est_monthly_mortgage_rm',
        'distance_to_public_transport_km',
        'distance_to_mall_km',
        'distance_to_school_km',
        'distance_to_hospital_km',
        'image',
    ];

    private const STAFF_COLUMNS = [
        'id',
        'property_name',
        'property_type',
        'location',
        'price',
        'township',
        'area',
        'state',
        'tenure',
        'type',
        'bedrooms',
        'bathrooms',
        'house_size_sqft',
        'median_price',
        'median_psf',
        'estimated_rental_yield_pct',
        'historical_capital_appreciation_3yr_pct',
        'est_monthly_mortgage_rm',
        'transactions',
        'safety_score',
        'crime_risk',
        'flood_risk',
        'distance_to_public_transport_km',
        'distance_to_mall_km',
        'distance_to_school_km',
        'distance_to_hospital_km',
        'created_at',
        'updated_at',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $filters Supported keys:
     * township, area, state, type, tenure, budget, min_price, max_price,
     * bedrooms, bathrooms, limit, offset.
     * @return array<int, array<string, mixed>>
     */
    public function getAll(array $filters = [], bool $staffView = false): array
    {
        $conditions = [];
        $params = [];

        foreach (['township', 'area', 'state', 'type', 'tenure'] as $field) {
            if (!empty($filters[$field])) {
                $conditions[] = "{$field} LIKE :{$field}";
                $params[":{$field}"] = '%' . trim((string) $filters[$field]) . '%';
            }
        }

        if (isset($filters['budget']) && (float) $filters['budget'] > 0) {
            $conditions[] = 'median_price <= :budget';
            $params[':budget'] = (float) $filters['budget'];
        }

        if (isset($filters['max_price']) && (float) $filters['max_price'] > 0) {
            $conditions[] = 'median_price <= :max_price';
            $params[':max_price'] = (float) $filters['max_price'];
        }

        if (isset($filters['min_price']) && (float) $filters['min_price'] > 0) {
            $conditions[] = 'median_price >= :min_price';
            $params[':min_price'] = (float) $filters['min_price'];
        }

        foreach (['bedrooms', 'bathrooms'] as $field) {
            if (isset($filters[$field]) && (int) $filters[$field] > 0) {
                $conditions[] = "{$field} >= :{$field}";
                $params[":{$field}"] = (int) $filters[$field];
            }
        }

        $limit = min(max((int) ($filters['limit'] ?? 50), 1), 100);
        $offset = max((int) ($filters['offset'] ?? 0), 0);
        $columns = implode(', ', $staffView ? self::STAFF_COLUMNS : self::PUBLIC_COLUMNS);
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $sql = "SELECT {$columns} FROM properties{$where} ORDER BY median_price ASC, township ASC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getById(int $id, bool $staffView = false): ?array
    {
        $columns = implode(', ', $staffView ? self::STAFF_COLUMNS : self::PUBLIC_COLUMNS);
        $stmt = $this->pdo->prepare("SELECT {$columns} FROM properties WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);

        $property = $stmt->fetch();
        return $property === false ? null : $property;
    }
}
