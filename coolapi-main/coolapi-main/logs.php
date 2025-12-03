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

// --- Get UUID from URL ---
if (!isset($_GET['uuid']) || empty($_GET['uuid'])) {
    http_response_code(400);
    echo json_encode(['error' => 'UUID parameter missing']);
    exit;
}

$uuid = $_GET['uuid'];
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;

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

// ================= Find application logs =================
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

// ================= Output JSON =================
echo json_encode([
    'success' => true,
    'uuid' => $uuid,
    'log_count' => count($logs),
    'logs' => $logs
], JSON_PRETTY_PRINT);
