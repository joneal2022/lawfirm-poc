<?php
/**
 * Intake List and Management Interface
 * Comprehensive intake workflow management
 */

define('LEGAL_INTAKE_SYSTEM', true);
session_start();

require_once '../../config/config.php';
require_once '../../includes/auth.php';

$auth = new AuthSystem();
$auth->requireAuth(ROLE_INTAKE_SPECIALIST);

$page_title = 'Intake Management';
$breadcrumbs = [
    ['title' => 'Intake Management']
];

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$assigned_filter = $_GET['assigned'] ?? '';
$search = $_GET['search'] ?? '';

// Build query with filters
$db = new Database();
$conn = $db->getConnection();

$where_conditions = ["if.firm_id = ?"];
$params = [$_SESSION['firm_id']];

// Role-based filtering
if (!$auth->hasPermission(ROLE_ATTORNEY)) {
    $where_conditions[] = "(if.assigned_to = ? OR if.created_by = ?)";
    $params[] = $_SESSION['user_id'];
    $params[] = $_SESSION['user_id'];
}

// Status filter
if ($status_filter) {
    $where_conditions[] = "if.status = ?";
    $params[] = $status_filter;
}

// Priority filter
if ($priority_filter) {
    $where_conditions[] = "if.priority = ?";
    $params[] = $priority_filter;
}

// Assigned filter
if ($assigned_filter) {
    if ($assigned_filter === 'unassigned') {
        $where_conditions[] = "if.assigned_to IS NULL";
    } else {
        $where_conditions[] = "if.assigned_to = ?";
        $params[] = $assigned_filter;
    }
}

// Search filter
if ($search) {
    $where_conditions[] = "(if.intake_number LIKE ? OR if.incident_description LIKE ? OR CONCAT(COALESCE(c.first_name_encrypted, ''), ' ', COALESCE(c.last_name_encrypted, '')) LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term; 
    $params[] = $search_term;
}

$where_clause = implode(' AND ', $where_conditions);

// Get intakes with pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 25;
$offset = ($page - 1) * $per_page;

$sql = "SELECT if.*, 
               c.first_name_encrypted, c.last_name_encrypted,
               u.first_name as assigned_first_name, u.last_name as assigned_last_name,
               cr.first_name as creator_first_name, cr.last_name as creator_last_name,
               COUNT(d.id) as document_count
        FROM intake_forms if
        LEFT JOIN clients c ON if.client_id = c.id
        LEFT JOIN users u ON if.assigned_to = u.id
        LEFT JOIN users cr ON if.created_by = cr.id
        LEFT JOIN documents d ON if.id = d.intake_id
        WHERE {$where_clause}
        GROUP BY if.id
        ORDER BY if.created_at DESC
        LIMIT {$per_page} OFFSET {$offset}";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$intakes = $stmt->fetchAll();

// Decrypt client names
foreach ($intakes as &$intake) {
    $intake['client_name'] = $db->decrypt($intake['first_name_encrypted']) . ' ' . $db->decrypt($intake['last_name_encrypted']);
}

// Get total count for pagination
$count_sql = "SELECT COUNT(DISTINCT if.id) as total 
              FROM intake_forms if
              LEFT JOIN clients c ON if.client_id = c.id
              WHERE {$where_clause}";

$stmt = $conn->prepare($count_sql);
$stmt->execute($params);
$total_intakes = $stmt->fetch()['total'];
$total_pages = ceil($total_intakes / $per_page);

