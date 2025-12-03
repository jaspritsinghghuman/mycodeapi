<?php
header('Content-Type: application/json');

// ================= CONFIG =================
$COOLIFY_URL = "https://coolify.jassweb.com";

$provided_api_token = isset($_GET['key']) ? $_GET['key'] : '';

// --- API Token Check ---
if (empty($provided_api_token)) {
    http_response_code(401);
    echo json_encode([
        'error' => 'Unauthorized',
        'message' => 'API token parameter is missing. Please provide ?key=YOUR_COOLIFY_API_TOKEN',
        'hint' => 'The custom API now uses your main Coolify API token. No separate API key needed.'
    ]);
    exit;
}

// --- Get UUID from URL ---
if (!isset($_GET['uuid']) || empty($_GET['uuid'])) {
    http_response_code(400);
    echo json_encode(['error' => 'UUID parameter missing']);
    exit;
}

$uuid = $_GET['uuid'];
$method = $_SERVER['REQUEST_METHOD'];

// ================= Connect to Coolify DB =================
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
    echo json_encode(['error' => 'Database connection failed', 'details' => $e->getMessage()]);
    exit;
}

// ================= Find application =================
$appUuid = null;
$serviceUuid = null;
$resourceType = null;
$envVars = null;

// Check service_applications first
$stmt = $pdo->prepare("
    SELECT sa.uuid AS app_uuid, sa.name AS app_name, sa.env_variables, s.uuid AS service_uuid
    FROM service_applications sa
    JOIN services s ON sa.service_id = s.id
    WHERE sa.uuid = :uuid
    LIMIT 1
");
$stmt->execute(['uuid' => $uuid]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if ($app) {
    $appUuid = $app['app_uuid'];
    $serviceUuid = $app['service_uuid'];
    $envVars = $app['env_variables'];
    $resourceType = 'service';
} else {
    // Check applications table (standalone applications)
    $stmt = $pdo->prepare("
        SELECT uuid, name, env_variables
        FROM applications
        WHERE uuid = :uuid
        LIMIT 1
    ");
    $stmt->execute(['uuid' => $uuid]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($app) {
        $appUuid = $app['uuid'];
        $envVars = $app['env_variables'];
        $resourceType = 'application';
    } else {
        // find the first service_application child and use that for env var operations
        $stmt = $pdo->prepare("
            SELECT sa.uuid AS app_uuid, sa.name AS app_name, sa.env_variables, s.uuid AS service_uuid
            FROM service_applications sa
            JOIN services s ON sa.service_id = s.id
            WHERE s.uuid = :uuid
            ORDER BY sa.created_at ASC
            LIMIT 1
        ");
        $stmt->execute(['uuid' => $uuid]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($app) {
            // Found a child service_application for this parent service
            $appUuid = $app['app_uuid'];
            $serviceUuid = $app['service_uuid'];
            $envVars = $app['env_variables'];
            $resourceType = 'service';
        }
    }
}

if (!$appUuid) {
    http_response_code(404);
    echo json_encode([
        'error' => 'Application not found',
        'details' => 'UUID not found in service_applications or applications tables',
        'uuid_searched' => $uuid,
        'hint' => 'Make sure you are sending the APPLICATION UUID, not the SERVICE UUID. Check the last part of your Coolify URL.'
    ]);
    exit;
}

// ================= Handle GET request (fetch env vars) =================
if ($method === 'GET') {
    echo json_encode([
        'success' => true,
        'uuid' => $appUuid,
        'resource_type' => $resourceType,
        'environment_variables' => $envVars ? json_decode($envVars, true) : []
    ], JSON_PRETTY_PRINT);
    exit;
}

// ================= Handle POST request (update env vars) =================
if ($method === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!isset($data['environment_variables'])) {
        http_response_code(400);
        echo json_encode(['error' => 'environment_variables parameter missing']);
        exit;
    }
    
    $newEnvVars = json_encode($data['environment_variables']);
    
    // Update database
    if ($resourceType === 'service') {
        $updateStmt = $pdo->prepare("UPDATE service_applications SET env_variables = :env, updated_at = NOW() WHERE uuid = :uuid");
    } else {
        $updateStmt = $pdo->prepare("UPDATE applications SET env_variables = :env, updated_at = NOW() WHERE uuid = :uuid");
    }
    
    $updateStmt->execute(['env' => $newEnvVars, 'uuid' => $appUuid]);
    
    if ($updateStmt->rowCount() === 0) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update environment variables']);
        exit;
    }
    
    // Trigger redeploy via API
    $redeployEndpoint = null;
    if ($resourceType === 'service' && $serviceUuid) {
        $redeployEndpoint = "$COOLIFY_URL/api/v1/services/$serviceUuid/applications/$appUuid/redeploy";
    } else {
        $redeployEndpoint = "$COOLIFY_URL/api/v1/applications/$appUuid/restart";
    }
    
    $ch = curl_init($redeployEndpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$provided_api_token}",
            "Accept: application/json"
        ]
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo json_encode([
        'success' => true,
        'uuid' => $appUuid,
        'resource_type' => $resourceType,
        'environment_variables_updated' => true,
        'redeploy_triggered' => $httpCode >= 200 && $httpCode < 300,
        'redeploy_http_code' => $httpCode
    ], JSON_PRETTY_PRINT);
    exit;
}

// ================= Unsupported method =================
http_response_code(405);
echo json_encode(['error' => 'Method not allowed. Use GET or POST.']);
