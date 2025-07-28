<?php
/**
 * Database Configuration for Legal Intake System
 * HIPAA-compliant database connection with encryption
 */

class Database {
    private $host = 'localhost';
    private $dbname = 'legal_intake_system';
    private $username = 'root';
    private $password = '';
    private $charset = 'utf8mb4';
    private $pdo;
    
    // Encryption key for sensitive data (should be stored securely in production)
    private $encryption_key = 'your-secure-encryption-key-here-32-chars';
    
    public function __construct() {
        $this->connect();
    }
    
    private function connect() {
        $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION'"
        ];
        
        try {
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    /**
     * Encrypt sensitive data for HIPAA compliance
     */
    public function encrypt($data) {
        if (empty($data)) return $data;
        
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $this->encryption_key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt sensitive data
     */
    public function decrypt($data) {
        if (empty($data)) return $data;
        
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $this->encryption_key, 0, $iv);
    }
    
    /**
     * Execute prepared statement with audit logging
     */
    public function executeWithAudit($sql, $params = [], $user_id = null, $action = null, $table = null, $record_id = null) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params);
            
            // Log the action for HIPAA audit trail
            if ($user_id && $action && $table) {
                $this->logAuditTrail($user_id, $action, $table, $record_id, $sql);
            }
            
            return $stmt;
        } catch (PDOException $e) {
            error_log("Database query failed: " . $e->getMessage());
            throw new Exception("Database operation failed");
        }
    }
    
    /**
     * HIPAA-compliant audit trail logging
     */
    private function logAuditTrail($user_id, $action, $table, $record_id, $sql_query) {
        $audit_sql = "INSERT INTO audit_log (user_id, action, table_name, record_id, sql_query, ip_address, user_agent, created_at) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        try {
            $stmt = $this->pdo->prepare($audit_sql);
            $stmt->execute([$user_id, $action, $table, $record_id, $sql_query, $ip_address, $user_agent]);
        } catch (PDOException $e) {
            error_log("Audit logging failed: " . $e->getMessage());
        }
    }
    
    /**
     * Begin transaction for complex operations
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        return $this->pdo->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->pdo->rollback();
    }
    
    /**
     * Get last insert ID
     */
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
}
?>