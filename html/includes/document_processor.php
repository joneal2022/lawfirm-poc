<?php
/**
 * Document Processing System
 * Handles file uploads, OCR processing, and document classification
 */

// Prevent direct access
if (!defined('LEGAL_INTAKE_SYSTEM')) {
    die('Direct access not permitted');
}

class DocumentProcessor {
    private $db;
    private $upload_path;
    private $allowed_types;
    private $max_file_size;
    
    public function __construct() {
        $this->db = new Database();
        $this->upload_path = UPLOAD_PATH;
        $this->allowed_types = ALLOWED_FILE_TYPES;
        $this->max_file_size = MAX_FILE_SIZE;
        
        // Create upload directory if it doesn't exist
        if (!is_dir($this->upload_path)) {
            mkdir($this->upload_path, 0755, true);
        }
    }
    
    /**
     * Upload and process a document
     */
    public function uploadDocument($file, $intake_id, $user_id, $document_type = DOC_TYPE_OTHER) {
        try {
            // Validate file
            $validation = $this->validateFile($file);
            if (!$validation['valid']) {
                return ['success' => false, 'message' => $validation['message']];
            }
            
            // Generate secure filename
            $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $stored_filename = uniqid('doc_', true) . '.' . $file_extension;
            $file_path = $this->upload_path . $stored_filename;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $file_path)) {
                return ['success' => false, 'message' => 'Failed to save uploaded file'];
            }
            
            // Create document record
            $document_id = $this->createDocumentRecord(
                $intake_id,
                $file['name'],
                $stored_filename,
                $file_path,
                $file['size'],
                $file['type'],
                $document_type,
                $user_id
            );
            
            if (!$document_id) {
                // Clean up file if database insert failed
                unlink($file_path);
                return ['success' => false, 'message' => 'Failed to create document record'];
            }
            
            // Add to OCR queue
            $this->addToOCRQueue($document_id);
            
