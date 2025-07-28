<?php
/**
 * Reports and Analytics Dashboard
 * Business intelligence for managing partners and administrators
 */

define('LEGAL_INTAKE_SYSTEM', true);
session_start();

require_once '../../config/config.php';
require_once '../../includes/auth.php';

$auth = new AuthSystem();
$auth->requireAuth(ROLE_MANAGING_PARTNER);

$page_title = 'Reports & Analytics';
$breadcrumbs = [
    ['title' => 'Reports & Analytics']
];

$db = new Database();
$conn = $db->getConnection();

// Get date range (default to last 30 days)
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));

// Key Metrics
$metrics_sql = "SELECT 
    COUNT(*) as total_intakes,
    SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_intakes,
    SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) as declined_intakes,
    SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as recent_intakes,
    SUM(CASE WHEN created_at >= ? AND status = 'accepted' THEN 1 ELSE 0 END) as recent_accepted,
    SUM(CASE WHEN estimated_damages IS NOT NULL THEN estimated_damages ELSE 0 END) as total_estimated_damages,
    AVG(CASE WHEN estimated_damages IS NOT NULL THEN estimated_damages ELSE NULL END) as avg_estimated_damages
FROM intake_forms 
WHERE firm_id = ?";

$stmt = $conn->prepare($metrics_sql);
$stmt->execute([$start_date, $start_date, $_SESSION['firm_id']]);
$metrics = $stmt->fetch();

// Conversion Rate
$conversion_rate = $metrics['total_intakes'] > 0 ? 
    round(($metrics['accepted_intakes'] / $metrics['total_intakes']) * 100, 1) : 0;

// Recent conversion rate
$recent_conversion_rate = $metrics['recent_intakes'] > 0 ? 
    round(($metrics['recent_accepted'] / $metrics['recent_intakes']) * 100, 1) : 0;

// Intake by Status
$status_sql = "SELECT status, COUNT(*) as count 
               FROM intake_forms 
               WHERE firm_id = ? AND created_at BETWEEN ? AND ?
               GROUP BY status";
$stmt = $conn->prepare($status_sql);
$stmt->execute([$_SESSION['firm_id'], $start_date, $end_date]);
$status_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Intake by Source
$source_sql = "SELECT source, COUNT(*) as count 
               FROM intake_forms 
               WHERE firm_id = ? AND created_at BETWEEN ? AND ?
               GROUP BY source";
$stmt = $conn->prepare($source_sql);
$stmt->execute([$_SESSION['firm_id'], $start_date, $end_date]);
$source_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Monthly Trend (last 12 months)
$trend_sql = "SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as month,
    COUNT(*) as total,
    SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted
FROM intake_forms 
WHERE firm_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
GROUP BY DATE_FORMAT(created_at, '%Y-%m')
ORDER BY month";
$stmt = $conn->prepare($trend_sql);
$stmt->execute([$_SESSION['firm_id']]);
$trend_data = $stmt->fetchAll();

// Staff Performance
$staff_sql = "SELECT 
    u.first_name, u.last_name,
    COUNT(if1.id) as created_count,
    COUNT(if2.id) as assigned_count,
    AVG(CASE WHEN if2.status = 'accepted' THEN 1 WHEN if2.status = 'declined' THEN 0 ELSE NULL END) as success_rate
FROM users u
LEFT JOIN intake_forms if1 ON u.id = if1.created_by AND if1.created_at BETWEEN ? AND ?
LEFT JOIN intake_forms if2 ON u.id = if2.assigned_to AND if2.created_at BETWEEN ? AND ?
WHERE u.firm_id = ? AND u.role IN (?, ?, ?)
GROUP BY u.id, u.first_name, u.last_name
HAVING created_count > 0 OR assigned_count > 0";

