<?php
session_start();
header('Content-Type: application/json');

require_once 'cors_helper.php';
set_cors_headers();
handle_preflight();

require_once '../config/database.php';

if (!isset($_SESSION['admin_user']) || $_SESSION['admin_user']['role'] !== 'superadmin') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Only superadmins may override parking spaces.'
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['space_id'], $input['status'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'space_id and status are required.'
    ]);
    exit();
}

$space_id = (int)$input['space_id'];
$status = $input['status'];
$allowed_statuses = ['vacant', 'occupied'];

if (!in_array($status, $allowed_statuses, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid status value. Only vacant/occupied overrides are supported.'
    ]);
    exit();
}

$db = new Database();
$occupied_since = ($status === 'occupied') ? date('Y-m-d H:i:s') : null;

$result = $db->updateParkingSpaceStatus(
    $space_id,
    $status,
    null, // license_plate
    null, // reservation_time
    $occupied_since,
    null // payment_tx_hash
);

if ($result['success']) {
    echo json_encode([
        'success' => true,
        'message' => "Parking space #{$space_id} updated to {$status}."
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $result['error'] ?? 'Unable to update parking space.'
    ]);
}

