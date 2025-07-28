<?php
/**
 * AI Services Client for PHP Integration
 * Connects the LAMP frontend to the Python AI services
 */

if (!defined('LEGAL_INTAKE_SYSTEM')) {
    die('Direct access not permitted');
}

class AIServiceClient {
    private $base_url;
    private $api_key;
    private $timeout;
    
    public function __construct($base_url = 'http://localhost:8000', $api_key = 'demo-token', $timeout = 300) {
        $this->base_url = rtrim($base_url, '/');
        $this->api_key = $api_key;
        $this->timeout = $timeout;
    }
    
    /**
     * Make HTTP request to AI services
     */
    private function makeRequest($endpoint, $data = null, $method = 'POST') {
        $url = $this->base_url . '/api/v1' . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->api_key,
            'Accept: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false // For development only
        ]);
        
        if ($method === 'POST' && $data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("AI Service request failed: " . $error);
        }
        
        if ($http_code >= 400) {
            throw new Exception("AI Service error: HTTP $http_code - " . $response);
        }
        
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from AI service");
        }
        
        return $decoded;
    }
    
    /**
     * Test AI service connectivity
     */
    public function healthCheck() {
        try {
            return $this->makeRequest('/health', null, 'GET');
        } catch (Exception $e) {
            error_log("AI Service health check failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Process document with OCR
     */
    public function processDocument($document_id, $file_path, $document_type = 'standard') {
        $data = [
            'document_id' => $document_id,
            'file_path' => $file_path,
            'document_type' => $document_type
        ];
        
        try {
            return $this->makeRequest('/ocr/process-document', $data);
        } catch (Exception $e) {
            error_log("OCR processing failed: " . $e->getMessage());
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Classify document type
     */
    public function classifyDocument($document_id, $content) {
        $data = [
            'document_id' => $document_id,
            'content' => $content
        ];
        
        try {
            return $this->makeRequest('/classify-document', $data);
        } catch (Exception $e) {
            error_log("Document classification failed: " . $e->getMessage());
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Extract medical information
     */
    public function extractMedicalInfo($document_id, $content) {
        $data = [
            'document_id' => $document_id,
            'content' => $content
        ];
        
        try {
            return $this->makeRequest('/medical/extract', $data);
        } catch (Exception $e) {
            error_log("Medical extraction failed: " . $e->getMessage());
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Analyze legal document
     */
    public function analyzeLegalDocument($document_id, $content, $case_context = null) {
        $data = [
            'document_id' => $document_id,
            'content' => $content,
            'case_context' => $case_context
        ];
        
        try {
            return $this->makeRequest('/legal/analyze', $data);
        } catch (Exception $e) {
            error_log("Legal analysis failed: " . $e->getMessage());
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Analyze case merit
     */
    public function analyzeCaseMerit($intake_id, $case_data) {
        $data = [
            'intake_id' => $intake_id,
            'case_data' => $case_data
        ];
        
        try {
            return $this->makeRequest('/case-merit/analyze', $data);
        } catch (Exception $e) {
            error_log("Case merit analysis failed: " . $e->getMessage());
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Test PHI redaction
     */
    public function testPHIRedaction($text) {
        $data = ['text' => $text];
        
        try {
            return $this->makeRequest('/test-phi-redaction', $data);
        } catch (Exception $e) {
            error_log("PHI redaction test failed: " . $e->getMessage());
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Test LLM routing
     */
    public function testLLMRouting($task_type, $content, $max_tokens = 500) {
        $data = [
            'task_type' => $task_type,
            'content' => $content,
            'max_tokens' => $max_tokens
        ];
        
        try {
            return $this->makeRequest('/test-routing', $data);
        } catch (Exception $e) {
            error_log("LLM routing test failed: " . $e->getMessage());
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get token usage statistics
     */
    public function getTokenUsage($firm_id, $start_date = null, $end_date = null) {
        $data = [
            'firm_id' => $firm_id,
            'start_date' => $start_date,
            'end_date' => $end_date
        ];
        
        try {
            return $this->makeRequest('/analytics/token-usage', $data);
        } catch (Exception $e) {
            error_log("Token usage query failed: " . $e->getMessage());
            return [
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Comprehensive document analysis
     * Combines OCR, classification, and relevant AI analysis
     */
    public function analyzeDocumentComplete($document_id, $file_path, $intake_id = null, $document_type = 'standard') {
        $results = [];
        
        try {
            // Step 1: OCR Processing
            $ocr_result = $this->processDocument($document_id, $file_path, $document_type);
            $results['ocr'] = $ocr_result;
            
            if ($ocr_result['status'] !== 'completed') {
                throw new Exception("OCR processing failed: " . ($ocr_result['error'] ?? 'Unknown error'));
            }
            
            $document_text = $ocr_result['combined_text'] ?? '';
            if (empty($document_text)) {
                throw new Exception("No text extracted from document");
            }
            
            // Step 2: Document Classification
            $classification_result = $this->classifyDocument($document_id, $document_text);
            $results['classification'] = $classification_result;
            
            $document_type_detected = $classification_result['classification']['document_type'] ?? 'other';
            
            // Step 3: Specialized Analysis based on document type
            if (in_array($document_type_detected, ['medical_record', 'bill_invoice'])) {
                // Medical analysis for medical documents
                $medical_result = $this->extractMedicalInfo($document_id, $document_text);
                $results['medical_analysis'] = $medical_result;
            }
            
            if (in_array($document_type_detected, ['legal_document', 'correspondence', 'police_report'])) {
                // Legal analysis for legal documents
                $case_context = $intake_id ? ['intake_id' => $intake_id] : null;
                $legal_result = $this->analyzeLegalDocument($document_id, $document_text, $case_context);
                $results['legal_analysis'] = $legal_result;
            }
            
            // Step 4: Overall document summary
            $results['summary'] = [
                'document_id' => $document_id,
                'document_type' => $document_type_detected,
                'confidence' => $classification_result['classification']['confidence_score'] ?? 0,
                'text_length' => strlen($document_text),
                'ocr_quality' => $ocr_result['quality_assessment']['overall'] ?? 'unknown',
                'analyses_performed' => array_keys($results),
                'processing_time' => array_sum([
                    $ocr_result['processing_time'] ?? 0,
                    $classification_result['processing_time'] ?? 0,
                    $results['medical_analysis']['processing_time'] ?? 0,
                    $results['legal_analysis']['processing_time'] ?? 0
                ])
            ];
            
            return [
                'status' => 'completed',
                'results' => $results
            ];
            
        } catch (Exception $e) {
            error_log("Complete document analysis failed: " . $e->getMessage());
            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'partial_results' => $results
            ];
        }
    }
    
    /**
     * Comprehensive case analysis
     * Analyzes all documents and provides case merit assessment
     */
    public function analyzeCaseComplete($intake_id, $documents = [], $case_context = []) {
        $analysis_results = [];
        $all_analyses = [];
        
        try {
            // Analyze each document
            foreach ($documents as $document) {
                $doc_id = $document['id'];
                $file_path = $document['file_path'];
                $doc_type = $document['type'] ?? 'standard';
                
                $doc_analysis = $this->analyzeDocumentComplete($doc_id, $file_path, $intake_id, $doc_type);
                $analysis_results[$doc_id] = $doc_analysis;
                
                if ($doc_analysis['status'] === 'completed') {
                    $all_analyses[] = $doc_analysis['results'];
                }
            }
            
            // Prepare case data for merit analysis
            $case_data = [
                'intake_id' => $intake_id,
                'document_analyses' => $all_analyses,
                'case_context' => $case_context,
                'documents_processed' => count($documents),
                'successful_analyses' => count($all_analyses)
            ];
            
            // Perform case merit analysis
            $merit_analysis = $this->analyzeCaseMerit($intake_id, $case_data);
            
            return [
                'status' => 'completed',
                'intake_id' => $intake_id,
                'document_analyses' => $analysis_results,
                'case_merit_analysis' => $merit_analysis,
                'summary' => [
                    'total_documents' => count($documents),
                    'successful_analyses' => count($all_analyses),
                    'overall_recommendation' => $merit_analysis['recommendation']['recommendation'] ?? 'unknown',
                    'merit_score' => $merit_analysis['overall_score'] ?? 0,
                    'estimated_value' => $merit_analysis['value_estimate']['likely_estimate'] ?? 0
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Complete case analysis failed: " . $e->getMessage());
            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'partial_results' => $analysis_results
            ];
        }
    }
}

// Global AI service client instance
$ai_service_client = new AIServiceClient();

/**
 * Helper function to get AI service client
 */
function getAIServiceClient() {
    global $ai_service_client;
    return $ai_service_client;
}

/**
 * Helper function to safely call AI services with error handling
 */
function callAIService($method, ...$args) {
    try {
        $client = getAIServiceClient();
        
        if (!method_exists($client, $method)) {
            throw new Exception("AI service method '$method' not found");
        }
        
        return call_user_func_array([$client, $method], $args);
        
    } catch (Exception $e) {
        error_log("AI service call failed: {$method} - " . $e->getMessage());
        return [
            'status' => 'error',
            'error' => $e->getMessage(),
            'method' => $method
        ];
    }
}

/**
 * Check if AI services are available
 */
function isAIServiceAvailable() {
    $client = getAIServiceClient();
    $health = $client->healthCheck();
    return $health !== false && isset($health['status']) && $health['status'] === 'healthy';
}

/**
 * Format AI analysis results for display
 */
function formatAIResults($results, $type = 'general') {
    if (!is_array($results) || $results['status'] !== 'completed') {
        return '<div class="alert alert-warning">AI analysis not available</div>';
    }
    
    $html = '<div class="ai-analysis-results">';
    
    switch ($type) {
        case 'document_classification':
            $classification = $results['classification'] ?? [];
            $doc_type = $classification['document_type'] ?? 'unknown';
            $confidence = round(($classification['confidence_score'] ?? 0) * 100, 1);
            
            $html .= '<div class="classification-result">';
            $html .= '<h6>Document Classification</h6>';
            $html .= '<p><strong>Type:</strong> ' . ucwords(str_replace('_', ' ', $doc_type)) . '</p>';
            $html .= '<p><strong>Confidence:</strong> ' . $confidence . '%</p>';
            $html .= '</div>';
            break;
            
        case 'case_merit':
            $merit = $results['recommendation'] ?? [];
            $score = $results['overall_score'] ?? 0;
            $recommendation = $merit['recommendation'] ?? 'unknown';
            $value = $results['value_estimate']['likely_estimate'] ?? 0;
            
            $html .= '<div class="merit-analysis-result">';
            $html .= '<h6>Case Merit Analysis</h6>';
            $html .= '<p><strong>Overall Score:</strong> ' . round($score, 1) . '/100</p>';
            $html .= '<p><strong>Recommendation:</strong> ' . ucfirst($recommendation) . '</p>';
            if ($value > 0) {
                $html .= '<p><strong>Estimated Value:</strong> $' . number_format($value) . '</p>';
            }
            $html .= '</div>';
            break;
            
        default:
            $html .= '<div class="general-ai-result">';
            $html .= '<p><strong>Status:</strong> ' . ucfirst($results['status']) . '</p>';
            if (isset($results['processing_time'])) {
                $html .= '<p><strong>Processing Time:</strong> ' . round($results['processing_time'], 2) . 's</p>';
            }
            $html .= '</div>';
    }
    
    $html .= '</div>';
    return $html;
}
?>