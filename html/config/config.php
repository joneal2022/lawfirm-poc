<?php
/**
 * Main Configuration File for Legal Intake System
 * Security and compliance settings
 */

// Prevent direct access
if (!defined('LEGAL_INTAKE_SYSTEM')) {
    die('Direct access not permitted');
}

// Error reporting (disable in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Security settings
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 0); // Set to 1 for HTTPS
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');

// Session configuration for HIPAA compliance
ini_set('session.gc_maxlifetime', 1800); // 30 minutes
ini_set('session.cookie_lifetime', 1800);

// Application settings
define('APP_NAME', 'Legal Intake System');
define('APP_VERSION', '1.0.0-POC');
define('APP_URL', 'http://localhost');

// Security settings
define('CSRF_TOKEN_NAME', 'csrf_token');
define('PASSWORD_MIN_LENGTH', 12);
define('SESSION_TIMEOUT', 1800); // 30 minutes
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// File upload settings
define('MAX_FILE_SIZE', 52428800); // 50MB
define('ALLOWED_FILE_TYPES', ['pdf', 'jpg', 'jpeg', 'png', 'tiff', 'doc', 'docx']);
define('UPLOAD_PATH', dirname(__FILE__) . '/../assets/uploads/');

// OCR settings
define('OCR_QUEUE_ENABLED', true);
define('OCR_BATCH_SIZE', 10);
define('OCR_TIMEOUT', 300); // 5 minutes

// User roles
define('ROLE_INTAKE_SPECIALIST', 'intake_specialist');
define('ROLE_PARALEGAL', 'paralegal');
define('ROLE_ATTORNEY', 'attorney');
define('ROLE_MANAGING_PARTNER', 'managing_partner');
define('ROLE_FIRM_ADMIN', 'firm_admin');
define('ROLE_SYSTEM_ADMIN', 'system_admin');

// Intake status constants
define('STATUS_NEW_INTAKE', 'new_intake');
define('STATUS_DOCUMENTS_PENDING', 'documents_pending');
define('STATUS_UNDER_REVIEW', 'under_review');
define('STATUS_ADDITIONAL_INFO_NEEDED', 'additional_info_needed');
define('STATUS_ATTORNEY_REVIEW', 'attorney_review');
define('STATUS_ACCEPTED', 'accepted');
define('STATUS_DECLINED', 'declined');

// Document types
define('DOC_TYPE_MEDICAL_RECORD', 'medical_record');
define('DOC_TYPE_POLICE_REPORT', 'police_report');
define('DOC_TYPE_INSURANCE_DOC', 'insurance_document');
define('DOC_TYPE_EMPLOYMENT_RECORD', 'employment_record');
define('DOC_TYPE_CORRESPONDENCE', 'correspondence');
define('DOC_TYPE_BILL_INVOICE', 'bill_invoice');
define('DOC_TYPE_LEGAL_DOCUMENT', 'legal_document');
define('DOC_TYPE_OTHER', 'other');

// Priority levels
define('PRIORITY_LOW', 'low');
define('PRIORITY_NORMAL', 'normal');
define('PRIORITY_HIGH', 'high');
define('PRIORITY_URGENT', 'urgent');

// Audit actions
define('AUDIT_LOGIN', 'login');
define('AUDIT_LOGOUT', 'logout');
define('AUDIT_CREATE', 'create');
define('AUDIT_READ', 'read');
define('AUDIT_UPDATE', 'update');
define('AUDIT_DELETE', 'delete');
define('AUDIT_EXPORT', 'export');
define('AUDIT_PRINT', 'print');

// Helper functions
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

function generate_csrf_token() {
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function verify_csrf_token($token) {
    return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

function format_currency($amount) {
    return '$' . number_format($amount, 2);
}

function format_date($date, $format = 'M j, Y') {
    if (empty($date)) return '';
    return date($format, strtotime($date));
}

function get_status_badge($status) {
    $badges = [
        STATUS_NEW_INTAKE => '<span class="badge bg-secondary">New Intake</span>',
        STATUS_DOCUMENTS_PENDING => '<span class="badge bg-info">Documents Pending</span>',
        STATUS_UNDER_REVIEW => '<span class="badge bg-warning">Under Review</span>',
        STATUS_ADDITIONAL_INFO_NEEDED => '<span class="badge bg-warning">Info Needed</span>',
        STATUS_ATTORNEY_REVIEW => '<span class="badge bg-primary">Attorney Review</span>',
        STATUS_ACCEPTED => '<span class="badge bg-success">Accepted</span>',
        STATUS_DECLINED => '<span class="badge bg-danger">Declined</span>'
    ];
    
    return $badges[$status] ?? '<span class="badge bg-secondary">Unknown</span>';
}

function get_priority_badge($priority) {
    $badges = [
        PRIORITY_LOW => '<span class="badge bg-light text-dark">Low</span>',
        PRIORITY_NORMAL => '<span class="badge bg-secondary">Normal</span>',
        PRIORITY_HIGH => '<span class="badge bg-warning">High</span>',
        PRIORITY_URGENT => '<span class="badge bg-danger">Urgent</span>'
    ];
    
    return $badges[$priority] ?? '<span class="badge bg-secondary">Normal</span>';
}

function check_user_permission($required_role, $user_role) {
    $role_hierarchy = [
        ROLE_INTAKE_SPECIALIST => 1,
        ROLE_PARALEGAL => 2,
        ROLE_ATTORNEY => 3,
        ROLE_MANAGING_PARTNER => 4,
        ROLE_FIRM_ADMIN => 5,
        ROLE_SYSTEM_ADMIN => 6
    ];
    
    return isset($role_hierarchy[$user_role]) && 
           isset($role_hierarchy[$required_role]) && 
           $role_hierarchy[$user_role] >= $role_hierarchy[$required_role];
}

// Auto-load database connection
require_once __DIR__ . '/database.php';
?>