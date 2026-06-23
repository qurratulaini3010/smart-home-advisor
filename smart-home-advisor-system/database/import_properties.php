<?php
declare(strict_types=1);

require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/core/Database.php';

$csvPath = __DIR__ . '/property_data.csv';

if (!is_file($csvPath)) {
    exit("CSV file not found: {$csvPath}\n");
}

function csv_value(array $row, array $headers, string $name): string
{
    $index = $headers[$name] ?? null;
    return $index === null ? '' : trim((string) ($row[$index] ?? ''));
}

function numeric_value(string $value): ?float
{
    $clean = str_replace([',', 'RM', '%'], '', trim($value));
    return $clean === '' ? null : (float) $clean;
}

try {
    $pdo = Database::connect();
    $handle = fopen($csvPath, 'rb');

    if ($handle === false) {
        exit("Unable to open CSV file: {$csvPath}\n");
    }

    $headerRow = fgetcsv($handle);
    if ($headerRow === false) {
        exit("CSV file has no header row.\n");
    }

    $headers = array_flip(array_map(static fn ($header) => trim((string) $header), $headerRow));

    $insert = $pdo->prepare(
        'INSERT INTO properties (
            property_name, property_type, location, state, price, bedrooms, bathrooms, built_up_sqft,
            smart_readiness_score, security_score, sustainability_score, family_score, acoustic_score,
            description, image, township, area, tenure, type, house_size_sqft, median_price, median_psf,
            estimated_rental_yield_pct, historical_capital_appreciation_3yr_pct, est_monthly_mortgage_rm,
            transactions, safety_score, crime_risk, flood_risk, distance_to_public_transport_km,
            distance_to_mall_km, distance_to_school_km, distance_to_hospital_km
        ) VALUES (
            :property_name, :property_type, :location, :state, :price, :bedrooms, :bathrooms, :built_up_sqft,
            :smart_readiness_score, :security_score, :sustainability_score, :family_score, :acoustic_score,
            :description, :image, :township, :area, :tenure, :type, :house_size_sqft, :median_price, :median_psf,
            :estimated_rental_yield_pct, :historical_capital_appreciation_3yr_pct, :est_monthly_mortgage_rm,
            :transactions, :safety_score, :crime_risk, :flood_risk, :distance_to_public_transport_km,
            :distance_to_mall_km, :distance_to_school_km, :distance_to_hospital_km
        )'
    );

    $pdo->beginTransaction();
    $inserted = 0;

    while (($row = fgetcsv($handle)) !== false) {
        if (trim(implode('', $row)) === '') {
            continue;
        }

        $township = csv_value($row, $headers, 'Township');
        $area = csv_value($row, $headers, 'Area');
        $state = csv_value($row, $headers, 'State');
        $type = csv_value($row, $headers, 'Type');
        $medianPrice = numeric_value(csv_value($row, $headers, 'Median_Price')) ?? 0.0;
        $houseSize = (int) (numeric_value(csv_value($row, $headers, 'House_Size_SQFT')) ?? 0);
        $safetyScore = numeric_value(csv_value($row, $headers, 'Safety_Score')) ?? 0.0;

        $insert->execute([
            ':property_name' => $township,
            ':property_type' => $type,
            ':location' => $area,
            ':state' => $state,
            ':price' => $medianPrice,
            ':bedrooms' => (int) (numeric_value(csv_value($row, $headers, 'Bedrooms')) ?? 0),
            ':bathrooms' => (int) (numeric_value(csv_value($row, $headers, 'Bathrooms')) ?? 0),
            ':built_up_sqft' => $houseSize,
            ':smart_readiness_score' => 70,
            ':security_score' => min(100, max(0, (int) round($safetyScore))),
            ':sustainability_score' => 70,
            ':family_score' => 70,
            ':acoustic_score' => 70,
            ':description' => "{$township} in {$area}, {$state}.",
            ':image' => null,
            ':township' => $township,
            ':area' => $area,
            ':tenure' => csv_value($row, $headers, 'Tenure'),
            ':type' => $type,
            ':house_size_sqft' => $houseSize,
            ':median_price' => $medianPrice,
            ':median_psf' => numeric_value(csv_value($row, $headers, 'Median_PSF')) ?? 0.0,
            ':estimated_rental_yield_pct' => numeric_value(csv_value($row, $headers, 'Estimated_Rental_Yield_Pct')) ?? 0.0,
            ':historical_capital_appreciation_3yr_pct' => numeric_value(csv_value($row, $headers, 'Historical_Capital_Appreciation_3Yr_Pct')) ?? 0.0,
            ':est_monthly_mortgage_rm' => numeric_value(csv_value($row, $headers, 'Est_Monthly_Mortgage_RM')),
            ':transactions' => (int) (numeric_value(csv_value($row, $headers, 'Transactions')) ?? 0),
            ':safety_score' => $safetyScore,
            ':crime_risk' => csv_value($row, $headers, 'Crime_Risk'),
            ':flood_risk' => csv_value($row, $headers, 'Flood_Risk'),
            ':distance_to_public_transport_km' => numeric_value(csv_value($row, $headers, 'Distance_to_Public_Transport_KM')) ?? 0.0,
            ':distance_to_mall_km' => numeric_value(csv_value($row, $headers, 'Distance_to_Mall_KM')) ?? 0.0,
            ':distance_to_school_km' => numeric_value(csv_value($row, $headers, 'Distance_to_School_KM')) ?? 0.0,
            ':distance_to_hospital_km' => numeric_value(csv_value($row, $headers, 'Distance_to_Hospital_KM')) ?? 0.0,
        ]);

        $inserted++;
    }

    fclose($handle);
    $pdo->commit();

    echo "Import complete. {$inserted} rows inserted into properties.\n";
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
