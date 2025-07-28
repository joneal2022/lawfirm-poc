-- Initial Database Schema for Legal Intake System
-- HIPAA-compliant with encryption for sensitive data

-- Create database
CREATE DATABASE IF NOT EXISTS legal_intake_system;
USE legal_intake_system;

-- Law firm accounts table
CREATE TABLE firms (
    id INT PRIMARY KEY AUTO_INCREMENT,
    firm_name VARCHAR(255) NOT NULL,
    address TEXT,
    phone VARCHAR(20),
    email VARCHAR(255),
    website VARCHAR(255),
    license_number VARCHAR(100),
    practice_areas TEXT,
    billing_address TEXT,
    settings JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_firm_name (firm_name)
);

-- User accounts and authentication
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    firm_id INT NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    role ENUM('intake_specialist', 'paralegal', 'attorney', 'managing_partner', 'firm_admin', 'system_admin') NOT NULL,
    phone VARCHAR(20),
    is_active BOOLEAN DEFAULT TRUE,
    last_login_at TIMESTAMP NULL,
    password_changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    mfa_enabled BOOLEAN DEFAULT FALSE,
    mfa_secret VARCHAR(255),
    failed_login_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (firm_id) REFERENCES firms(id) ON DELETE CASCADE,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role)
);

-- Granular permission assignments
CREATE TABLE user_permissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    permission_name VARCHAR(100) NOT NULL,
    granted BOOLEAN DEFAULT TRUE,
    granted_by INT,
    granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_user_permission (user_id, permission_name),
    INDEX idx_permission_name (permission_name)
);

-- Secure session management
CREATE TABLE user_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    session_id VARCHAR(255) UNIQUE NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_session_id (session_id),
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at)
);

-- Client records (encrypted PII/PHI)
CREATE TABLE clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    firm_id INT NOT NULL,
    client_number VARCHAR(50) UNIQUE NOT NULL,
    first_name_encrypted TEXT, -- Encrypted
    last_name_encrypted TEXT, -- Encrypted
    email_encrypted TEXT, -- Encrypted
    phone_encrypted TEXT, -- Encrypted
    ssn_encrypted TEXT, -- Encrypted
    date_of_birth_encrypted TEXT, -- Encrypted
    address_encrypted TEXT, -- Encrypted
    emergency_contact_encrypted TEXT, -- Encrypted
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (firm_id) REFERENCES firms(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_client_number (client_number),
    INDEX idx_firm_id (firm_id)
);

-- Intake form submissions
CREATE TABLE intake_forms (
    id INT PRIMARY KEY AUTO_INCREMENT,
    firm_id INT NOT NULL,
    client_id INT,
    intake_number VARCHAR(50) UNIQUE NOT NULL,
    status ENUM('new_intake', 'documents_pending', 'under_review', 'additional_info_needed', 'attorney_review', 'accepted', 'declined') DEFAULT 'new_intake',
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    source VARCHAR(100), -- website, phone, referral, etc.
    incident_date DATE,
    incident_location TEXT,
    incident_description TEXT,
    injury_description TEXT,
    medical_treatment BOOLEAN DEFAULT FALSE,
    police_report BOOLEAN DEFAULT FALSE,
    insurance_claim BOOLEAN DEFAULT FALSE,
    employment_status VARCHAR(100),
    estimated_damages DECIMAL(12,2),
    statute_of_limitations DATE,
    assigned_to INT,
    notes TEXT,
    form_data JSON, -- Complete form responses
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (firm_id) REFERENCES firms(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_intake_number (intake_number),
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_created_at (created_at)
);

-- Intake workflow status tracking
CREATE TABLE intake_status_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    intake_id INT NOT NULL,
    old_status VARCHAR(50),
    new_status VARCHAR(50) NOT NULL,
    changed_by INT NOT NULL,
    reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (intake_id) REFERENCES intake_forms(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id),
    INDEX idx_intake_id (intake_id),
    INDEX idx_created_at (created_at)
);

-- Document metadata
CREATE TABLE documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    intake_id INT NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL,
    mime_type VARCHAR(100),
    document_type ENUM('medical_record', 'police_report', 'insurance_document', 'employment_record', 'correspondence', 'bill_invoice', 'legal_document', 'other') DEFAULT 'other',
    classification_confidence DECIMAL(5,2) DEFAULT 0.00,
    ocr_status ENUM('pending', 'processing', 'completed', 'failed', 'manual_review') DEFAULT 'pending',
    ocr_confidence DECIMAL(5,2) DEFAULT 0.00,
    page_count INT DEFAULT 1,
    uploaded_by INT NOT NULL,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (intake_id) REFERENCES intake_forms(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id),
    INDEX idx_intake_id (intake_id),
    INDEX idx_document_type (document_type),
    INDEX idx_ocr_status (ocr_status),
    INDEX idx_created_at (created_at)
);

