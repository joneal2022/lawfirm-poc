<?php
/**
 * Document Analysis API Endpoint
 * Integrates with Python AI services for document processing
 */

define('LEGAL_INTAKE_SYSTEM', true);
require_once '../../includes/header.php';
require_once '../../includes/auth.php';
require_once '../../includes/ai_service_client.php';

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// CORS headers for API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Get request data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    $action = $input['action'] ?? '';
    $document_id = $input['document_id'] ?? null;
    $intake_id = $input['intake_id'] ?? null;
    
    // Validate required fields
    if (empty($action)) {
        throw new Exception('Action is required');
    }
    
    if (empty($document_id) && !in_array($action, ['test_services', 'case_analysis'])) {
        throw new Exception('Document ID is required');
    }
    
    // Check AI service availability
    if (!isAIServiceAvailable()) {
        throw new Exception('AI services are currently unavailable');
    }
    
    $ai_client = getAIServiceClient();
    $response = [];
    
    // Handle different actions
    switch ($action) {
        case 'classify_document':
            $content = $input['content'] ?? '';
            if (empty($content)) {
                throw new Exception('Document content is required');
            }
            
            $response = $ai_client->classifyDocument($document_id, $content);
            break;
            
        case 'extract_medical':
            $content = $input['content'] ?? '';
            if (empty($content)) {
                throw new Exception('Document content is required');
            }
            
            $response = $ai_client->extractMedicalInfo($document_id, $content);
            break;
            
        case 'analyze_legal':
            $content = $input['content'] ?? '';
            $case_context = $input['case_context'] ?? null;
            
            if (empty($content)) {
                throw new Exception('Document content is required');
            }
            
            $response = $ai_client->analyzeLegalDocument($document_id, $content, $case_context);
            break;
            
        case 'process_ocr':
            $file_path = $input['file_path'] ?? '';
            $document_type = $input['document_type'] ?? 'standard';
            
            if (empty($file_path)) {
                throw new Exception('File path is required');
            }
            
            // Validate file exists and is accessible
            if (!file_exists($file_path)) {
                throw new Exception('File not found: ' . $file_path);
            }
            
            $response = $ai_client->processDocument($document_id, $file_path, $document_type);
            break;
            
        case 'complete_document_analysis':
            $file_path = $input['file_path'] ?? '';
            $document_type = $input['document_type'] ?? 'standard';
            
            if (empty($file_path)) {
                throw new Exception('File path is required');
            }
            
            if (!file_exists($file_path)) {
                throw new Exception('File not found: ' . $file_path);
            }
            
            $response = $ai_client->analyzeDocumentComplete($document_id, $file_path, $intake_id, $document_type);
            break;
            
        case 'case_analysis':
            if (empty($intake_id)) {
                throw new Exception('Intake ID is required for case analysis');
            }
            
            $documents = $input['documents'] ?? [];
            $case_context = $input['case_context'] ?? [];
            
            if (empty($documents)) {
                throw new Exception('At least one document is required for case analysis');
            }
            
            $response = $ai_client->analyzeCaseComplete($intake_id, $documents, $case_context);
            break;
            
        case 'test_services':
            $test_text = $input['test_text'] ?? 'This is a test document for AI analysis.';
            
            $response = [
                'health_check' => $ai_client->healthCheck(),
                'phi_redaction_test' => $ai_client->testPHIRedaction($test_text),
                'routing_test' => $ai_client->testLLMRouting('document_classification', $test_text)
            ];
            break;
            
        case 'get_token_usage':
            $firm_id = $_SESSION['user']['firm_id'] ?? 1;
            $start_date = $input['start_date'] ?? null;
            $end_date = $input['end_date'] ?? null;
            
            $response = $ai_client->getTokenUsage($firm_id, $start_date, $end_date);
            break;
            
        default:
            throw new Exception('Unknown action: ' . $action);
    }
    
    // Log successful AI service call
    $db = new Database();
    $db->executeWithAudit(
        "INSERT INTO audit_log (user_id, action, table_name, record_id, sql_query, ip_address, user_agent, created_at) 
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
        [
            $_SESSION['user']['id'],
            'ai_service_call',
            'ai_analysis',
            $document_id,
            json_encode(['action' => $action, 'status' => $response['status'] ?? 'unknown']),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]
    );
    
    // Return response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $response,
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    error_log("AI Document Analysis API Error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
?>