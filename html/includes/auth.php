<?php
/**
 * Authentication and Authorization System
 * HIPAA-compliant user management with audit logging
 */

// Prevent direct access
if (!defined('LEGAL_INTAKE_SYSTEM')) {
    die('Direct access not permitted');
}

class AuthSystem {
    private $db;
    
    public function __construct() {
        $this->db = new Database();
    }
    
    /**
     * Authenticate user login
     */
    public function login($username, $password, $remember_me = false) {
        try {
            // Check for account lockout
            if ($this->isAccountLocked($username)) {
                $this->logAuditEvent(null, AUDIT_LOGIN, 'users', null, 'Login attempt on locked account: ' . $username);
                return ['success' => false, 'message' => 'Account is temporarily locked due to multiple failed attempts'];
            }
            
            // Get user by username or email
            $sql = "SELECT id, firm_id, username, email, password_hash, first_name, last_name, role, 
                           is_active, failed_login_attempts, mfa_enabled 
                    FROM users 
                    WHERE (username = ? OR email = ?) AND is_active = 1";
            
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $this->incrementFailedAttempts($username);
                $this->logAuditEvent(null, AUDIT_LOGIN, 'users', null, 'Login attempt with invalid username: ' . $username);
                return ['success' => false, 'message' => 'Invalid username or password'];
            }
            
            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                $this->incrementFailedAttempts($username);
                $this->logAuditEvent($user['id'], AUDIT_LOGIN, 'users', $user['id'], 'Failed password attempt');
                return ['success' => false, 'message' => 'Invalid username or password'];
            }
            
            // Reset failed attempts on successful login
            $this->resetFailedAttempts($user['id']);
            
            // TODO: Handle MFA if enabled
            if ($user['mfa_enabled']) {
                // For POC, we'll skip MFA implementation
                // return ['success' => false, 'mfa_required' => true, 'user_id' => $user['id']];
            }
            
            // Create session
            $session_id = $this->createSession($user['id'], $remember_me);
            
