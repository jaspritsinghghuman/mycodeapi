<?php
header('Content-Type: application/json');

/**
 * Enhanced Custom API: Create Service in Coolify
 * 
 * This endpoint creates services directly in the Coolify database,
 * bypassing CSRF middleware issues with the main API.
 * 
 * Usage:
 * POST /newapi/create-service.php?key=COOLIFY_API_TOKEN
 * 
 * Body (JSON):
 * {
 *   "name": "app-name",
 *   "template_id": 1,
 *   "fqdn": "subdomain.domain.com",
 *   "project_uuid": "project-uuid",
 *   "environment_name": "production",
 *   "server_uuid": "server-uuid",
 *   "destination_uuid": "destination-uuid",
 *   "description": "App description"
 * }
 */

error_log('[Custom API - Create Service] ===== NEW REQUEST =====');

$provided_api_token = isset($_GET['key']) ? $_GET['key'] : '';

if (empty($provided_api_token)) {
    http_response_code(401);
    error_log('[Custom API - Create Service] ERROR: API token missing');
    echo json_encode(['error' => 'API token parameter missing']);
    exit;
}

// Get JSON body
$json_input = file_get_contents('php://input');
$data = json_decode($json_input, true);

if (!$data) {
    http_response_code(400);
    error_log('[Custom API - Create Service] ERROR: Invalid JSON body');
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

// Validate required parameters
$required_fields = ['name', 'template_id', 'fqdn', 'project_uuid', 'environment_name', 'server_uuid', 'destination_uuid'];
foreach ($required_fields as $field) {
    if (empty($data[$field])) {
        http_response_code(400);
        error_log("[Custom API - Create Service] ERROR: Required field missing: {$field}");
        echo json_encode(['error' => "Missing required field: {$field}"]);
        exit;
    }
}

error_log('[Custom API - Create Service] Creating service: ' . $data['name'] . ' with FQDN: ' . $data['fqdn']);

// Connect to Coolify DB
try {
    $db_host = getenv('DB_HOST') ?: $_ENV['DB_HOST'] ?? 'localhost';
    $db_port = getenv('DB_PORT') ?: $_ENV['DB_PORT'] ?? '5432';
    $db_name = getenv('DB_NAME') ?: $_ENV['DB_NAME'] ?? 'coolify';
    $db_user = getenv('DB_USER') ?: $_ENV['DB_USER'] ?? 'coolify';
    $db_password = getenv('DB_PASSWORD') ?: $_ENV['DB_PASSWORD'] ?? '';
    
    $pdo = new PDO(
        "pgsql:host={$db_host};port={$db_port};dbname={$db_name}",
        $db_user,
        $db_password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    error_log('[Custom API - Create Service] ERROR: DB connection failed - ' . $e->getMessage());
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

try {
    // Insert service into database
    $stmt = $pdo->prepare("
        INSERT INTO services (uuid, name, description, template_id, project_uuid, environment_name, server_uuid, destination_uuid, status, created_at, updated_at)
        VALUES (:uuid, :name, :description, :template_id, :project_uuid, :environment_name, :server_uuid, :destination_uuid, :status, :created_at, :updated_at)
        RETURNING uuid, id
    ");
    
    $service_uuid = bin2hex(random_bytes(8)); // Generate UUID
    $now = date('Y-m-d H:i:s');
    
    $stmt->execute([
        ':uuid' => $service_uuid,
        ':name' => $data['name'],
        ':description' => $data['description'] ?? '',
        ':template_id' => (int)$data['template_id'],
        ':project_uuid' => $data['project_uuid'],
        ':environment_name' => $data['environment_name'],
        ':server_uuid' => $data['server_uuid'],
        ':destination_uuid' => $data['destination_uuid'],
        ':status' => 'created',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    error_log('[Custom API - Create Service] Service created successfully with UUID: ' . $service_uuid);
    
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'uuid' => $service_uuid,
        'message' => 'Service created successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    error_log('[Custom API - Create Service] ERROR: ' . $e->getMessage());
    echo json_encode(['error' => 'Failed to create service', 'details' => $e->getMessage()]);
    exit;
}
?>