-- OCR text and extracted data
CREATE TABLE document_content (
    id INT PRIMARY KEY AUTO_INCREMENT,
    document_id INT NOT NULL,
    page_number INT DEFAULT 1,
    ocr_text TEXT,
    corrected_text TEXT,
    extracted_data JSON, -- Structured data extraction
    medical_codes JSON, -- ICD, CPT codes
    financial_amounts JSON, -- Extracted monetary values
    dates_extracted JSON, -- Important dates found
    parties_mentioned JSON, -- Names, companies mentioned
    confidence_scores JSON, -- Per-field confidence
    reviewed_by INT,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_document_id (document_id),
    INDEX idx_page_number (page_number),
    FULLTEXT idx_ocr_text (ocr_text),
    FULLTEXT idx_corrected_text (corrected_text)
);

-- OCR processing queue
CREATE TABLE ocr_queue (
    id INT PRIMARY KEY AUTO_INCREMENT,
    document_id INT NOT NULL,
    priority INT DEFAULT 0,
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    status ENUM('queued', 'processing', 'completed', 'failed') DEFAULT 'queued',
    error_message TEXT,
    processing_started_at TIMESTAMP NULL,
    processing_completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_created_at (created_at)
);

-- AI analysis results
CREATE TABLE ai_analysis (
    id INT PRIMARY KEY AUTO_INCREMENT,
    intake_id INT NOT NULL,
    analysis_type ENUM('liability', 'damages', 'case_score', 'conflict_check') NOT NULL,
    analysis_data JSON,
    confidence_score DECIMAL(5,2),
    liability_score DECIMAL(5,2),
    damages_estimate DECIMAL(12,2),
    case_strength ENUM('very_weak', 'weak', 'moderate', 'strong', 'very_strong'),
    recommendations TEXT,
    analyzed_by_ai_model VARCHAR(100),
    reviewed_by INT,
    approved BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (intake_id) REFERENCES intake_forms(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_intake_id (intake_id),
    INDEX idx_analysis_type (analysis_type),
    INDEX idx_case_strength (case_strength)
);

-- Lead qualification scores
CREATE TABLE case_scores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    intake_id INT NOT NULL,
    liability_score DECIMAL(5,2) DEFAULT 0.00,
    damages_score DECIMAL(5,2) DEFAULT 0.00,
    collectibility_score DECIMAL(5,2) DEFAULT 0.00,
    complexity_score DECIMAL(5,2) DEFAULT 0.00,
    overall_score DECIMAL(5,2) DEFAULT 0.00,
    recommendation ENUM('accept', 'decline', 'refer', 'more_info_needed') DEFAULT 'more_info_needed',
    estimated_case_value DECIMAL(12,2),
    estimated_attorney_fees DECIMAL(12,2),
    success_probability DECIMAL(5,2),
    manual_override BOOLEAN DEFAULT FALSE,
    override_reason TEXT,
    scored_by INT,
    scored_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (intake_id) REFERENCES intake_forms(id) ON DELETE CASCADE,
    FOREIGN KEY (scored_by) REFERENCES users(id),
    INDEX idx_intake_id (intake_id),
    INDEX idx_recommendation (recommendation),
    INDEX idx_overall_score (overall_score)
);

-- All client communications
CREATE TABLE communication_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    intake_id INT NOT NULL,
    communication_type ENUM('email', 'phone', 'sms', 'letter', 'in_person', 'portal_message') NOT NULL,
    direction ENUM('inbound', 'outbound') NOT NULL,
    subject VARCHAR(255),
    content TEXT,
    from_user_id INT,
    to_email VARCHAR(255),
    to_phone VARCHAR(20),
    status ENUM('draft', 'sent', 'delivered', 'failed', 'read') DEFAULT 'draft',
    attachments JSON,
    metadata JSON, -- Email headers, call duration, etc.
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL,
    FOREIGN KEY (intake_id) REFERENCES intake_forms(id) ON DELETE CASCADE,
    FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_intake_id (intake_id),
    INDEX idx_communication_type (communication_type),
    INDEX idx_direction (direction),
    INDEX idx_created_at (created_at)
);

