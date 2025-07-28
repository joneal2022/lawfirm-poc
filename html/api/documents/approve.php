<?php
/**
 * API endpoint to approve document OCR
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
$document_id = isset($input['document_id']) ? intval($input['document_id']) : 0;
$csrf_token = $input['csrf_token'] ?? '';

// Verify CSRF token
if (!$auth->verifyCSRFToken($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit();
}

if (!$document_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Document ID required']);
    exit();
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Verify document belongs to user's firm
    $sql = "SELECT d.id, d.ocr_status FROM documents d
            JOIN intake_forms if ON d.intake_id = if.id
            WHERE d.id = ? AND if.firm_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$document_id, $_SESSION['firm_id']]);
    $document = $stmt->fetch();
    
    if (!$document) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit();
    }
    
    $conn->beginTransaction();
    
    // Update document content to mark as reviewed
    $sql = "UPDATE document_content 
            SET reviewed_by = ?, reviewed_at = NOW()
            WHERE document_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$_SESSION['user_id'], $document_id]);
    
    // Update document status to completed
    $sql = "UPDATE documents 
            SET ocr_status = 'completed'
            WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$document_id]);
    
    // Log the approval in audit trail
    $sql = "INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
            VALUES (?, 'update', 'documents', ?, ?, ?, ?, NOW())";
    
    $audit_data = json_encode([
        'action' => 'document_approved',
        'previous_status' => $document['ocr_status'],
        'new_status' => 'completed'
    ]);
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$_SESSION['user_id'], $document_id, $audit_data, $ip_address, $user_agent]);
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Document approved successfully'
    ]);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    error_log("Document approval error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>