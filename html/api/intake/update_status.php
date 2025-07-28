<?php
/**
 * API endpoint to update intake status
 */

define('LEGAL_INTAKE_SYSTEM', true);
session_start();

require_once '../../config/config.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

$auth = new AuthSystem();

// Check authentication
if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Check permissions
if (!$auth->hasPermission(ROLE_PARALEGAL)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get input data (support both JSON and form data)
$input_data = null;
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';

if (strpos($content_type, 'application/json') !== false) {
    $input_data = json_decode(file_get_contents('php://input'), true);
} else {
    $input_data = $_POST;
}

$intake_id = isset($input_data['intake_id']) ? intval($input_data['intake_id']) : 0;
$new_status = $input_data['status'] ?? '';
$reason = $input_data['reason'] ?? '';
$csrf_token = $input_data['csrf_token'] ?? '';

// Verify CSRF token
if (!$auth->verifyCSRFToken($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit();
}

// Validate input
if (!$intake_id || !$new_status) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Intake ID and status required']);
    exit();
}

// Validate status value
$valid_statuses = [
    STATUS_NEW_INTAKE,
    STATUS_DOCUMENTS_PENDING,
    STATUS_UNDER_REVIEW,
    STATUS_ADDITIONAL_INFO_NEEDED,
    STATUS_ATTORNEY_REVIEW,
    STATUS_ACCEPTED,
    STATUS_DECLINED
];

if (!in_array($new_status, $valid_statuses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status value']);
    exit();
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Verify intake belongs to user's firm and get current status
    $sql = "SELECT id, status FROM intake_forms WHERE id = ? AND firm_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$intake_id, $_SESSION['firm_id']]);
    $intake = $stmt->fetch();
    
    if (!$intake) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Intake not found']);
        exit();
    }
    
    $old_status = $intake['status'];
    
    // Don't update if status is the same
    if ($old_status === $new_status) {
        echo json_encode(['success' => true, 'message' => 'Status is already set to this value']);
        exit();
    }
    
    $conn->beginTransaction();
    
    // Update intake status
    $sql = "UPDATE intake_forms SET status = ?, updated_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$new_status, $intake_id]);
    
    // Add to status history
    $sql = "INSERT INTO intake_status_history (intake_id, old_status, new_status, changed_by, reason, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$intake_id, $old_status, $new_status, $_SESSION['user_id'], $reason]);
    
    // Log the change in audit trail
    $sql = "INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
            VALUES (?, 'update', 'intake_forms', ?, ?, ?, ?, ?, NOW())";
    
    $old_values = json_encode(['status' => $old_status]);
    $new_values = json_encode(['status' => $new_status, 'reason' => $reason]);
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$_SESSION['user_id'], $intake_id, $old_values, $new_values, $ip_address, $user_agent]);
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Status updated successfully',
        'old_status' => $old_status,
        'new_status' => $new_status
    ]);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    error_log("Status update error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>