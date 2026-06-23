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
require __DIR__ . '/../../app/core/Csrf.php';
require __DIR__ . '/../../app/models/PropertyDirectoryRepository.php';

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (!Auth::check()) {
        respond(['success' => false, 'message' => 'Please sign in to continue.'], 401);
    }

    $repo = new PropertyDirectoryRepository(Database::connect());
    $userId = (int) Auth::user()['id'];
    $action = $_GET['action'] ?? $_POST['action'] ?? 'search';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Csrf::verify();
    }

    if ($action === 'details') {
        $property = $repo->find((int) ($_GET['id'] ?? 0), $userId);

        if (!$property) {
            respond(['success' => false, 'message' => 'Property not found.'], 404);
        }

        respond(['success' => true, 'data' => $property]);
    }

    if ($action === 'recommend') {
        $preferences = [
            'areas' => $_GET['areas'] ?? '',
            'min_budget' => $_GET['min_budget'] ?? '',
            'max_budget' => $_GET['max_budget'] ?? '',
            'min_size' => $_GET['min_size'] ?? '',
            'bedrooms' => $_GET['bedrooms'] ?? '',
            'property_type' => $_GET['property_type'] ?? '',
            'tenure_preference' => $_GET['tenure_preference'] ?? '',
            'smart_priority' => $_GET['smart_priority'] ?? 50,
            'security_priority' => $_GET['security_priority'] ?? 50,
            'sustainability_priority' => $_GET['sustainability_priority'] ?? 50,
            'family_priority' => $_GET['family_priority'] ?? 50,
            'quiet_priority' => $_GET['quiet_priority'] ?? 50,
            'near_school' => $_GET['near_school'] ?? '',
            'low_flood_risk' => $_GET['low_flood_risk'] ?? '',
        ];

        $properties = $repo->recommend($preferences, $userId);
        respond(['success' => true, 'count' => count($properties), 'data' => $properties]);
    }

    if ($action === 'favorite') {
        $propertyId = (int) ($_POST['property_id'] ?? 0);
        if ($propertyId <= 0) {
            respond(['success' => false, 'message' => 'Missing property ID.'], 422);
        }

        $repo->addFavorite($userId, $propertyId);
        respond([
            'success' => true,
            'message' => 'Property saved to favorites.',
            'redirect' => route('dashboard'),
        ]);
    }

    if ($action === 'favorites') {
        $favorites = $repo->favoritesForUser($userId);
        respond(['success' => true, 'count' => count($favorites), 'data' => $favorites]);
    }

    $filters = [
        'query' => $_GET['query'] ?? '',
        'township_area' => $_GET['township_area'] ?? '',
        'property_type' => $_GET['property_type'] ?? '',
        'min_price' => $_GET['min_price'] ?? '',
        'max_price' => $_GET['max_price'] ?? '',
        'bedrooms' => $_GET['bedrooms'] ?? '',
        'min_smart_score' => $_GET['min_smart_score'] ?? '',
        'min_sustainability_score' => $_GET['min_sustainability_score'] ?? '',
    ];

    $properties = $repo->search($filters, $userId);
    respond(['success' => true, 'count' => count($properties), 'data' => $properties]);
} catch (Throwable $exception) {
    respond([
        'success' => false,
        'message' => 'Unable to process property request.',
        'error' => $exception->getMessage(),
    ], 500);
}