-- HIPAA-compliant audit trail
CREATE TABLE audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action ENUM('login', 'logout', 'create', 'read', 'update', 'delete', 'export', 'print', 'access_denied') NOT NULL,
    table_name VARCHAR(100),
    record_id INT,
    old_values JSON,
    new_values JSON,
    sql_query TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    session_id VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_table_name (table_name),
    INDEX idx_created_at (created_at)
);

-- Third-party integrations
CREATE TABLE integrations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    firm_id INT NOT NULL,
    integration_type ENUM('case_management', 'email', 'sms', 'document_signing', 'calendar', 'accounting') NOT NULL,
    provider_name VARCHAR(100) NOT NULL,
    api_credentials JSON, -- Encrypted API keys
    settings JSON,
    is_active BOOLEAN DEFAULT TRUE,
    last_sync_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (firm_id) REFERENCES firms(id) ON DELETE CASCADE,
    INDEX idx_firm_id (firm_id),
    INDEX idx_integration_type (integration_type)
);

-- Document and email templates
CREATE TABLE templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    firm_id INT NOT NULL,
    template_type ENUM('email', 'document', 'form', 'letter') NOT NULL,
    name VARCHAR(255) NOT NULL,
    subject VARCHAR(255),
    content TEXT,
    variables JSON, -- Available template variables
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (firm_id) REFERENCES firms(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id),
    INDEX idx_firm_id (firm_id),
    INDEX idx_template_type (template_type)
);

-- Subscription and usage tracking
CREATE TABLE billing (
    id INT PRIMARY KEY AUTO_INCREMENT,
    firm_id INT NOT NULL,
    subscription_plan VARCHAR(100),
    billing_cycle ENUM('monthly', 'yearly') DEFAULT 'monthly',
    price DECIMAL(10,2),
    intake_limit INT DEFAULT 100,
    storage_limit BIGINT DEFAULT 10737418240, -- 10GB in bytes
    user_limit INT DEFAULT 5,
    current_intakes INT DEFAULT 0,
    current_storage BIGINT DEFAULT 0,
    current_users INT DEFAULT 0,
    billing_date DATE,
    status ENUM('active', 'suspended', 'cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (firm_id) REFERENCES firms(id) ON DELETE CASCADE,
    INDEX idx_firm_id (firm_id),
    INDEX idx_status (status)
);

-- Insert default firm for POC
INSERT INTO firms (firm_name, address, phone, email, practice_areas) 
VALUES ('Demo Law Firm', '123 Legal Street, Law City, LC 12345', '555-LAW-FIRM', 'contact@demolawfirm.com', 'Personal Injury, Auto Accidents, Medical Malpractice');

-- Insert default system admin user (password: AdminPassword123!)
INSERT INTO users (firm_id, username, email, password_hash, first_name, last_name, role) 
VALUES (1, 'admin', 'admin@demolawfirm.com', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/UnlvQwlEXu2OUOuLy', 'System', 'Administrator', 'system_admin');

-- Insert sample roles for testing
INSERT INTO users (firm_id, username, email, password_hash, first_name, last_name, role) VALUES
(1, 'intake1', 'intake@demolawfirm.com', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/UnlvQwlEXu2OUOuLy', 'Sarah', 'Johnson', 'intake_specialist'),
(1, 'paralegal1', 'paralegal@demolawfirm.com', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/UnlvQwlEXu2OUOuLy', 'Mike', 'Davis', 'paralegal'),
(1, 'attorney1', 'attorney@demolawfirm.com', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/UnlvQwlEXu2OUOuLy', 'Jennifer', 'Smith', 'attorney'),
(1, 'partner1', 'partner@demolawfirm.com', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/UnlvQwlEXu2OUOuLy', 'Robert', 'Wilson', 'managing_partner');

-- Insert default billing record
INSERT INTO billing (firm_id, subscription_plan, billing_cycle, price, intake_limit, user_limit) 
VALUES (1, 'Professional', 'monthly', 299.00, 500, 10);

-- Set up automated cleanup for old sessions
DELIMITER $$
CREATE EVENT IF NOT EXISTS cleanup_expired_sessions
ON SCHEDULE EVERY 1 HOUR
DO
BEGIN
    DELETE FROM user_sessions WHERE expires_at < NOW();
END$$
DELIMITER ;

-- Set up automated cleanup for old audit logs (keep 2 years)
DELIMITER $$
CREATE EVENT IF NOT EXISTS cleanup_old_audit_logs
ON SCHEDULE EVERY 1 DAY
DO
BEGIN
    DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 YEAR);
END$$
DELIMITER ;