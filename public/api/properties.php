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
require __DIR__ . '/../../app/models/PropertyRepository.php';


function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (!Auth::check()) {
        json_response([
            'success' => false,
            'message' => 'Authentication is required.',
        ], 401);
    }

    $user = Auth::user();
    $staffViewRequested = ($_GET['view'] ?? 'customer') === 'staff';
    $staffView = $staffViewRequested && ($user['role'] ?? '') === 'admin';

    if ($staffViewRequested && !$staffView) {
        json_response([
            'success' => false,
            'message' => 'Staff property metrics require an admin account.',
        ], 403);
    }

    $repository = new PropertyRepository(Database::connect());

    if (isset($_GET['id'])) {
        $property = $repository->getById((int) $_GET['id'], $staffView);

        if ($property === null) {
            json_response([
                'success' => false,
                'message' => 'Property not found.',
            ], 404);
        }

        json_response([
            'success' => true,
            'data' => $property,
        ]);
    }

    $filters = [
        'township' => $_GET['township'] ?? null,
        'area' => $_GET['area'] ?? null,
        'state' => $_GET['state'] ?? null,
        'type' => $_GET['type'] ?? null,
        'tenure' => $_GET['tenure'] ?? null,
        'budget' => $_GET['budget'] ?? null,
        'min_price' => $_GET['min_price'] ?? null,
        'max_price' => $_GET['max_price'] ?? null,
        'bedrooms' => $_GET['bedrooms'] ?? null,
        'bathrooms' => $_GET['bathrooms'] ?? null,
        'limit' => $_GET['limit'] ?? 50,
        'offset' => $_GET['offset'] ?? 0,
    ];

    $properties = $repository->getAll($filters, $staffView);

    json_response([
        'success' => true,
        'count' => count($properties),
        'view' => $staffView ? 'staff' : 'customer',
        'data' => $properties,
    ]);
} catch (Throwable $exception) {
    json_response([
        'success' => false,
        'message' => 'Unable to fetch property data.',
        'error' => $exception->getMessage(),
    ], 500);
}
