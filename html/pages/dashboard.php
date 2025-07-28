<?php
/**
 * Main Dashboard for Legal Intake System
 * Role-specific dashboard with widgets and metrics
 */

define('LEGAL_INTAKE_SYSTEM', true);
session_start();

require_once '../config/config.php';
require_once '../includes/auth.php';

$auth = new AuthSystem();
$auth->requireAuth();

$user = $auth->getCurrentUser();
$user_role = $_SESSION['user_role'];
$page_title = 'Dashboard';

// Get dashboard statistics
$db = new Database();
$conn = $db->getConnection();

// Get intake statistics
$stats = [];

try {
    // Total intakes
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM intake_forms WHERE firm_id = ?");
    $stmt->execute([$_SESSION['firm_id']]);
    $stats['total_intakes'] = $stmt->fetch()['total'];
    
    // New intakes (last 7 days)
    $stmt = $conn->prepare("SELECT COUNT(*) as new_intakes FROM intake_forms WHERE firm_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stmt->execute([$_SESSION['firm_id']]);
    $stats['new_intakes'] = $stmt->fetch()['new_intakes'];
    
    // Pending review
    $stmt = $conn->prepare("SELECT COUNT(*) as pending FROM intake_forms WHERE firm_id = ? AND status IN ('new_intake', 'under_review', 'attorney_review')");
    $stmt->execute([$_SESSION['firm_id']]);
    $stats['pending_review'] = $stmt->fetch()['pending'];
    
    // Accepted cases
    $stmt = $conn->prepare("SELECT COUNT(*) as accepted FROM intake_forms WHERE firm_id = ? AND status = 'accepted'");
    $stmt->execute([$_SESSION['firm_id']]);
    $stats['accepted_cases'] = $stmt->fetch()['accepted'];
    
    // Recent intakes for the current user's role
    $recent_sql = "SELECT id, intake_number, status, priority, incident_description, created_at 
                   FROM intake_forms 
                   WHERE firm_id = ?";
    
    // Role-based filtering
    if (!$auth->hasPermission(ROLE_ATTORNEY)) {
        $recent_sql .= " AND (assigned_to = ? OR created_by = ?)";
        $stmt = $conn->prepare($recent_sql . " ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$_SESSION['firm_id'], $user['id'], $user['id']]);
    } else {
        $stmt = $conn->prepare($recent_sql . " ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$_SESSION['firm_id']]);
    }
    $recent_intakes = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $stats = ['total_intakes' => 0, 'new_intakes' => 0, 'pending_review' => 0, 'accepted_cases' => 0];
    $recent_intakes = [];
}

include '../includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4" id="page-header">
    <div>
        <h1 class="h3 mb-0" id="page-title">Welcome back, <?php echo htmlspecialchars($user['first_name']); ?>!</h1>
        <p class="text-muted" id="page-subtitle">
            <?php echo ucfirst(str_replace('_', ' ', $user_role)); ?> Dashboard
        </p>
    </div>
    <div class="d-flex gap-2" id="page-actions">
        <?php if ($auth->hasPermission(ROLE_INTAKE_SPECIALIST)): ?>
        <a href="intake/new.php" class="btn btn-primary" id="new-intake-btn">
            <i class="bi bi-plus-circle me-2"></i>
            New Intake
        </a>
        <?php endif; ?>
        
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#helpModal" id="help-btn">
            <i class="bi bi-question-circle"></i>
        </button>
    </div>
</div>

<!-- Quick Stats Row -->
<div class="row mb-4" id="stats-row">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm h-100" id="total-intakes-card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="bg-primary bg-gradient rounded-circle p-3">
                            <i class="bi bi-file-earmark-text text-white fs-4"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="fw-bold fs-4"><?php echo number_format($stats['total_intakes']); ?></div>
                        <div class="text-muted">Total Intakes</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm h-100" id="new-intakes-card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="bg-success bg-gradient rounded-circle p-3">
                            <i class="bi bi-plus-circle text-white fs-4"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="fw-bold fs-4"><?php echo number_format($stats['new_intakes']); ?></div>
                        <div class="text-muted">New (7 days)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm h-100" id="pending-review-card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="bg-warning bg-gradient rounded-circle p-3">
                            <i class="bi bi-clock text-white fs-4"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="fw-bold fs-4"><?php echo number_format($stats['pending_review']); ?></div>
                        <div class="text-muted">Pending Review</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm h-100" id="accepted-cases-card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="bg-info bg-gradient rounded-circle p-3">
                            <i class="bi bi-check-circle text-white fs-4"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="fw-bold fs-4"><?php echo number_format($stats['accepted_cases']); ?></div>
                        <div class="text-muted">Accepted Cases</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Recent Intakes -->
    <div class="col-lg-8 mb-4">
        <div class="card border-0 shadow-sm" id="recent-intakes-card">
            <div class="card-header bg-white border-bottom-0 d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0" id="recent-intakes-title">
                    <i class="bi bi-clock-history me-2"></i>
                    Recent Intakes
                </h5>
                <a href="intake/list.php" class="btn btn-sm btn-outline-primary" id="view-all-intakes">
                    View All
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recent_intakes)): ?>
                <div class="text-center py-5" id="no-intakes-message">
                    <i class="bi bi-inbox text-muted fs-1"></i>
                    <p class="text-muted mt-2">No recent intakes to display</p>
                    <?php if ($auth->hasPermission(ROLE_INTAKE_SPECIALIST)): ?>
                    <a href="intake/new.php" class="btn btn-primary">Create First Intake</a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="table-responsive" id="recent-intakes-table">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Intake #</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_intakes as $intake): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($intake['intake_number']); ?></strong>
                                </td>
                                <td>
                                    <div class="text-truncate" style="max-width: 250px;">
                                        <?php echo htmlspecialchars($intake['incident_description'] ?: 'No description provided'); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php echo get_status_badge($intake['status']); ?>
                                </td>
                                <td>
                                    <?php echo get_priority_badge($intake['priority']); ?>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?php echo format_date($intake['created_at']); ?>
                                    </small>
                                </td>
                                <td>
                                    <a href="intake/view.php?id=<?php echo $intake['id']; ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Actions & System Status -->
    <div class="col-lg-4">
        <!-- Quick Actions -->
        <div class="card border-0 shadow-sm mb-4" id="quick-actions-card">
            <div class="card-header bg-white border-bottom-0">
                <h5 class="card-title mb-0" id="quick-actions-title">
                    <i class="bi bi-lightning me-2"></i>
                    Quick Actions
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2" id="quick-actions-list">
                    <?php if ($auth->hasPermission(ROLE_INTAKE_SPECIALIST)): ?>
                    <a href="intake/new.php" class="btn btn-outline-primary text-start" id="quick-new-intake">
                        <i class="bi bi-plus-circle me-2"></i>
                        Create New Intake
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($auth->hasPermission(ROLE_PARALEGAL)): ?>
                    <a href="documents/review.php" class="btn btn-outline-info text-start" id="quick-documents">
                        <i class="bi bi-file-earmark-text me-2"></i>
                        Review Documents
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($auth->hasPermission(ROLE_ATTORNEY)): ?>
                    <a href="analysis/review.php" class="btn btn-outline-warning text-start" id="quick-analysis">
                        <i class="bi bi-graph-up me-2"></i>
                        Case Analysis
                    </a>
                    <?php endif; ?>
                    
                    <a href="communication/messages.php" class="btn btn-outline-success text-start" id="quick-messages">
                        <i class="bi bi-chat-dots me-2"></i>
                        Messages
                        <span class="badge bg-success ms-auto">2</span>
                    </a>
                    
                    <?php if ($auth->hasPermission(ROLE_MANAGING_PARTNER)): ?>
                    <a href="reports/dashboard.php" class="btn btn-outline-secondary text-start" id="quick-reports">
                        <i class="bi bi-bar-chart me-2"></i>
                        View Reports
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- System Status -->
        <div class="card border-0 shadow-sm" id="system-status-card">
            <div class="card-header bg-white border-bottom-0">
                <h5 class="card-title mb-0" id="system-status-title">
                    <i class="bi bi-activity me-2"></i>
                    System Status
                </h5>
            </div>
            <div class="card-body">
                <div class="mb-3" id="system-health">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>System Health</span>
                        <span class="badge bg-success">Online</span>
                    </div>
                </div>
                
                <div class="mb-3" id="ocr-status">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>OCR Processing</span>
                        <span class="badge bg-info">Active</span>
                    </div>
                </div>
                
                <div class="mb-3" id="backup-status">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>Last Backup</span>
                        <small class="text-muted">2 hours ago</small>
                    </div>
                </div>
                
                <hr>
                
                <div class="text-center" id="system-version">
                    <small class="text-muted">
                        Version <?php echo APP_VERSION; ?><br>
                        <i class="bi bi-shield-check text-success"></i>
                        HIPAA Compliant
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Help Modal -->
<div class="modal fade" id="helpModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-question-circle me-2"></i>
                    Getting Started
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Quick Start Guide</h6>
                        <ul class="list-unstyled">
                            <?php if ($auth->hasPermission(ROLE_INTAKE_SPECIALIST)): ?>
                            <li class="mb-2">
                                <i class="bi bi-1-circle text-primary me-2"></i>
                                Create a new client intake
                            </li>
                            <li class="mb-2">
                                <i class="bi bi-2-circle text-primary me-2"></i>
                                Upload client documents
                            </li>
                            <?php endif; ?>
                            
                            <?php if ($auth->hasPermission(ROLE_PARALEGAL)): ?>
                            <li class="mb-2">
                                <i class="bi bi-3-circle text-primary me-2"></i>
                                Review OCR results
                            </li>
                            <?php endif; ?>
                            
                            <?php if ($auth->hasPermission(ROLE_ATTORNEY)): ?>
                            <li class="mb-2">
                                <i class="bi bi-4-circle text-primary me-2"></i>
                                Analyze case strength
                            </li>
                            <li class="mb-2">
                                <i class="bi bi-5-circle text-primary me-2"></i>
                                Make acceptance decision
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Support Resources</h6>
                        <ul class="list-unstyled">
                            <li class="mb-2">
                                <a href="#" class="text-decoration-none">
                                    <i class="bi bi-book me-2"></i>
                                    User Manual
                                </a>
                            </li>
                            <li class="mb-2">
                                <a href="#" class="text-decoration-none">
                                    <i class="bi bi-play-circle me-2"></i>
                                    Video Tutorials
                                </a>
                            </li>
                            <li class="mb-2">
                                <a href="#" class="text-decoration-none">
                                    <i class="bi bi-chat-dots me-2"></i>
                                    Live Support
                                </a>
                            </li>
                            <li class="mb-2">
                                <a href="#" class="text-decoration-none">
                                    <i class="bi bi-telephone me-2"></i>
                                    Call Support
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
$additional_js = "
<script>
// Dashboard-specific JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Auto-refresh stats every 5 minutes
    setInterval(function() {
        // Could implement AJAX refresh of stats here
    }, 300000);
    
    // Animate number counters
    const counters = document.querySelectorAll('.fw-bold.fs-4');
    counters.forEach(counter => {
        const target = parseInt(counter.innerText.replace(/,/g, ''));
        const increment = target / 100;
        let current = 0;
        
        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                counter.innerText = target.toLocaleString();
                clearInterval(timer);
            } else {
                counter.innerText = Math.floor(current).toLocaleString();
            }
        }, 20);
    });
});
</script>
";

include '../includes/footer.php';
?>