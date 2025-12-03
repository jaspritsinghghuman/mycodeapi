<?php
header('Content-Type: application/json');

// ================= CONFIG =================
$COOLIFY_URL = "https://coolify.jassweb.com"; // Coolify base URL

$provided_api_token = isset($_GET['key']) ? $_GET['key'] : '';

error_log('[Custom API] ===== NEW REQUEST =====');
error_log('[Custom API] Request received from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
error_log('[Custom API] Request URI: ' . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
error_log('[Custom API] API token provided: ' . (!empty($provided_api_token) ? 'YES (length: ' . strlen($provided_api_token) . ', first 8 chars: ' . substr($provided_api_token, 0, 8) . '...)' : 'NO'));

// --- API Token Check ---
if (empty($provided_api_token)) {
    http_response_code(401);
    error_log('[Custom API] ERROR: API token parameter is missing');
    echo json_encode([
        'error' => 'Unauthorized',
        'message' => 'API token parameter is missing. Please provide ?key=YOUR_COOLIFY_API_TOKEN',
        'hint' => 'The custom API now uses your main Coolify API token. No separate API key needed.'
    ]);
    exit;
}

error_log('[Custom API] Authentication successful - will use provided token for Coolify API calls');

// --- Get UUID and FQDN from URL ---
if (!isset($_GET['uuid']) || empty($_GET['uuid'])) {
    http_response_code(400);
    error_log('[Custom API] ERROR: UUID parameter missing');
    echo json_encode(['error' => 'UUID parameter missing']);
    exit;
}

if (!isset($_GET['fqdn']) || empty($_GET['fqdn'])) {
    http_response_code(400);
    error_log('[Custom API] ERROR: FQDN parameter missing');
    echo json_encode(['error' => 'FQDN parameter missing']);
    exit;
}

$uuid = $_GET['uuid'];
$rawFqdn = $_GET['fqdn'];

error_log('[Custom API] Raw FQDN received: "' . $rawFqdn . '"');
error_log('[Custom API] Raw FQDN length: ' . strlen($rawFqdn));

$newDomain = strtolower(trim($rawFqdn)); // This is just the domain part (e.g., subdomain.jassweb.com)

if (strpos($newDomain, '.') === false) {
    error_log('[Custom API] WARNING: Domain seems to be missing TLD: ' . $newDomain);
}

error_log('[Custom API] After strtolower and trim: "' . $newDomain . '"');
error_log('[Custom API] After processing length: ' . strlen($newDomain));
error_log('[Custom API] Processing subdomain update for UUID: ' . $uuid . ' to domain: ' . $newDomain);

// Validate domain (letters, numbers, hyphens, dots)
if (!preg_match('/^[a-z0-9.-]+$/', $newDomain)) {
    error_log('[Custom API] ERROR: Invalid domain format received');
    error_log('[Custom API] Domain value: "' . $newDomain . '"');
    error_log('[Custom API] Domain length: ' . strlen($newDomain));
    error_log('[Custom API] Invalid characters: "' . preg_replace('/[a-z0-9.-]/', '', $newDomain) . '"');
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid domain',
        'received' => $newDomain,
        'length' => strlen($newDomain),
        'invalid_chars' => preg_replace('/[a-z0-9.-]/', '', $newDomain)
    ]);
    exit;
}

error_log('[Custom API] Domain validation passed');

// ================= 1️⃣ Connect to Coolify DB =================
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
    error_log('[Custom API] ERROR: Database connection failed - ' . $e->getMessage());
    echo json_encode(['error' => 'Database connection failed', 'details' => $e->getMessage()]);
    exit;
}

// ================= 2️⃣ Find application and get current FQDN =================
$appUuid = null;
$serviceUuid = null;
$appName = null;
$resourceType = null;
$currentFqdn = null;