            // Update last login
            $this->updateLastLogin($user['id']);
            
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['firm_id'] = $user['firm_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['session_id'] = $session_id;
            $_SESSION['last_activity'] = time();
            
            // Log successful login
            $this->logAuditEvent($user['id'], AUDIT_LOGIN, 'users', $user['id'], 'Successful login');
            
            return ['success' => true, 'user' => $user];
            
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Login system error'];
        }
    }
    
    /**
     * Logout user and clean up session
     */
    public function logout() {
        $user_id = $_SESSION['user_id'] ?? null;
        $session_id = $_SESSION['session_id'] ?? null;
        
        if ($user_id) {
            $this->logAuditEvent($user_id, AUDIT_LOGOUT, 'users', $user_id, 'User logout');
        }
        
        // Invalidate session in database
        if ($session_id) {
            $sql = "UPDATE user_sessions SET is_active = 0 WHERE session_id = ?";
            $this->db->getConnection()->prepare($sql)->execute([$session_id]);
        }
        
        // Clear session data
        session_unset();
        session_destroy();
        
        // Start new session for post-logout
        session_start();
        session_regenerate_id(true);
    }
    
    /**
     * Check if user is authenticated and session is valid
     */
    public function isAuthenticated() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_id'])) {
            return false;
        }
        
        // Check session timeout
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
            $this->logout();
            return false;
        }
        
        // Verify session in database
        $sql = "SELECT id FROM user_sessions 
                WHERE session_id = ? AND user_id = ? AND is_active = 1 AND expires_at > NOW()";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$_SESSION['session_id'], $_SESSION['user_id']]);
        
        if (!$stmt->fetch()) {
            $this->logout();
            return false;
        }
        
        // Update last activity
        $_SESSION['last_activity'] = time();
        
        return true;
    }
    
    /**
     * Check if user has required permission
     */
    public function hasPermission($required_role, $user_role = null) {
        if (!$user_role) {
            $user_role = $_SESSION['user_role'] ?? null;
        }
        
        return check_user_permission($required_role, $user_role);
    }
    
    /**
     * Require authentication or redirect to login
     */
    public function requireAuth($required_role = null) {
        if (!$this->isAuthenticated()) {
            header('Location: ' . APP_URL . '/html/pages/login.php');
            exit();
        }
        
        if ($required_role && !$this->hasPermission($required_role)) {
            $this->logAuditEvent($_SESSION['user_id'], 'access_denied', 'authorization', null, 
                               'Access denied for role: ' . $required_role);
            header('Location: ' . APP_URL . '/html/pages/access_denied.php');
            exit();
        }
    }
    
    /**
     * Create new session record
     */
    private function createSession($user_id, $remember_me = false) {
        $session_id = bin2hex(random_bytes(32));
        $expires_at = $remember_me ? 
            date('Y-m-d H:i:s', strtotime('+30 days')) : 
            date('Y-m-d H:i:s', strtotime('+' . SESSION_TIMEOUT . ' seconds'));
        
        $sql = "INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, expires_at) 
                VALUES (?, ?, ?, ?, ?)";
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $this->db->getConnection()->prepare($sql)->execute([
            $user_id, $session_id, $ip_address, $user_agent, $expires_at
        ]);
        
        return $session_id;
    }
    
    /**
     * Update last login timestamp
     */
    private function updateLastLogin($user_id) {
        $sql = "UPDATE users SET last_login_at = NOW() WHERE id = ?";
        $this->db->getConnection()->prepare($sql)->execute([$user_id]);
    }
    
    /**
     * Check if account is locked due to failed attempts
     */
    private function isAccountLocked($username) {
        $sql = "SELECT failed_login_attempts, locked_until FROM users 
                WHERE (username = ? OR email = ?) AND is_active = 1";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if (!$user) return false;
        
        if ($user['failed_login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
            if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Increment failed login attempts
     */
    private function incrementFailedAttempts($username) {
        $sql = "UPDATE users 
                SET failed_login_attempts = failed_login_attempts + 1,
                    locked_until = CASE 
                        WHEN failed_login_attempts + 1 >= ? THEN DATE_ADD(NOW(), INTERVAL ? SECOND)
                        ELSE locked_until 
                    END
                WHERE (username = ? OR email = ?) AND is_active = 1";
        
        $this->db->getConnection()->prepare($sql)->execute([
            MAX_LOGIN_ATTEMPTS, LOGIN_LOCKOUT_TIME, $username, $username
        ]);
    }
    
    /**
     * Reset failed login attempts
     */
    private function resetFailedAttempts($user_id) {
        $sql = "UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?";
        $this->db->getConnection()->prepare($sql)->execute([$user_id]);
    }
    
    /**
     * Log audit event for HIPAA compliance
     */
    private function logAuditEvent($user_id, $action, $table_name, $record_id, $description) {
        $sql = "INSERT INTO audit_log (user_id, action, table_name, record_id, ip_address, user_agent, session_id, sql_query) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $session_id = $_SESSION['session_id'] ?? null;
        
        try {
            $this->db->getConnection()->prepare($sql)->execute([
                $user_id, $action, $table_name, $record_id, $ip_address, $user_agent, $session_id, $description
            ]);
        } catch (Exception $e) {
            error_log("Audit logging failed: " . $e->getMessage());
        }
    }
    
    /**
     * Get current user information
     */
    public function getCurrentUser() {
        if (!$this->isAuthenticated()) {
            return null;
        }
        
        $sql = "SELECT id, firm_id, username, email, first_name, last_name, role, last_login_at 
                FROM users WHERE id = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$_SESSION['user_id']]);
        
        return $stmt->fetch();
    }
    
    /**
     * Change user password
     */
    public function changePassword($current_password, $new_password) {
        if (!$this->isAuthenticated()) {
            return ['success' => false, 'message' => 'Not authenticated'];
        }
        
        $user = $this->getCurrentUser();
        if (!$user) {
            return ['success' => false, 'message' => 'User not found'];
        }
        
        // Get current password hash
        $sql = "SELECT password_hash FROM users WHERE id = ?";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([$user['id']]);
        $result = $stmt->fetch();
        
        // Verify current password
        if (!password_verify($current_password, $result['password_hash'])) {
            $this->logAuditEvent($user['id'], 'password_change_failed', 'users', $user['id'], 'Invalid current password');
            return ['success' => false, 'message' => 'Current password is incorrect'];
        }
        
        // Validate new password strength
        if (strlen($new_password) < PASSWORD_MIN_LENGTH) {
            return ['success' => false, 'message' => 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters'];
        }
        
        // Hash new password
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update password
        $sql = "UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?";
        $this->db->getConnection()->prepare($sql)->execute([$new_hash, $user['id']]);
        
        $this->logAuditEvent($user['id'], 'password_changed', 'users', $user['id'], 'Password successfully changed');
        
        return ['success' => true, 'message' => 'Password changed successfully'];
    }
    
    /**
     * Generate CSRF token for forms
     */
    public function generateCSRFToken() {
        return generate_csrf_token();
    }
    
    /**
     * Verify CSRF token
     */
    public function verifyCSRFToken($token) {
        return verify_csrf_token($token);
    }
}
?>