<?php
/**
 * Access Denied page for unauthorized access attempts
 */

define('LEGAL_INTAKE_SYSTEM', true);
session_start();

require_once '../config/config.php';
require_once '../includes/auth.php';

$auth = new AuthSystem();

// If not authenticated, redirect to login
if (!$auth->isAuthenticated()) {
    header('Location: login.php');
    exit();
}

$user = $auth->getCurrentUser();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container mt-5" id="access-denied-container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card border-danger" id="access-denied-card">
                    <div class="card-body text-center p-5">
                        <div class="mb-4" id="access-denied-icon">
                            <i class="bi bi-shield-exclamation text-danger" style="font-size: 4rem;"></i>
                        </div>
                        
                        <h2 class="text-danger mb-3" id="access-denied-title">Access Denied</h2>
                        
                        <p class="text-muted mb-4" id="access-denied-message">
                            You don't have permission to access this resource. Your access level 
                            (<strong><?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?></strong>) 
                            does not allow this action.
                        </p>
                        
                        <div class="d-grid gap-2" id="access-denied-actions">
                            <a href="dashboard.php" class="btn btn-primary" id="back-to-dashboard">
                                <i class="bi bi-house me-2"></i>
                                Return to Dashboard
                            </a>
                            
                            <a href="javascript:history.back()" class="btn btn-outline-secondary" id="go-back">
                                <i class="bi bi-arrow-left me-2"></i>
                                Go Back
                            </a>
                        </div>
                        
                        <hr class="my-4">
                        
                        <p class="small text-muted mb-0" id="security-notice">
                            <i class="bi bi-info-circle me-1"></i>
                            This incident has been logged for security purposes.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>