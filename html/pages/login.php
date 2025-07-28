<?php
/**
 * Login page for Legal Intake System
 * Professional authentication with security features
 */

define('LEGAL_INTAKE_SYSTEM', true);
session_start();

require_once '../config/config.php';
require_once '../includes/auth.php';

$auth = new AuthSystem();
$error_message = '';
$success_message = '';

// If already logged in, redirect to dashboard
if ($auth->isAuthenticated()) {
    header('Location: dashboard.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize_input($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']);
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Verify CSRF token
    if (!$auth->verifyCSRFToken($csrf_token)) {
        $error_message = 'Security token mismatch. Please try again.';
    } else if (empty($username) || empty($password)) {
        $error_message = 'Please enter both username and password.';
    } else {
        $result = $auth->login($username, $password, $remember_me);
        
        if ($result['success']) {
            header('Location: dashboard.php');
            exit();
        } else {
            $error_message = $result['message'];
        }
    }
}

// Handle password reset request
if (isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $success_message = 'Password reset instructions have been sent to your email.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            border: none;
        }
        .brand-logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
        }
        .security-badge {
            background: #e8f5e8;
            border: 1px solid #4caf50;
            border-radius: 8px;
            padding: 0.5rem;
            margin-bottom: 1rem;
        }
        .form-floating label {
            color: #6c757d;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 8px;
            padding: 0.75rem;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a67d8 0%, #6b46c1 100%);
            transform: translateY(-1px);
        }
        .footer-links {
            text-align: center;
            margin-top: 2rem;
        }
        .footer-links a {
            color: #6c757d;
            text-decoration: none;
            margin: 0 1rem;
        }
        .footer-links a:hover {
            color: #495057;
        }
    </style>
</head>
<body>
    <div class="container" id="login-container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="card login-card" id="login-card">
                    <div class="card-body p-5">
                        <!-- Brand Logo -->
                        <div class="brand-logo" id="brand-logo">
                            <i class="bi bi-shield-check text-white fs-2"></i>
                        </div>
                        
                        <!-- Title -->
                        <h2 class="text-center mb-1" id="login-title"><?php echo APP_NAME; ?></h2>
                        <p class="text-center text-muted mb-4" id="login-subtitle">Secure Legal Intake Platform</p>
                        
                        <!-- HIPAA Compliance Notice -->
                        <div class="security-badge" id="security-notice">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-shield-lock text-success me-2"></i>
                                <small class="text-success fw-bold">HIPAA Compliant & Secure</small>
                            </div>
                        </div>
                        
                        <!-- Success Message -->
                        <?php if ($success_message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert" id="success-alert">
                            <i class="bi bi-check-circle me-2"></i>
                            <?php echo htmlspecialchars($success_message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Error Message -->
                        <?php if ($error_message): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert" id="error-alert">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($error_message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Login Form -->
                        <form method="POST" action="" id="login-form">
                            <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCSRFToken(); ?>">
                            
                            <!-- Username Field -->
                            <div class="form-floating mb-3" id="username-group">
                                <input type="text" class="form-control" id="username" name="username" 
                                       placeholder="Username" required autofocus 
                                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                                <label for="username">
                                    <i class="bi bi-person me-2"></i>Username or Email
                                </label>
                            </div>
                            
                            <!-- Password Field -->
                            <div class="form-floating mb-3" id="password-group">
                                <input type="password" class="form-control" id="password" name="password" 
                                       placeholder="Password" required>
                                <label for="password">
                                    <i class="bi bi-lock me-2"></i>Password
                                </label>
                            </div>
                            
                            <!-- Remember Me & Forgot Password -->
                            <div class="d-flex justify-content-between align-items-center mb-3" id="login-options">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="remember_me" name="remember_me"
                                           <?php echo isset($_POST['remember_me']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="remember_me">
                                        Remember me
                                    </label>
                                </div>
                                <a href="forgot_password.php" class="text-decoration-none" id="forgot-password-link">
                                    Forgot password?
                                </a>
                            </div>
                            
                            <!-- Login Button -->
                            <button type="submit" class="btn btn-primary w-100 fw-bold" id="login-button">
                                <i class="bi bi-box-arrow-in-right me-2"></i>
                                Sign In Securely
                            </button>
                        </form>
                        
                        <!-- Session Info -->
                        <div class="mt-4 text-center" id="session-info">
                            <small class="text-muted">
                                <i class="bi bi-clock me-1"></i>
                                Your session will timeout after 30 minutes of inactivity
                            </small>
                        </div>
                        
                        <!-- Demo Credentials (POC Only) -->
                        <div class="mt-4 p-3 bg-light rounded" id="demo-credentials">
                            <h6 class="text-center mb-2">Demo Credentials (POC)</h6>
                            <div class="row text-center">
                                <div class="col-6 mb-2">
                                    <small class="d-block"><strong>Admin:</strong> admin</small>
                                    <small class="text-muted">AdminPassword123!</small>
                                </div>
                                <div class="col-6 mb-2">
                                    <small class="d-block"><strong>Attorney:</strong> attorney1</small>
                                    <small class="text-muted">AdminPassword123!</small>
                                </div>
                                <div class="col-6">
                                    <small class="d-block"><strong>Paralegal:</strong> paralegal1</small>
                                    <small class="text-muted">AdminPassword123!</small>
                                </div>
                                <div class="col-6">
                                    <small class="d-block"><strong>Intake:</strong> intake1</small>
                                    <small class="text-muted">AdminPassword123!</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Footer Links -->
                <div class="footer-links" id="footer-links">
                    <a href="#" id="privacy-link">Privacy Policy</a>
                    <a href="#" id="terms-link">Terms of Service</a>
                    <a href="#" id="support-link">Support</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-focus username field
        document.getElementById('username').focus();
        
        // Auto-dismiss alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        // Form validation
        document.getElementById('login-form').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                e.preventDefault();
                alert('Please enter both username and password.');
                return false;
            }
            
            // Show loading state
            const button = document.getElementById('login-button');
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Signing In...';
            button.disabled = true;
        });
        
        // Security notice tooltip
        const securityNotice = document.getElementById('security-notice');
        securityNotice.setAttribute('data-bs-toggle', 'tooltip');
        securityNotice.setAttribute('data-bs-placement', 'top');
        securityNotice.setAttribute('title', 'All data is encrypted and HIPAA compliant');
        
        // Initialize tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    </script>
</body>
</html>