<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../../app/config.php';

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $stmt = $pdo->query(
        'SELECT
            id,
            Transactions,
            Safety_Score,
            Crime_Risk,
            Distance_to_Public_Transport_KM,
            Distance_to_Mall_KM,
            Distance_to_School_KM,
            Distance_to_Hospital_KM,
            Flood_Risk,
            Estimated_Rental_Yield_Pct,
            Historical_Capital_Appreciation_3Yr_Pct,
            Est_Monthly_Mortgage_RM,
            created_at
        FROM property_insights
        ORDER BY id DESC'
    );

    $rows = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'count' => count($rows),
        'data' => $rows,
    ], JSON_PRETTY_PRINT);
} catch (Throwable $exception) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to fetch property insights.',
        'error' => $exception->getMessage(),
    ], JSON_PRETTY_PRINT);
}