$stmt = $pdo->prepare("
    SELECT sa.uuid AS app_uuid, sa.name AS app_name, sa.fqdn AS current_fqdn, s.uuid AS service_uuid
    FROM service_applications sa
    JOIN services s ON sa.service_id = s.id
    WHERE sa.uuid = :uuid
    LIMIT 1
");
$stmt->execute(['uuid' => $uuid]);
$app = $stmt->fetch(PDO::FETCH_ASSOC);

if ($app) {
    // Found in service_applications
    $appUuid = $app['app_uuid'];
    $serviceUuid = $app['service_uuid'];
    $appName = $app['app_name'];
    $currentFqdn = $app['current_fqdn'];
    $resourceType = 'service';

    // If this is a service, let's check if there's a better candidate to update
    // e.g. if we found "n8n-worker" but there is an "n8n" app in the same service
    try {
        $candidateStmt = $pdo->prepare("
            SELECT uuid, name, fqdn 
            FROM service_applications 
            WHERE service_id = (SELECT service_id FROM service_applications WHERE uuid = :uuid)
            AND name NOT ILIKE '%worker%'
            AND name NOT ILIKE '%redis%'
            AND name NOT ILIKE '%postgres%'
            AND name NOT ILIKE '%db%'
            ORDER BY id ASC
            LIMIT 1
        ");
        $candidateStmt->execute(['uuid' => $uuid]);
        $betterCandidate = $candidateStmt->fetch(PDO::FETCH_ASSOC);

        if ($betterCandidate) {
            error_log("[Custom API] Switching target from '{$appName}' ({$appUuid}) to better candidate '{$betterCandidate['name']}' ({$betterCandidate['uuid']})");
            $appUuid = $betterCandidate['uuid'];
            $appName = $betterCandidate['name'];
            $currentFqdn = $betterCandidate['fqdn'];
        }
    } catch (Exception $e) {
        error_log("[Custom API] Warning: Failed to check for better service candidate: " . $e->getMessage());
    }

} else {
    $stmt = $pdo->prepare("
        SELECT uuid, name, fqdn AS current_fqdn
        FROM applications
        WHERE uuid = :uuid
        LIMIT 1
    ");
    $stmt->execute(['uuid' => $uuid]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($app) {
        // Found in applications table
        $appUuid = $app['uuid'];
        $appName = $app['name'];
        $currentFqdn = $app['current_fqdn'];
        $resourceType = 'application';
    }
}

if (!$appUuid) {
    http_response_code(404);
    error_log('[Custom API] ERROR: Application not found - UUID: ' . $uuid);
    echo json_encode([
        'error' => 'Application not found',
        'details' => 'UUID not found in service_applications or applications tables',
        'uuid_searched' => $uuid,
        'hint' => 'Make sure you are sending the APPLICATION UUID, not the SERVICE UUID. Check the last part of your Coolify URL.'
    ]);
    exit;
}

// ================= 3️⃣ Build new FQDN preserving protocol and port =================
$protocol = 'http://'; // default
$port = '';

if (!empty($currentFqdn)) {
    // Extract protocol
    if (preg_match('#^(https?)://#i', $currentFqdn, $matches)) {
        $protocol = strtolower($matches[1]) . '://';
    }
    
    // Extract port
    if (preg_match('#:(\d+)$#', $currentFqdn, $matches)) {
        $port = ':' . $matches[1];
    }
}

$newFqdn = $protocol . $newDomain . $port;

// ================= 4️⃣ Update FQDN in DB =================
if ($resourceType === 'service') {
    $updateStmt = $pdo->prepare("UPDATE service_applications SET fqdn = :fqdn, updated_at = NOW() WHERE uuid = :uuid");
    $updateStmt->execute(['fqdn' => $newFqdn, 'uuid' => $appUuid]);
} else {
    $updateStmt = $pdo->prepare("UPDATE applications SET fqdn = :fqdn, updated_at = NOW() WHERE uuid = :uuid");
    $updateStmt->execute(['fqdn' => $newFqdn, 'uuid' => $appUuid]);
}

if ($updateStmt->rowCount() === 0) {
    http_response_code(500);
    error_log('[Custom API] ERROR: Failed to update FQDN in database for UUID: ' . $appUuid);
    echo json_encode(['error' => 'Failed to update FQDN in database']);
    exit;
}

error_log('[Custom API] Successfully updated FQDN in database for UUID: ' . $appUuid);

// ================= 5️⃣ Trigger redeploy via API =================
$redeployEndpoint = null;
$redeployResponse = null;
$httpCode = null;

if ($resourceType === 'service' && $serviceUuid) {
    // For services, restart the entire service (which restarts all containers including the one we updated)
    $redeployEndpoint = "$COOLIFY_URL/api/v1/services/$serviceUuid/restart";
} else {
    // For standalone applications, use the application restart endpoint
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
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    error_log('[Custom API] ERROR: Failed to trigger redeploy/restart - ' . $curlErr);
}

error_log('[Custom API] Triggered redeploy/restart for UUID: ' . $appUuid . ' - Endpoint: ' . $redeployEndpoint . ' - HTTP Code: ' . $httpCode);

// ================= 6️⃣ Output JSON =================
$result = [
    'success' => true,
    'resource_type' => $resourceType,
    'app_name' => $appName,
    'uuid' => $appUuid,
    'old_fqdn' => $currentFqdn,
    'new_domain' => $newDomain,
    'new_fqdn' => $newFqdn,
    'protocol_preserved' => $protocol,
    'port_preserved' => $port ? $port : 'none',
    'redeploy_endpoint' => $redeployEndpoint,
    'redeploy_http' => $httpCode,
    'redeploy_response' => $response,
    'redeploy_success' => ($httpCode >= 200 && $httpCode < 300)
];

if ($serviceUuid) {
    $result['service_uuid'] = $serviceUuid;
}

if ($curlErr) {
    $result['redeploy_curl_error'] = $curlErr;
}

echo json_encode($result, JSON_PRETTY_PRINT);
