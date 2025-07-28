<?php
/**
 * Case Merit Analysis API Endpoint
 * AI-powered case evaluation for intake decisions
 */

define('LEGAL_INTAKE_SYSTEM', true);
require_once '../../includes/header.php';
require_once '../../includes/auth.php';
require_once '../../includes/ai_service_client.php';

// Check authentication and permissions
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Only attorneys and above can access case merit analysis
$user_role = $_SESSION['user']['role'] ?? '';
if (!in_array($user_role, [ROLE_ATTORNEY, ROLE_MANAGING_PARTNER, ROLE_SYSTEM_ADMIN])) {
    http_response_code(403);
    echo json_encode(['error' => 'Insufficient permissions']);
    exit;
}

// CORS headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    $action = $input['action'] ?? '';
    $intake_id = $input['intake_id'] ?? null;
    
    if (empty($action)) {
        throw new Exception('Action is required');
    }
    
    if (empty($intake_id)) {
        throw new Exception('Intake ID is required');
    }
    
    // Check AI service availability
    if (!isAIServiceAvailable()) {
        throw new Exception('AI services are currently unavailable');
    }
    
    $db = new Database();
    $ai_client = getAIServiceClient();
    $response = [];
    
    switch ($action) {
        case 'analyze_case_merit':
            // Get intake information
            $intake_stmt = $db->getConnection()->prepare(
                "SELECT * FROM intake_forms WHERE id = ? AND firm_id = ?"
            );
            $intake_stmt->execute([$intake_id, $_SESSION['user']['firm_id']]);
            $intake = $intake_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$intake) {
                throw new Exception('Intake not found or access denied');
            }
            
            // Get associated documents
            $docs_stmt = $db->getConnection()->prepare(
                "SELECT id, file_path, document_type, created_at FROM documents WHERE intake_id = ?"
            );
            $docs_stmt->execute([$intake_id]);
            $documents = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Prepare case context
            $case_context = [
                'intake_id' => $intake_id,
                'incident_date' => $intake['incident_date'] ?? null,
                'client_name' => $db->decrypt($intake['client_name'] ?? ''),
                'case_type' => $intake['case_type'] ?? 'personal_injury',
                'incident_description' => $intake['incident_description'] ?? '',
                'injury_description' => $intake['injury_description'] ?? '',
                'total_documents' => count($documents)
            ];
            
            // Format documents for AI analysis
            $formatted_documents = [];
            foreach ($documents as $doc) {
                $formatted_documents[] = [
                    'id' => $doc['id'],
                    'file_path' => $doc['file_path'],
                    'type' => $doc['document_type'] ?? 'standard',
                    'created_at' => $doc['created_at']
                ];
            }
            
            // Perform comprehensive case analysis
            $analysis_result = $ai_client->analyzeCaseComplete($intake_id, $formatted_documents, $case_context);
            
            if ($analysis_result['status'] === 'completed') {
                // Store results in database
                $merit_data = $analysis_result['case_merit_analysis'];
                
                $store_stmt = $db->getConnection()->prepare(
                    "INSERT INTO case_scores (
                        intake_id, liability_strength, damages_potential, collectibility_score,
                        case_complexity, resource_requirements, success_probability, overall_score,
                        recommendation, estimated_settlement_range_low, estimated_settlement_range_high,
                        analysis_notes, model_used, model_confidence, created_by, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        liability_strength = VALUES(liability_strength),
                        damages_potential = VALUES(damages_potential),
                        collectibility_score = VALUES(collectibility_score),
                        case_complexity = VALUES(case_complexity),
                        resource_requirements = VALUES(resource_requirements),
                        success_probability = VALUES(success_probability),
                        overall_score = VALUES(overall_score),
                        recommendation = VALUES(recommendation),
                        estimated_settlement_range_low = VALUES(estimated_settlement_range_low),
                        estimated_settlement_range_high = VALUES(estimated_settlement_range_high),
                        analysis_notes = VALUES(analysis_notes),
                        model_used = VALUES(model_used),
                        model_confidence = VALUES(model_confidence),
                        updated_at = NOW()"
                );
                
                $store_stmt->execute([
                    $intake_id,
                    $merit_data['component_scores']['liability_strength'] ?? 0,
                    $merit_data['component_scores']['damages_potential'] ?? 0,
                    $merit_data['component_scores']['collectibility'] ?? 0,
                    $merit_data['component_scores']['case_complexity'] ?? 0,
                    $merit_data['component_scores']['resource_requirements'] ?? 0,
                    $merit_data['component_scores']['success_probability'] ?? 0,
                    $merit_data['overall_score'] ?? 0,
                    $merit_data['recommendation']['recommendation'] ?? 'review',
                    $merit_data['value_estimate']['low_estimate'] ?? 0,
                    $merit_data['value_estimate']['high_estimate'] ?? 0,
                    json_encode($merit_data['recommendation']['reasoning'] ?? ''),
                    'AI Analysis',
                    $merit_data['recommendation']['confidence'] ?? 0,
                    $_SESSION['user']['id']
                ]);
                
                // Update intake status based on recommendation
                $new_status = STATUS_ATTORNEY_REVIEW;
                $recommendation = $merit_data['recommendation']['recommendation'] ?? 'review';
                
                if ($recommendation === 'accept') {
                    $new_status = STATUS_ACCEPTED;
                } elseif ($recommendation === 'decline') {
                    $new_status = STATUS_DECLINED;
                }
                
                $update_stmt = $db->getConnection()->prepare(
                    "UPDATE intake_forms SET status = ?, updated_at = NOW() WHERE id = ?"
                );
                $update_stmt->execute([$new_status, $intake_id]);
            }
            
            $response = $analysis_result;
            break;
            
        case 'get_case_scores':
            // Retrieve existing case scores
            $scores_stmt = $db->getConnection()->prepare(
                "SELECT cs.*, if.client_name, if.incident_date 
                 FROM case_scores cs 
                 JOIN intake_forms if ON cs.intake_id = if.id 
                 WHERE cs.intake_id = ? AND if.firm_id = ?"
            );
            $scores_stmt->execute([$intake_id, $_SESSION['user']['firm_id']]);
            $scores = $scores_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($scores) {
                // Decrypt sensitive data
                $scores['client_name'] = $db->decrypt($scores['client_name']);
                
                // Parse JSON fields
                $scores['analysis_notes'] = json_decode($scores['analysis_notes'] ?? '[]', true);
                $scores['legal_theories'] = json_decode($scores['legal_theories'] ?? '[]', true);
                $scores['risk_factors'] = json_decode($scores['risk_factors'] ?? '[]', true);
            }
            
            $response = [
                'status' => $scores ? 'found' : 'not_found',
                'data' => $scores
            ];
            break;
            
        case 'update_attorney_decision':
            $final_decision = $input['final_decision'] ?? '';
            $attorney_notes = $input['attorney_notes'] ?? '';
            
            if (!in_array($final_decision, ['accepted', 'declined'])) {
                throw new Exception('Invalid final decision');
            }
            
            // Update case scores with attorney decision
            $update_stmt = $db->getConnection()->prepare(
                "UPDATE case_scores 
                 SET attorney_override = 1, attorney_notes = ?, final_decision = ?, updated_at = NOW()
                 WHERE intake_id = ?"
            );
            $update_stmt->execute([$attorney_notes, $final_decision, $intake_id]);
            
            // Update intake status
            $new_status = $final_decision === 'accepted' ? STATUS_ACCEPTED : STATUS_DECLINED;
            $intake_update_stmt = $db->getConnection()->prepare(
                "UPDATE intake_forms SET status = ?, updated_at = NOW() WHERE id = ?"
            );
            $intake_update_stmt->execute([$new_status, $intake_id]);
            
            $response = [
                'status' => 'updated',
                'final_decision' => $final_decision,
                'new_intake_status' => $new_status
            ];
            break;
            
        case 'get_merit_history':
            // Get historical merit analysis data
            $history_stmt = $db->getConnection()->prepare(
                "SELECT cs.*, u.name as attorney_name
                 FROM case_scores cs
                 LEFT JOIN users u ON cs.created_by = u.id
                 WHERE cs.intake_id = ?
                 ORDER BY cs.created_at DESC"
            );
            $history_stmt->execute([$intake_id]);
            $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($history as &$record) {
                $record['analysis_notes'] = json_decode($record['analysis_notes'] ?? '[]', true);
                $record['legal_theories'] = json_decode($record['legal_theories'] ?? '[]', true);
                $record['risk_factors'] = json_decode($record['risk_factors'] ?? '[]', true);
            }
            
            $response = [
                'status' => 'success',
                'history' => $history
            ];
            break;
            
        default:
            throw new Exception('Unknown action: ' . $action);
    }
    
    // Audit log
    $db->executeWithAudit(
        "INSERT INTO audit_log (user_id, action, table_name, record_id, sql_query, ip_address, user_agent, created_at) 
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
        [
            $_SESSION['user']['id'],
            'case_merit_analysis',
            'case_scores',
            $intake_id,
            json_encode(['action' => $action, 'status' => $response['status'] ?? 'unknown']),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]
    );
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $response,
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    error_log("Case Merit Analysis API Error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
?>