// Get users for assignment filter
$users_sql = "SELECT id, first_name, last_name FROM users WHERE firm_id = ? AND role IN (?, ?, ?) ORDER BY first_name";
$stmt = $conn->prepare($users_sql);
$stmt->execute([$_SESSION['firm_id'], ROLE_PARALEGAL, ROLE_ATTORNEY, ROLE_MANAGING_PARTNER]);
$users = $stmt->fetchAll();

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4" id="page-header">
    <div>
        <h1 class="h3 mb-0" id="page-title">Intake Management</h1>
        <p class="text-muted" id="page-subtitle">
            Showing <?php echo count($intakes); ?> of <?php echo number_format($total_intakes); ?> intakes
        </p>
    </div>
    <div class="d-flex gap-2" id="page-actions">
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#filtersModal" id="filters-btn">
            <i class="bi bi-funnel me-2"></i>
            Filters
            <?php if ($status_filter || $priority_filter || $assigned_filter || $search): ?>
            <span class="badge bg-primary ms-1">Active</span>
            <?php endif; ?>
        </button>
        
        <?php if ($auth->hasPermission(ROLE_INTAKE_SPECIALIST)): ?>
        <a href="new.php" class="btn btn-primary" id="new-intake-btn">
            <i class="bi bi-plus-circle me-2"></i>
            New Intake
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Quick Stats -->
<div class="row mb-4" id="quick-stats">
    <?php
    // Get quick stats
    $stats_sql = "SELECT 
                    SUM(CASE WHEN status = 'new_intake' THEN 1 ELSE 0 END) as new_count,
                    SUM(CASE WHEN status IN ('under_review', 'attorney_review') THEN 1 ELSE 0 END) as review_count,
                    SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_count,
                    SUM(CASE WHEN priority = 'urgent' THEN 1 ELSE 0 END) as urgent_count
                  FROM intake_forms WHERE firm_id = ?";
    $stmt = $conn->prepare($stats_sql);
    $stmt->execute([$_SESSION['firm_id']]);
    $stats = $stmt->fetch();
    ?>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm h-100" id="new-intakes-stat">
            <div class="card-body text-center">
                <div class="h4 text-secondary mb-2"><?php echo $stats['new_count']; ?></div>
                <div class="text-muted">New Intakes</div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm h-100" id="review-intakes-stat">
            <div class="card-body text-center">
                <div class="h4 text-warning mb-2"><?php echo $stats['review_count']; ?></div>
                <div class="text-muted">Under Review</div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm h-100" id="accepted-intakes-stat">
            <div class="card-body text-center">
                <div class="h4 text-success mb-2"><?php echo $stats['accepted_count']; ?></div>
                <div class="text-muted">Accepted</div>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm h-100" id="urgent-intakes-stat">
            <div class="card-body text-center">
                <div class="h4 text-danger mb-2"><?php echo $stats['urgent_count']; ?></div>
                <div class="text-muted">Urgent Priority</div>
            </div>
        </div>
    </div>
</div>

