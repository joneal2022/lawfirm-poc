<?php
/**
 * API endpoint to update OCR text corrections
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

// Get POST data
$document_id = isset($_POST['document_id']) ? intval($_POST['document_id']) : 0;
$corrected_text = $_POST['corrected_text'] ?? '';
$notes = $_POST['notes'] ?? '';

if (!$document_id || empty($corrected_text)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Document ID and corrected text required']);
    exit();
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Verify document belongs to user's firm
    $sql = "SELECT d.id FROM documents d
            JOIN intake_forms if ON d.intake_id = if.id
            WHERE d.id = ? AND if.firm_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$document_id, $_SESSION['firm_id']]);
    
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit();
    }
    
    $conn->beginTransaction();
    
    // Update document content with corrected text
    $sql = "UPDATE document_content 
            SET corrected_text = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE document_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$corrected_text, $_SESSION['user_id'], $document_id]);
    
    // Update document status
    $sql = "UPDATE documents 
            SET ocr_status = 'completed'
            WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$document_id]);
    
    // Log the correction in audit trail
    $sql = "INSERT INTO audit_log (user_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
            VALUES (?, 'update', 'document_content', ?, ?, ?, ?, NOW())";
    
    $audit_data = json_encode([
        'corrected_text_length' => strlen($corrected_text),
        'notes' => $notes,
        'action' => 'ocr_correction'
    ]);
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$_SESSION['user_id'], $document_id, $audit_data, $ip_address, $user_agent]);
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'OCR corrections saved successfully'
    ]);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    error_log("OCR update error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>