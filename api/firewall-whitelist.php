<?php
session_start();
header('Content-Type: application/json');
require_once 'cors_helper.php';

set_cors_headers();
handle_preflight();

require_once '../config/database.php';

function checkSuperAdminAccess() {
    if (!isset($_SESSION['admin_user']) || $_SESSION['admin_user']['role'] !== 'superadmin') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Access denied. Superadmin only.'
        ]);
        exit();
    }

    return $_SESSION['admin_user']['id'];
}

$whitelist_file = __DIR__ . '/../config/firewall_whitelist.json';

function load_whitelist($file_path) {
    if (!file_exists($file_path)) {
        $default = [
            'ips' => [],
            'updated_at' => date('c'),
            'updated_by' => 'system'
        ];
        file_put_contents($file_path, json_encode($default, JSON_PRETTY_PRINT));
        return $default;
    }

    $content = file_get_contents($file_path);
    $data = json_decode($content, true);

    if (!is_array($data)) {
        return [
            'ips' => [],
            'updated_at' => date('c'),
            'updated_by' => 'system'
        ];
    }

    if (!isset($data['ips']) || !is_array($data['ips'])) {
        $data['ips'] = [];
    }

    return $data;
}

function save_whitelist($file_path, $data) {
    $dir = dirname($file_path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new Exception('Failed to encode whitelist data.');
    }

    if (file_put_contents($file_path, $json) === false) {
        throw new Exception('Failed to write whitelist file.');
    }
}

try {
    $admin_user_id = checkSuperAdminAccess();
    $admin_user = $_SESSION['admin_user'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $data = load_whitelist($whitelist_file);
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || !isset($input['ips']) || !is_array($input['ips'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid whitelist payload.'
            ]);
            exit();
        }

        $validated_ips = [];
        foreach ($input['ips'] as $entry) {
            if (!isset($entry['ip']) || !filter_var($entry['ip'], FILTER_VALIDATE_IP)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => "Invalid IP address: " . ($entry['ip'] ?? 'missing')
                ]);
                exit();
            }

            $validated_ips[] = [
                'ip' => $entry['ip'],
                'label' => isset($entry['label']) ? trim($entry['label']) : ''
            ];
        }

        $whitelist_data = [
            'ips' => $validated_ips,
            'updated_at' => date('c'),
            'updated_by' => $admin_user['username']
        ];

        save_whitelist($whitelist_file, $whitelist_data);

        echo json_encode([
            'success' => true,
            'message' => 'Firewall whitelist updated successfully.',
            'data' => $whitelist_data
        ]);
        exit();
    }

    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}