<!-- Intakes Table -->
<div class="card" id="intakes-table-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            <i class="bi bi-list-ul me-2"></i>
            Intake List
        </h5>
        <div class="d-flex gap-2">
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-three-dots"></i>
                    Actions
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="#" onclick="exportIntakes()">
                        <i class="bi bi-download me-2"></i>Export to CSV
                    </a></li>
                    <li><a class="dropdown-item" href="#" onclick="bulkAssign()">
                        <i class="bi bi-people me-2"></i>Bulk Assign
                    </a></li>
                    <li><a class="dropdown-item" href="#" onclick="bulkStatusUpdate()">
                        <i class="bi bi-arrow-repeat me-2"></i>Bulk Status Update
                    </a></li>
                </ul>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($intakes)): ?>
        <div class="text-center py-5" id="no-intakes-message">
            <i class="bi bi-inbox text-muted fs-1"></i>
            <p class="text-muted mt-2">No intakes found matching your criteria</p>
            <?php if ($auth->hasPermission(ROLE_INTAKE_SPECIALIST)): ?>
            <a href="new.php" class="btn btn-primary">Create First Intake</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive" id="intakes-table">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>
                            <input type="checkbox" class="form-check-input" id="select-all">
                        </th>
                        <th>Intake #</th>
                        <th>Client</th>
                        <th>Incident</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th>Assigned To</th>
                        <th>Documents</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($intakes as $intake): ?>
                    <tr data-intake-id="<?php echo $intake['id']; ?>">
                        <td>
                            <input type="checkbox" class="form-check-input intake-checkbox" 
                                   value="<?php echo $intake['id']; ?>">
                        </td>
                        <td>
                            <div class="fw-medium">
                                <a href="view.php?id=<?php echo $intake['id']; ?>" 
                                   class="text-decoration-none">
                                    <?php echo htmlspecialchars($intake['intake_number']); ?>
                                </a>
                            </div>
                        </td>
                        <td>
                            <div class="fw-medium"><?php echo htmlspecialchars($intake['client_name']); ?></div>
                        </td>
                        <td>
                            <div class="text-truncate" style="max-width: 200px;">
                                <?php echo htmlspecialchars($intake['incident_description'] ?: 'No description'); ?>
                            </div>
                            <small class="text-muted">
                                <?php echo $intake['incident_date'] ? format_date($intake['incident_date']) : 'Date not set'; ?>
                            </small>
                        </td>
                        <td>
                            <div class="d-flex align-items-center">
                                <?php echo get_status_badge($intake['status']); ?>
                                <?php if ($intake['status'] === STATUS_NEW_INTAKE): ?>
                                <button type="button" class="btn btn-sm btn-outline-primary ms-2" 
                                        onclick="quickStatusUpdate(<?php echo $intake['id']; ?>, '<?php echo STATUS_UNDER_REVIEW; ?>')"
                                        title="Move to Review">
                                    <i class="bi bi-arrow-right"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php echo get_priority_badge($intake['priority']); ?>
                        </td>
                        <td>
                            <?php if ($intake['assigned_to']): ?>
                                <div class="fw-medium">
                                    <?php echo htmlspecialchars($intake['assigned_first_name'] . ' ' . $intake['assigned_last_name']); ?>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">Unassigned</span>
                                <?php if ($auth->hasPermission(ROLE_PARALEGAL)): ?>
                                <button type="button" class="btn btn-sm btn-outline-primary ms-1" 
                                        onclick="quickAssign(<?php echo $intake['id']; ?>)"
                                        title="Assign">
                                    <i class="bi bi-person-plus"></i>
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($intake['document_count'] > 0): ?>
                                <a href="../documents/upload.php?intake_id=<?php echo $intake['id']; ?>" 
                                   class="text-decoration-none">
                                    <i class="bi bi-file-earmark-text text-primary me-1"></i>
                                    <?php echo $intake['document_count']; ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                                <a href="../documents/upload.php?intake_id=<?php echo $intake['id']; ?>" 
                                   class="btn btn-sm btn-outline-secondary ms-1"
                                   title="Upload Documents">
                                    <i class="bi bi-upload"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small class="text-muted">
                                <?php echo format_date($intake['created_at']); ?>
                                <br>
                                by <?php echo htmlspecialchars($intake['creator_first_name'] . ' ' . $intake['creator_last_name']); ?>
                            </small>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="view.php?id=<?php echo $intake['id']; ?>" 
                                   class="btn btn-outline-primary" title="View Details">
                                    <i class="bi bi-eye"></i>
                                </a>
                                
                                <?php if ($auth->hasPermission(ROLE_PARALEGAL)): ?>
                                <button type="button" class="btn btn-outline-warning" 
                                        onclick="editIntake(<?php echo $intake['id']; ?>)" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php endif; ?>
                                
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" 
                                            type="button" data-bs-toggle="dropdown">
                                        <span class="visually-hidden">More actions</span>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="../documents/upload.php?intake_id=<?php echo $intake['id']; ?>">
                                            <i class="bi bi-upload me-2"></i>Upload Documents
                                        </a></li>
                                        <li><a class="dropdown-item" href="#" onclick="addNote(<?php echo $intake['id']; ?>)">
                                            <i class="bi bi-chat-text me-2"></i>Add Note
                                        </a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item" href="#" onclick="duplicateIntake(<?php echo $intake['id']; ?>)">
                                            <i class="bi bi-files me-2"></i>Duplicate
                                        </a></li>
                                    </ul>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav class="p-3" id="pagination-nav">
            <ul class="pagination justify-content-center mb-0">
                <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page'), '', '&'); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $start_page + 4);
                $start_page = max(1, $end_page - 4);
                
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page'), '', '&'); ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page'), '', '&'); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Filters Modal -->
<div class="modal fade" id="filtersModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-funnel me-2"></i>
                    Filter Intakes
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="GET" action="" id="filters-form">
                <div class="modal-body">
                    <!-- Search -->
                    <div class="mb-3">
                        <label for="search" class="form-label">Search</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Intake number, client name, or description...">
                    </div>
                    
                    <!-- Status Filter -->
                    <div class="mb-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Statuses</option>
                            <option value="<?php echo STATUS_NEW_INTAKE; ?>" <?php echo $status_filter === STATUS_NEW_INTAKE ? 'selected' : ''; ?>>New Intake</option>
                            <option value="<?php echo STATUS_DOCUMENTS_PENDING; ?>" <?php echo $status_filter === STATUS_DOCUMENTS_PENDING ? 'selected' : ''; ?>>Documents Pending</option>
                            <option value="<?php echo STATUS_UNDER_REVIEW; ?>" <?php echo $status_filter === STATUS_UNDER_REVIEW ? 'selected' : ''; ?>>Under Review</option>
                            <option value="<?php echo STATUS_ADDITIONAL_INFO_NEEDED; ?>" <?php echo $status_filter === STATUS_ADDITIONAL_INFO_NEEDED ? 'selected' : ''; ?>>Additional Info Needed</option>
                            <option value="<?php echo STATUS_ATTORNEY_REVIEW; ?>" <?php echo $status_filter === STATUS_ATTORNEY_REVIEW ? 'selected' : ''; ?>>Attorney Review</option>
                            <option value="<?php echo STATUS_ACCEPTED; ?>" <?php echo $status_filter === STATUS_ACCEPTED ? 'selected' : ''; ?>>Accepted</option>
                            <option value="<?php echo STATUS_DECLINED; ?>" <?php echo $status_filter === STATUS_DECLINED ? 'selected' : ''; ?>>Declined</option>
                        </select>
                    </div>
                    
                    <!-- Priority Filter -->
                    <div class="mb-3">
                        <label for="priority" class="form-label">Priority</label>
                        <select class="form-select" id="priority" name="priority">
                            <option value="">All Priorities</option>
                            <option value="<?php echo PRIORITY_URGENT; ?>" <?php echo $priority_filter === PRIORITY_URGENT ? 'selected' : ''; ?>>Urgent</option>
                            <option value="<?php echo PRIORITY_HIGH; ?>" <?php echo $priority_filter === PRIORITY_HIGH ? 'selected' : ''; ?>>High</option>
                            <option value="<?php echo PRIORITY_NORMAL; ?>" <?php echo $priority_filter === PRIORITY_NORMAL ? 'selected' : ''; ?>>Normal</option>
                            <option value="<?php echo PRIORITY_LOW; ?>" <?php echo $priority_filter === PRIORITY_LOW ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                    
                    <!-- Assigned Filter -->
                    <div class="mb-3">
                        <label for="assigned" class="form-label">Assigned To</label>
                        <select class="form-select" id="assigned" name="assigned">
                            <option value="">All Assignments</option>
                            <option value="unassigned" <?php echo $assigned_filter === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                            <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $assigned_filter == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="?" class="btn btn-outline-secondary">Clear Filters</a>
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$additional_js = "
<script>
// Select all functionality
document.getElementById('select-all').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.intake-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
    updateBulkActionsVisibility();
});

