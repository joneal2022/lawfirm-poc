<?php
/**
 * API endpoint to assign intake to user
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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$intake_id = isset($input['intake_id']) ? intval($input['intake_id']) : 0;
$user_id = isset($input['user_id']) ? intval($input['user_id']) : 0;
$csrf_token = $input['csrf_token'] ?? '';

// Verify CSRF token
if (!$auth->verifyCSRFToken($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit();
}

if (!$intake_id || !$user_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Intake ID and user ID required']);
    exit();
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Verify intake belongs to user's firm
    $sql = "SELECT id, assigned_to FROM intake_forms WHERE id = ? AND firm_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$intake_id, $_SESSION['firm_id']]);
    $intake = $stmt->fetch();
    
    if (!$intake) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Intake not found']);
        exit();
    }
    
    // Verify user belongs to same firm and has appropriate role
    $sql = "SELECT id, first_name, last_name, role FROM users 
            WHERE id = ? AND firm_id = ? AND role IN (?, ?, ?) AND is_active = 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id, $_SESSION['firm_id'], ROLE_PARALEGAL, ROLE_ATTORNEY, ROLE_MANAGING_PARTNER]);
    $assignee = $stmt->fetch();
    
    if (!$assignee) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Invalid user for assignment']);
        exit();
    }
    
    $old_assigned_to = $intake['assigned_to'];
    
    $conn->beginTransaction();
    
    // Update intake assignment
    $sql = "UPDATE intake_forms SET assigned_to = ?, updated_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$user_id, $intake_id]);
    
    // Log the assignment change in audit trail
    $sql = "INSERT INTO audit_log (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
            VALUES (?, 'update', 'intake_forms', ?, ?, ?, ?, ?, NOW())";
    
    $old_values = json_encode(['assigned_to' => $old_assigned_to]);
    $new_values = json_encode([
        'assigned_to' => $user_id,
        'assigned_to_name' => $assignee['first_name'] . ' ' . $assignee['last_name'],
        'assigned_by' => $_SESSION['user_id']
    ]);
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$_SESSION['user_id'], $intake_id, $old_values, $new_values, $ip_address, $user_agent]);
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Intake assigned successfully',
        'assigned_to' => [
            'id' => $assignee['id'],
            'name' => $assignee['first_name'] . ' ' . $assignee['last_name'],
            'role' => $assignee['role']
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    error_log("Assignment error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>