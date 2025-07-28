<?php
/**
 * Header with role-based navigation for Legal Intake System
 */

// Prevent direct access
if (!defined('LEGAL_INTAKE_SYSTEM')) {
    die('Direct access not permitted');
}

// Ensure user is authenticated
$auth = new AuthSystem();
$auth->requireAuth();

$user = $auth->getCurrentUser();
$user_role = $_SESSION['user_role'];
$full_name = $_SESSION['full_name'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?><?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 250px;
            --navbar-height: 60px;
        }
        
        body {
            font-size: 0.875rem;
        }
        
        .navbar-brand {
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .sidebar {
            position: fixed;
            top: var(--navbar-height);
            left: 0;
            z-index: 100;
            width: var(--sidebar-width);
            height: calc(100vh - var(--navbar-height));
            background: #f8f9fa;
            border-right: 1px solid #dee2e6;
            overflow-y: auto;
        }
        
        .sidebar .nav-link {
            color: #495057;
            padding: 0.75rem 1rem;
            border-radius: 0;
        }
        
        .sidebar .nav-link:hover {
            background-color: #e9ecef;
            color: #212529;
        }
        
        .sidebar .nav-link.active {
            background-color: #0d6efd;
            color: white;
        }
        
        .sidebar .nav-link i {
            margin-right: 0.5rem;
            width: 1.2rem;
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 2rem;
            min-height: calc(100vh - var(--navbar-height));
        }
        
        .role-badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }
        
        .security-indicator {
            background: #e8f5e8;
            border: 1px solid #4caf50;
            border-radius: 20px;
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
        }
        
        .nav-section {
            padding: 0.5rem 1rem;
            font-weight: 600;
            color: #6c757d;
            text-transform: uppercase;
            font-size: 0.75rem;
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary fixed-top" id="top-navbar">
        <div class="container-fluid">
            <!-- Mobile menu toggle -->
            <button class="btn btn-outline-light d-md-none me-2" type="button" id="sidebar-toggle">
                <i class="bi bi-list"></i>
            </button>
            
            <!-- Brand -->
            <a class="navbar-brand text-white" href="dashboard.php" id="navbar-brand">
                <i class="bi bi-shield-check me-2"></i>
                <?php echo APP_NAME; ?>
            </a>
            
            <!-- Security indicator -->
            <div class="security-indicator d-none d-md-block" id="security-indicator">
                <i class="bi bi-shield-lock text-success me-1"></i>
                <span class="text-success">HIPAA Secure</span>
            </div>
            
            <!-- User menu -->
            <div class="dropdown" id="user-menu">
                <button class="btn btn-outline-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-person-circle me-1"></i>
                    <?php echo htmlspecialchars($full_name); ?>
                    <span class="badge bg-light text-primary role-badge ms-2">
                        <?php echo ucfirst(str_replace('_', ' ', $user_role)); ?>
                    </span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><h6 class="dropdown-header">Account</h6></li>
                    <li><a class="dropdown-item" href="profile.php" id="profile-link">
                        <i class="bi bi-person me-2"></i>My Profile
                    </a></li>
                    <li><a class="dropdown-item" href="settings.php" id="settings-link">
                        <i class="bi bi-gear me-2"></i>Settings
                    </a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="logout.php" id="logout-link">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </a></li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Sidebar Navigation -->
    <nav class="sidebar" id="sidebar">
        <div class="d-flex flex-column h-100">
            <!-- Main Navigation -->
            <ul class="nav nav-pills flex-column" id="main-navigation">
                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : ''; ?>" 
                       href="dashboard.php" id="nav-dashboard">
                        <i class="bi bi-speedometer2"></i>
                        Dashboard
                    </a>
                </li>
                
                <?php if ($auth->hasPermission(ROLE_INTAKE_SPECIALIST)): ?>
                <!-- Intake Management -->
                <div class="nav-section">Intake Management</div>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === 'new_intake.php' ? 'active' : ''; ?>" 
                       href="intake/new.php" id="nav-new-intake">
                        <i class="bi bi-plus-circle"></i>
                        New Intake
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'intake_list') !== false ? 'active' : ''; ?>" 
                       href="intake/list.php" id="nav-intake-list">
                        <i class="bi bi-list-ul"></i>
                        All Intakes
                        <span class="badge bg-warning ms-auto">5</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if ($auth->hasPermission(ROLE_PARALEGAL)): ?>
                <!-- Document Processing -->
                <div class="nav-section">Documents</div>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'documents') !== false ? 'active' : ''; ?>" 
                       href="documents/review.php" id="nav-documents">
                        <i class="bi bi-file-earmark-text"></i>
                        Document Review
                        <span class="badge bg-info ms-auto">12</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="documents/ocr_queue.php" id="nav-ocr-queue">
                        <i class="bi bi-cpu"></i>
                        OCR Processing
                        <span class="badge bg-secondary ms-auto">3</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if ($auth->hasPermission(ROLE_ATTORNEY)): ?>
                <!-- Case Analysis -->
                <div class="nav-section">Case Analysis</div>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'analysis') !== false ? 'active' : ''; ?>" 
                       href="analysis/review.php" id="nav-case-analysis">
                        <i class="bi bi-graph-up"></i>
                        Case Analysis
                        <span class="badge bg-primary ms-auto">7</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="analysis/decisions.php" id="nav-decisions">
                        <i class="bi bi-check-circle"></i>
                        Decision Queue
                        <span class="badge bg-warning ms-auto">4</span>
                    </a>
                </li>
                <?php endif; ?>
                
                <!-- Communication -->
                <div class="nav-section">Communication</div>
                
                <li class="nav-item">
                    <a class="nav-link" href="communication/messages.php" id="nav-messages">
                        <i class="bi bi-chat-dots"></i>
                        Messages
                        <span class="badge bg-success ms-auto">2</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="communication/email.php" id="nav-email">
                        <i class="bi bi-envelope"></i>
                        Email Center
                    </a>
                </li>
                
                <?php if ($auth->hasPermission(ROLE_MANAGING_PARTNER)): ?>
                <!-- Reporting & Analytics -->
                <div class="nav-section">Analytics</div>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'reports') !== false ? 'active' : ''; ?>" 
                       href="reports/dashboard.php" id="nav-reports">
                        <i class="bi bi-bar-chart"></i>
                        Reports
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="reports/analytics.php" id="nav-analytics">
                        <i class="bi bi-graph-up-arrow"></i>
                        Analytics
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if ($auth->hasPermission(ROLE_FIRM_ADMIN)): ?>
                <!-- Administration -->
                <div class="nav-section">Administration</div>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'admin') !== false ? 'active' : ''; ?>" 
                       href="admin/users.php" id="nav-users">
                        <i class="bi bi-people"></i>
                        User Management
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="admin/firm_settings.php" id="nav-firm-settings">
                        <i class="bi bi-building"></i>
                        Firm Settings
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="admin/templates.php" id="nav-templates">
                        <i class="bi bi-file-earmark-text"></i>
                        Templates
                    </a>
                </li>
                <?php endif; ?>
                
                <?php if ($auth->hasPermission(ROLE_SYSTEM_ADMIN)): ?>
                <!-- System Administration -->
                <div class="nav-section">System</div>
                
                <li class="nav-item">
                    <a class="nav-link" href="admin/system_health.php" id="nav-system-health">
                        <i class="bi bi-activity"></i>
                        System Health
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="admin/audit_logs.php" id="nav-audit-logs">
                        <i class="bi bi-shield-check"></i>
                        Audit Logs
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="admin/integrations.php" id="nav-integrations">
                        <i class="bi bi-plug"></i>
                        Integrations
                    </a>
                </li>
                <?php endif; ?>
            </ul>
            
            <!-- Footer info -->
            <div class="mt-auto p-3 border-top" id="sidebar-footer">
                <div class="text-center">
                    <small class="text-muted">
                        <i class="bi bi-shield-lock me-1"></i>
                        Session expires in <span id="session-timer">30:00</span>
                    </small>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content Area -->
    <main class="main-content" id="main-content">
        <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['flash_type'] ?? 'info'; ?> alert-dismissible fade show" role="alert" id="flash-message">
            <i class="bi bi-info-circle me-2"></i>
            <?php 
            echo htmlspecialchars($_SESSION['flash_message']); 
            unset($_SESSION['flash_message'], $_SESSION['flash_type']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Breadcrumb Navigation -->
        <?php if (isset($breadcrumbs) && !empty($breadcrumbs)): ?>
        <nav aria-label="breadcrumb" id="breadcrumb-nav">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="dashboard.php" class="text-decoration-none">
                        <i class="bi bi-house"></i> Dashboard
                    </a>
                </li>
                <?php foreach ($breadcrumbs as $crumb): ?>
                    <?php if (isset($crumb['url'])): ?>
                        <li class="breadcrumb-item">
                            <a href="<?php echo htmlspecialchars($crumb['url']); ?>" class="text-decoration-none">
                                <?php echo htmlspecialchars($crumb['title']); ?>
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="breadcrumb-item active" aria-current="page">
                            <?php echo htmlspecialchars($crumb['title']); ?>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ol>
        </nav>
        <?php endif; ?>

<script>
// Session timer
let sessionTimeLeft = <?php echo SESSION_TIMEOUT; ?>;
const sessionTimer = document.getElementById('session-timer');

function updateSessionTimer() {
    const minutes = Math.floor(sessionTimeLeft / 60);
    const seconds = sessionTimeLeft % 60;
    sessionTimer.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    
    if (sessionTimeLeft <= 0) {
        alert('Your session has expired. You will be redirected to the login page.');
        window.location.href = 'login.php';
        return;
    }
    
    if (sessionTimeLeft <= 300) { // 5 minutes warning
        sessionTimer.parentElement.classList.add('text-warning');
    }
    
    sessionTimeLeft--;
}

// Update timer every second
setInterval(updateSessionTimer, 1000);

// Mobile sidebar toggle
document.getElementById('sidebar-toggle')?.addEventListener('click', function() {
    document.getElementById('sidebar').classList.toggle('show');
});

// Auto-dismiss flash messages
setTimeout(function() {
    const flashMessage = document.getElementById('flash-message');
    if (flashMessage) {
        const bsAlert = new bootstrap.Alert(flashMessage);
        bsAlert.close();
    }
}, 5000);

// Reset session timer on user activity
document.addEventListener('mousemove', function() {
    sessionTimeLeft = Math.max(sessionTimeLeft, 60); // Reset to at least 1 minute
});

document.addEventListener('keypress', function() {
    sessionTimeLeft = Math.max(sessionTimeLeft, 60); // Reset to at least 1 minute
});
</script>