$stmt = $conn->prepare($staff_sql);
$stmt->execute([
    $start_date, $end_date, $start_date, $end_date, 
    $_SESSION['firm_id'], 
    ROLE_INTAKE_SPECIALIST, ROLE_PARALEGAL, ROLE_ATTORNEY
]);
$staff_performance = $stmt->fetchAll();

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4" id="page-header">
    <div>
        <h1 class="h3 mb-0" id="page-title">Reports & Analytics</h1>
        <p class="text-muted" id="page-subtitle">
            Data from <?php echo format_date($start_date); ?> to <?php echo format_date($end_date); ?>
        </p>
    </div>
    <div class="d-flex gap-2" id="page-actions">
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#dateRangeModal">
            <i class="bi bi-calendar3 me-2"></i>
            Date Range
        </button>
        <button type="button" class="btn btn-outline-secondary" onclick="exportReport()">
            <i class="bi bi-download me-2"></i>
            Export
        </button>
    </div>
</div>

<!-- Key Metrics Row -->
<div class="row mb-4" id="key-metrics">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm h-100" id="total-intakes-metric">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="bg-primary bg-gradient rounded-circle p-3">
                            <i class="bi bi-file-earmark-text text-white fs-4"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="fw-bold fs-3"><?php echo number_format($metrics['total_intakes']); ?></div>
                        <div class="text-muted">Total Intakes</div>
                        <small class="text-success">
                            +<?php echo number_format($metrics['recent_intakes']); ?> this period
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm h-100" id="conversion-rate-metric">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="bg-success bg-gradient rounded-circle p-3">
                            <i class="bi bi-check-circle text-white fs-4"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="fw-bold fs-3"><?php echo $conversion_rate; ?>%</div>
                        <div class="text-muted">Conversion Rate</div>
                        <small class="<?php echo $recent_conversion_rate >= $conversion_rate ? 'text-success' : 'text-danger'; ?>">
                            <?php echo $recent_conversion_rate; ?>% this period
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm h-100" id="avg-damages-metric">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="bg-warning bg-gradient rounded-circle p-3">
                            <i class="bi bi-currency-dollar text-white fs-4"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="fw-bold fs-3">
                            <?php echo $metrics['avg_estimated_damages'] ? format_currency($metrics['avg_estimated_damages']) : '$0'; ?>
                        </div>
                        <div class="text-muted">Avg Case Value</div>
                        <small class="text-info">
                            <?php echo format_currency($metrics['total_estimated_damages']); ?> total
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-0 shadow-sm h-100" id="accepted-intakes-metric">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="bg-info bg-gradient rounded-circle p-3">
                            <i class="bi bi-award text-white fs-4"></i>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <div class="fw-bold fs-3"><?php echo number_format($metrics['accepted_intakes']); ?></div>
                        <div class="text-muted">Accepted Cases</div>
                        <small class="text-success">
                            +<?php echo number_format($metrics['recent_accepted']); ?> this period
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="row mb-4" id="charts-row">
    <!-- Monthly Trend Chart -->
    <div class="col-lg-8 mb-4">
        <div class="card border-0 shadow-sm" id="trend-chart-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-graph-up me-2"></i>
                    Monthly Intake Trend
                </h5>
            </div>
            <div class="card-body">
                <canvas id="trendChart" height="300"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Status Distribution -->
    <div class="col-lg-4 mb-4">
        <div class="card border-0 shadow-sm" id="status-chart-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-pie-chart me-2"></i>
                    Status Distribution
                </h5>
            </div>
            <div class="card-body">
                <canvas id="statusChart" height="300"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Data Tables Row -->