// Individual checkbox handling
document.querySelectorAll('.intake-checkbox').forEach(cb => {
    cb.addEventListener('change', updateBulkActionsVisibility);
});

function updateBulkActionsVisibility() {
    const checkedBoxes = document.querySelectorAll('.intake-checkbox:checked');
    const selectAll = document.getElementById('select-all');
    
    // Update select-all checkbox state
    if (checkedBoxes.length === 0) {
        selectAll.indeterminate = false;
        selectAll.checked = false;
    } else if (checkedBoxes.length === document.querySelectorAll('.intake-checkbox').length) {
        selectAll.indeterminate = false;
        selectAll.checked = true;
    } else {
        selectAll.indeterminate = true;
        selectAll.checked = false;
    }
}

// Quick status update
function quickStatusUpdate(intakeId, newStatus) {
    if (confirm('Update intake status?')) {
        fetch('../../api/intake/update_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                intake_id: intakeId,
                status: newStatus,
                csrf_token: '<?php echo $auth->generateCSRFToken(); ?>'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Status updated successfully', 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showToast('Failed to update status: ' + data.message, 'error');
            }
        })
        .catch(error => {
            showToast('Error updating status', 'error');
            console.error(error);
        });
    }
}

// Quick assign
function quickAssign(intakeId) {
    const users = <?php echo json_encode($users); ?>;
    let options = users.map(user => 
        `<option value=\"${user.id}\">${user.first_name} ${user.last_name}</option>`
    ).join('');
    
    const html = `
        <div class=\"mb-3\">
            <label class=\"form-label\">Assign to:</label>
            <select class=\"form-select\" id=\"assign-user\">
                <option value=\"\">Select user...</option>
                ${options}
            </select>
        </div>
    `;
    
    if (confirm('Assign this intake?')) {
        // Implementation would show modal or use simple prompt
        const userId = prompt('Enter user ID to assign to:');
        if (userId) {
            assignIntake(intakeId, userId);
        }
    }
}

