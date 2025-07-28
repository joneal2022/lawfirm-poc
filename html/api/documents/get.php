<?php
/**
 * API endpoint to get document information
 */

define('LEGAL_INTAKE_SYSTEM', true);
session_start();

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/document_processor.php';

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

// Get document ID
$document_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$document_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Document ID required']);
    exit();
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get document with OCR content
    $sql = "SELECT d.*, dc.ocr_text, dc.corrected_text, dc.extracted_data, dc.confidence_scores,
                   if.intake_number
            FROM documents d
            JOIN intake_forms if ON d.intake_id = if.id
            LEFT JOIN document_content dc ON d.id = dc.document_id
            WHERE d.id = ? AND if.firm_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$document_id, $_SESSION['firm_id']]);
    $document = $stmt->fetch();
    
    if (!$document) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit();
    }
    
    // Format response
    $response = [
        'success' => true,
        'document' => [
            'id' => $document['id'],
            'intake_id' => $document['intake_id'],
            'intake_number' => $document['intake_number'],
            'original_filename' => $document['original_filename'],
            'file_size' => $document['file_size'],
            'document_type' => $document['document_type'],
            'ocr_status' => $document['ocr_status'],
            'ocr_confidence' => $document['ocr_confidence'],
            'ocr_text' => $document['corrected_text'] ?: $document['ocr_text'],
            'extracted_data' => $document['extracted_data'],
            'confidence_scores' => $document['confidence_scores'],
            'created_at' => $document['created_at'],
            'processed_at' => $document['processed_at']
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Document API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
?>