<div class="row" id="data-tables-row">
    <!-- Source Analysis -->
    <div class="col-lg-6 mb-4">
        <div class="card border-0 shadow-sm" id="source-analysis-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-funnel me-2"></i>
                    Intake Sources
                </h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($source_data)): ?>
                <div class="text-center py-4">
                    <p class="text-muted">No source data available for this period</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Source</th>
                                <th>Count</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_sources = array_sum($source_data);
                            foreach ($source_data as $source => $count): 
                                $percentage = $total_sources > 0 ? round(($count / $total_sources) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td><?php echo ucfirst($source ?: 'Unknown'); ?></td>
                                <td><?php echo number_format($count); ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="progress me-2" style="width: 60px; height: 8px;">
                                            <div class="progress-bar" style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                        <?php echo $percentage; ?>%
                                    </div>
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
    
    <!-- Staff Performance -->
    <div class="col-lg-6 mb-4">
        <div class="card border-0 shadow-sm" id="staff-performance-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-people me-2"></i>
                    Staff Performance
                </h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($staff_performance)): ?>
                <div class="text-center py-4">
                    <p class="text-muted">No staff performance data for this period</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Staff Member</th>
                                <th>Created</th>
                                <th>Assigned</th>
                                <th>Success Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($staff_performance as $staff): ?>
                            <tr>
                                <td>
                                    <div class="fw-medium">
                                        <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?>
                                    </div>
                                </td>
                                <td><?php echo number_format($staff['created_count']); ?></td>
                                <td><?php echo number_format($staff['assigned_count']); ?></td>
                                <td>
                                    <?php if ($staff['success_rate'] !== null): ?>
                                        <?php 
                                        $success_rate = round($staff['success_rate'] * 100, 1);
                                        $color = $success_rate >= 70 ? 'success' : ($success_rate >= 50 ? 'warning' : 'danger');
                                        ?>
                                        <span class="badge bg-<?php echo $color; ?>">
                                            <?php echo $success_rate; ?>%
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
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
</div>

<!-- Date Range Modal -->
<div class="modal fade" id="dateRangeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Select Date Range</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="GET" action="">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" 
                                   value="<?php echo htmlspecialchars($start_date); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" 
                                   value="<?php echo htmlspecialchars($end_date); ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Quick Ranges</label>
                        <div class="btn-group d-flex" role="group">
                            <button type="button" class="btn btn-outline-secondary" onclick="setDateRange(7)">Last 7 days</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="setDateRange(30)">Last 30 days</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="setDateRange(90)">Last 90 days</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="setDateRange(365)">Last year</button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$additional_js = "
<script src=\"https://cdn.jsdelivr.net/npm/chart.js\"></script>
<script>
// Trend Chart
const trendCtx = document.getElementById('trendChart').getContext('2d');
const trendData = " . json_encode($trend_data) . ";

const trendChart = new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: trendData.map(item => item.month),
        datasets: [{
            label: 'Total Intakes',
            data: trendData.map(item => item.total),
            borderColor: '#0d6efd',
            backgroundColor: 'rgba(13, 110, 253, 0.1)',
            tension: 0.1
        }, {
            label: 'Accepted',
            data: trendData.map(item => item.accepted),
            borderColor: '#198754',
            backgroundColor: 'rgba(25, 135, 84, 0.1)',
            tension: 0.1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
            }
        },
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

// Status Chart
const statusCtx = document.getElementById('statusChart').getContext('2d');
const statusData = " . json_encode($status_data) . ";

const statusChart = new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: Object.keys(statusData).map(status => 
            status.split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ')
        ),
        datasets: [{
            data: Object.values(statusData),
            backgroundColor: [
                '#6c757d', '#17a2b8', '#ffc107', '#fd7e14', '#0d6efd', '#198754', '#dc3545'
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
            }
        }
    }
});

// Date range functions
function setDateRange(days) {
    const endDate = new Date();
    const startDate = new Date();
    startDate.setDate(endDate.getDate() - days);
    
    document.getElementById('start_date').value = startDate.toISOString().split('T')[0];
    document.getElementById('end_date').value = endDate.toISOString().split('T')[0];
}

// Export function
function exportReport() {
    const startDate = '" . $start_date . "';
    const endDate = '" . $end_date . "';
    
    window.open('../../api/reports/export.php?start_date=' + startDate + '&end_date=' + endDate, '_blank');
}
</script>
";

include '../../includes/footer.php';
?>