<?php
declare(strict_types=1);

require __DIR__ . '/../app/config.php';

$csvPath = __DIR__ . '/property_data.csv';

if (!is_file($csvPath)) {
    exit("CSV file not found: {$csvPath}\n");
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $handle = fopen($csvPath, 'rb');
    if ($handle === false) {
        exit("Unable to open CSV file: {$csvPath}\n");
    }

    $insert = $pdo->prepare(
        'INSERT INTO property_insights (
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
            Est_Monthly_Mortgage_RM
        ) VALUES (
            :transactions,
            :safety_score,
            :crime_risk,
            :distance_to_public_transport_km,
            :distance_to_mall_km,
            :distance_to_school_km,
            :distance_to_hospital_km,
            :flood_risk,
            :estimated_rental_yield_pct,
            :historical_capital_appreciation_3yr_pct,
            :est_monthly_mortgage_rm
        )'
    );

    $pdo->beginTransaction();
    $rowNumber = 0;
    $inserted = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $rowNumber++;

        if ($rowNumber === 1) {
            continue;
        }

        if (count($row) < 11 || trim(implode('', $row)) === '') {
            continue;
        }

        $insert->execute([
            ':transactions' => (int) $row[0],
            ':safety_score' => (float) $row[1],
            ':crime_risk' => trim((string) $row[2]),
            ':distance_to_public_transport_km' => (float) $row[3],
            ':distance_to_mall_km' => (float) $row[4],
            ':distance_to_school_km' => (float) $row[5],
            ':distance_to_hospital_km' => (float) $row[6],
            ':flood_risk' => trim((string) $row[7]),
            ':estimated_rental_yield_pct' => (float) $row[8],
            ':historical_capital_appreciation_3yr_pct' => (float) $row[9],
            ':est_monthly_mortgage_rm' => (float) $row[10],
        ]);

        $inserted++;
    }

    fclose($handle);
    $pdo->commit();

    echo "Import complete. {$inserted} rows inserted into property_insights.\n";
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (isset($handle) && is_resource($handle)) {
        fclose($handle);
    }

    http_response_code(500);
    exit('Import failed: ' . $exception->getMessage() . "\n");
}