            return [
                'success' => true,
                'document_id' => $document_id,
                'message' => 'Document uploaded successfully and added to processing queue'
            ];
            
        } catch (Exception $e) {
            error_log("Document upload error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Upload system error'];
        }
    }
    
    /**
     * Validate uploaded file
     */
    private function validateFile($file) {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => 'File too large (exceeds server limit)',
                UPLOAD_ERR_FORM_SIZE => 'File too large (exceeds form limit)',
                UPLOAD_ERR_PARTIAL => 'File upload incomplete',
                UPLOAD_ERR_NO_FILE => 'No file uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Temporary directory missing',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
            ];
            
            $message = $error_messages[$file['error']] ?? 'Unknown upload error';
            return ['valid' => false, 'message' => $message];
        }
        
        // Check file size
        if ($file['size'] > $this->max_file_size) {
            return ['valid' => false, 'message' => 'File size exceeds maximum allowed size (' . $this->formatFileSize($this->max_file_size) . ')'];
        }
        
        // Check file type
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($file_extension, $this->allowed_types)) {
            return ['valid' => false, 'message' => 'File type not allowed. Allowed types: ' . implode(', ', $this->allowed_types)];
        }
        
        // Check for malicious content (basic check)
        if ($this->isMaliciousFile($file)) {
            return ['valid' => false, 'message' => 'File appears to contain malicious content'];
        }
        
        return ['valid' => true];
    }
    
    /**
     * Basic malicious file detection
     */
    private function isMaliciousFile($file) {
        // Check for executable extensions disguised as documents
        $dangerous_extensions = ['exe', 'bat', 'com', 'scr', 'pif', 'cmd', 'js', 'jar'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (in_array($file_extension, $dangerous_extensions)) {
            return true;
        }
        
        // Check file signature (magic numbers) for common document types
        $file_content = file_get_contents($file['tmp_name'], false, null, 0, 10);
        
        // PDF signature
        if ($file_extension === 'pdf' && strpos($file_content, '%PDF') !== 0) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Create document record in database
     */
    private function createDocumentRecord($intake_id, $original_filename, $stored_filename, $file_path, $file_size, $mime_type, $document_type, $user_id) {
        $sql = "INSERT INTO documents (intake_id, original_filename, stored_filename, file_path, file_size, 
                mime_type, document_type, uploaded_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([
                $intake_id,
                $original_filename,
                $stored_filename,
                $file_path,
                $file_size,
                $mime_type,
                $document_type,
                $user_id
            ]);
            
            return $this->db->getConnection()->lastInsertId();
        } catch (Exception $e) {
            error_log("Document record creation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Add document to OCR processing queue
     */
    private function addToOCRQueue($document_id, $priority = 0) {
        $sql = "INSERT INTO ocr_queue (document_id, priority, status, created_at) 
                VALUES (?, ?, 'queued', NOW())";
        
        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$document_id, $priority]);
            return true;
        } catch (Exception $e) {
            error_log("OCR queue error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Process OCR for a document (basic implementation)
     */
    public function processOCR($document_id) {
        try {
            // Update OCR status to processing
            $this->updateOCRStatus($document_id, 'processing');
            
            // Get document information
            $document = $this->getDocumentById($document_id);
            if (!$document) {
                throw new Exception("Document not found");
            }
            
            // For POC: Simulate OCR processing
            // In production, this would use Tesseract or another OCR engine
            $ocr_text = $this->simulateOCR($document);
            
            // Extract structured data from OCR text
            $extracted_data = $this->extractStructuredData($ocr_text);
            
            // Save OCR results
            $this->saveOCRResults($document_id, $ocr_text, $extracted_data);
            
            // Update document OCR status
            $this->updateDocumentOCRStatus($document_id, 'completed', 85.5);
            
            // Update OCR queue status
            $this->updateOCRStatus($document_id, 'completed');
            
            return ['success' => true, 'message' => 'OCR processing completed'];
            
        } catch (Exception $e) {
            error_log("OCR processing error: " . $e->getMessage());
            $this->updateOCRStatus($document_id, 'failed', $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Simulate OCR processing for POC
     */
    private function simulateOCR($document) {
        $file_extension = strtolower(pathinfo($document['original_filename'], PATHINFO_EXTENSION));
        
        // Simulate different OCR results based on document type
        switch ($file_extension) {
            case 'pdf':
                return $this->generateMedicalRecordText();
            default:
                return "This is simulated OCR text extracted from the document: " . $document['original_filename'] . 
                       "\n\nDate: " . date('m/d/Y') . 
                       "\nDocument contains text that would be extracted by OCR processing." .
                       "\nIn production, this would be actual text extracted from the uploaded document.";
        }
    }
    
    /**
     * Generate sample medical record text for simulation
     */
    private function generateMedicalRecordText() {
        return "MEDICAL RECORD\n\n" .
               "Patient: John Doe\n" .
               "DOB: 01/15/1980\n" .
               "Date of Service: " . date('m/d/Y') . "\n\n" .
               "CHIEF COMPLAINT: Back pain following motor vehicle accident\n\n" .
               "HISTORY OF PRESENT ILLNESS:\n" .
               "Patient presents with acute lower back pain that began immediately following " .
               "a motor vehicle accident on " . date('m/d/Y', strtotime('-7 days')) . ". " .
               "Pain is described as sharp and radiating down the left leg.\n\n" .
               "PHYSICAL EXAMINATION:\n" .
               "Patient appears uncomfortable. Range of motion limited due to pain.\n" .
               "Tenderness noted in lumbar region.\n\n" .
               "ASSESSMENT:\n" .
               "Acute lumbar strain\n" .
               "Rule out herniated disc\n\n" .
               "PLAN:\n" .
               "1. MRI lumbar spine\n" .
               "2. Physical therapy referral\n" .
               "3. Pain medication as needed\n" .
               "4. Follow up in 2 weeks\n\n" .
               "Dr. Sarah Smith, MD\n" .
               "License #12345";
    }
    
    /**
     * Extract structured data from OCR text
     */
    private function extractStructuredData($ocr_text) {
        $extracted = [
            'dates' => [],
            'amounts' => [],
            'medical_codes' => [],
            'parties' => [],
            'addresses' => []
        ];
        
        // Extract dates (simple regex patterns)
        preg_match_all('/\b\d{1,2}\/\d{1,2}\/\d{4}\b/', $ocr_text, $date_matches);
        $extracted['dates'] = array_unique($date_matches[0]);
        
        // Extract dollar amounts
        preg_match_all('/\$\d{1,3}(?:,\d{3})*(?:\.\d{2})?/', $ocr_text, $amount_matches);
        $extracted['amounts'] = array_unique($amount_matches[0]);
        
        // Extract names (basic pattern)
        preg_match_all('/(?:Dr\.|Mr\.|Mrs\.|Ms\.)\s+([A-Z][a-z]+\s+[A-Z][a-z]+)/', $ocr_text, $name_matches);
        $extracted['parties'] = array_unique($name_matches[1]);
        
        // Extract phone numbers
        preg_match_all('/\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', $ocr_text, $phone_matches);
        $extracted['phones'] = array_unique($phone_matches[0]);
        
        return $extracted;
    }
    
    /**
     * Save OCR results to database
     */
    private function saveOCRResults($document_id, $ocr_text, $extracted_data) {
        $sql = "INSERT INTO document_content (document_id, page_number, ocr_text, extracted_data, 
                confidence_scores, created_at) 
                VALUES (?, 1, ?, ?, ?, NOW())";
        
        $confidence_scores = [
            'overall' => 85.5,
            'text_quality' => 90.2,
            'extraction_accuracy' => 80.8
        ];
        
        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([
                $document_id,
                $ocr_text,
                json_encode($extracted_data),
                json_encode($confidence_scores)
            ]);
            return true;
        } catch (Exception $e) {
            error_log("OCR results save error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update OCR queue status
     */
    private function updateOCRStatus($document_id, $status, $error_message = null) {
        $sql = "UPDATE ocr_queue SET status = ?, error_message = ?, 
                processing_completed_at = IF(? = 'completed', NOW(), processing_completed_at),
                processing_started_at = IF(? = 'processing', NOW(), processing_started_at)
                WHERE document_id = ?";
        
        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$status, $error_message, $status, $status, $document_id]);
            return true;
        } catch (Exception $e) {
            error_log("OCR status update error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update document OCR status
     */
    private function updateDocumentOCRStatus($document_id, $status, $confidence = null) {
        $sql = "UPDATE documents SET ocr_status = ?, ocr_confidence = ?, processed_at = NOW() 
                WHERE id = ?";
        
        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$status, $confidence, $document_id]);
            return true;
        } catch (Exception $e) {
            error_log("Document OCR status update error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get document by ID
     */
    public function getDocumentById($document_id) {
        $sql = "SELECT * FROM documents WHERE id = ?";
        
        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$document_id]);
            return $stmt->fetch();
        } catch (Exception $e) {
            error_log("Document fetch error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get documents for an intake
     */
    public function getDocumentsByIntake($intake_id) {
        $sql = "SELECT d.*, dc.ocr_text, dc.extracted_data 
                FROM documents d 
                LEFT JOIN document_content dc ON d.id = dc.document_id 
                WHERE d.intake_id = ? 
                ORDER BY d.created_at DESC";
        
        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$intake_id]);
            return $stmt->fetchAll();
        } catch (Exception $e) {
            error_log("Documents fetch error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Classify document type based on content
     */
    public function classifyDocument($document_id) {
        $document = $this->getDocumentById($document_id);
        if (!$document) {
            return false;
        }
        
        // Get OCR content
        $sql = "SELECT ocr_text FROM document_content WHERE document_id = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$document_id]);
        $content = $stmt->fetch();
        
        if (!$content || empty($content['ocr_text'])) {
            return false;
        }
        
        $ocr_text = strtolower($content['ocr_text']);
        $classification = DOC_TYPE_OTHER;
        $confidence = 0.0;
        
        // Simple rule-based classification
        if (strpos($ocr_text, 'medical record') !== false || 
            strpos($ocr_text, 'patient') !== false ||
            strpos($ocr_text, 'diagnosis') !== false) {
            $classification = DOC_TYPE_MEDICAL_RECORD;
            $confidence = 0.85;
        } elseif (strpos($ocr_text, 'police report') !== false ||
                  strpos($ocr_text, 'incident report') !== false ||
                  strpos($ocr_text, 'officer') !== false) {
            $classification = DOC_TYPE_POLICE_REPORT;
            $confidence = 0.80;
        } elseif (strpos($ocr_text, 'insurance') !== false ||
                  strpos($ocr_text, 'claim') !== false ||
                  strpos($ocr_text, 'policy') !== false) {
            $classification = DOC_TYPE_INSURANCE_DOC;
            $confidence = 0.75;
        } elseif (strpos($ocr_text, 'invoice') !== false ||
                  strpos($ocr_text, 'bill') !== false ||
                  strpos($ocr_text, 'amount due') !== false) {
            $classification = DOC_TYPE_BILL_INVOICE;
            $confidence = 0.70;
        }
        
        // Update document classification
        $sql = "UPDATE documents SET document_type = ?, classification_confidence = ? WHERE id = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$classification, $confidence, $document_id]);
        
        return ['type' => $classification, 'confidence' => $confidence];
    }
    
    /**
     * Format file size for display
     */
    private function formatFileSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
?>