function assignIntake(intakeId, userId) {
    fetch('../../api/intake/assign.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            intake_id: intakeId,
            user_id: userId,
            csrf_token: '<?php echo $auth->generateCSRFToken(); ?>'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('Intake assigned successfully', 'success');
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast('Failed to assign intake: ' + data.message, 'error');
        }
    })
    .catch(error => {
        showToast('Error assigning intake', 'error');
        console.error(error);
    });
}

// Export intakes
function exportIntakes() {
    const checkedBoxes = document.querySelectorAll('.intake-checkbox:checked');
    const intakeIds = Array.from(checkedBoxes).map(cb => cb.value);
    
    if (intakeIds.length === 0) {
        showToast('Please select intakes to export', 'warning');
        return;
    }
    
    // Create form and submit for download
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '../../api/intake/export.php';
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'intake_ids';
    input.value = JSON.stringify(intakeIds);
    
    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = '<?php echo $auth->generateCSRFToken(); ?>';
    
    form.appendChild(input);
    form.appendChild(csrfInput);
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

// Bulk operations
function bulkAssign() {
    const checkedBoxes = document.querySelectorAll('.intake-checkbox:checked');
    if (checkedBoxes.length === 0) {
        showToast('Please select intakes to assign', 'warning');
        return;
    }
    
    showToast('Bulk assign feature coming soon', 'info');
}

function bulkStatusUpdate() {
    const checkedBoxes = document.querySelectorAll('.intake-checkbox:checked');
    if (checkedBoxes.length === 0) {
        showToast('Please select intakes to update', 'warning');
        return;
    }
    
    showToast('Bulk status update feature coming soon', 'info');
}

function editIntake(intakeId) {
    window.location.href = 'edit.php?id=' + intakeId;
}

function addNote(intakeId) {
    const note = prompt('Enter note:');
    if (note) {
        showToast('Note functionality coming soon', 'info');
    }
}

function duplicateIntake(intakeId) {
    if (confirm('Create a duplicate of this intake?')) {
        showToast('Duplicate functionality coming soon', 'info');
    }
}
</script>
";

include '../../includes/footer.php';
?>