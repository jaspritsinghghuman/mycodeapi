<?php
header('Content-Type: application/json');

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

$uuid = isset($_GET['uuid']) ? $_GET['uuid'] : '';
$fqdn = isset($_GET['fqdn']) ? $_GET['fqdn'] : '';
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;

// Validate we have at least one identifier
if (empty($uuid) && empty($fqdn)) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing parameter',
        'message' => 'Either uuid or fqdn parameter is required',
        'hint' => 'Provide ?uuid=APP_UUID or ?fqdn=subdomain.domain.com'
    ]);
    exit;
}

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

// ================= Find application by UUID or FQDN =================
if (!empty($uuid)) {
    // Search by UUID
    $stmt = $pdo->prepare("
        SELECT 
            id,
            application_id,
            service_id,
            message,
            level,
            created_at
        FROM application_deployment_logs
        WHERE application_id = :uuid OR service_id = :uuid
        ORDER BY created_at DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':uuid', $uuid, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'search_type' => 'uuid',
        'uuid' => $uuid,
        'log_count' => count($logs),
        'logs' => $logs
    ], JSON_PRETTY_PRINT);
} else {
    // Search by FQDN
    // First find the application with this FQDN
    $stmt = $pdo->prepare("
        SELECT uuid FROM applications WHERE fqdn LIKE :fqdn
        UNION
        SELECT uuid FROM service_applications WHERE fqdn LIKE :fqdn
        LIMIT 1
    ");
    $stmt->bindValue(':fqdn', '%' . $fqdn . '%', PDO::PARAM_STR);
    $stmt->execute();
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$app) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Application not found',
            'message' => 'No application found with FQDN: ' . $fqdn
        ]);
        exit;
    }
    
    $app_uuid = $app['uuid'];
    
    // Now get logs for this application
    $stmt = $pdo->prepare("
        SELECT 
            id,
            application_id,
            service_id,
            message,
            level,
            created_at
        FROM application_deployment_logs
        WHERE application_id = :uuid OR service_id = :uuid
        ORDER BY created_at DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':uuid', $app_uuid, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'search_type' => 'fqdn',
        'fqdn' => $fqdn,
        'uuid' => $app_uuid,
        'log_count' => count($logs),
        'logs' => $logs
    ], JSON_PRETTY_PRINT);
}
