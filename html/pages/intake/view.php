<?php
/**
 * Intake Detail View
 * Comprehensive intake information with workflow management
 */

define('LEGAL_INTAKE_SYSTEM', true);
session_start();

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/document_processor.php';

$auth = new AuthSystem();
$auth->requireAuth(ROLE_INTAKE_SPECIALIST);

$intake_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$intake_id) {
    header('Location: list.php');
    exit();
}

$db = new Database();
$conn = $db->getConnection();

// Get intake with client information
$sql = "SELECT if.*, c.*, u.first_name as assigned_first_name, u.last_name as assigned_last_name,
               cr.first_name as creator_first_name, cr.last_name as creator_last_name
        FROM intake_forms if
        LEFT JOIN clients c ON if.client_id = c.id
        LEFT JOIN users u ON if.assigned_to = u.id
        LEFT JOIN users cr ON if.created_by = cr.id
        WHERE if.id = ? AND if.firm_id = ?";

$stmt = $conn->prepare($sql);
$stmt->execute([$intake_id, $_SESSION['firm_id']]);
$intake = $stmt->fetch();

if (!$intake) {
    $_SESSION['flash_message'] = 'Intake not found or access denied.';
    $_SESSION['flash_type'] = 'error';
    header('Location: list.php');
    exit();
}

// Decrypt client data
$client_data = [
    'first_name' => $db->decrypt($intake['first_name_encrypted']),
    'last_name' => $db->decrypt($intake['last_name_encrypted']),
    'email' => $db->decrypt($intake['email_encrypted']),
    'phone' => $db->decrypt($intake['phone_encrypted']),
    'ssn' => $db->decrypt($intake['ssn_encrypted']),
    'date_of_birth' => $db->decrypt($intake['date_of_birth_encrypted']),
    'address' => $db->decrypt($intake['address_encrypted']),
    'emergency_contact' => $db->decrypt($intake['emergency_contact_encrypted'])
];

// Get documents
$processor = new DocumentProcessor();
$documents = $processor->getDocumentsByIntake($intake_id);

// Get status history
$history_sql = "SELECT ish.*, u.first_name, u.last_name
                FROM intake_status_history ish
                LEFT JOIN users u ON ish.changed_by = u.id
                WHERE ish.intake_id = ?
                ORDER BY ish.created_at DESC";
$stmt = $conn->prepare($history_sql);
$stmt->execute([$intake_id]);
$status_history = $stmt->fetchAll();

$page_title = 'Intake Details - ' . $intake['intake_number'];
$breadcrumbs = [
    ['title' => 'Intake Management', 'url' => 'list.php'],
    ['title' => 'Intake Details']
];

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4" id="page-header">
    <div>
        <h1 class="h3 mb-0" id="page-title">
            Intake #<?php echo htmlspecialchars($intake['intake_number']); ?>
        </h1>
        <p class="text-muted" id="page-subtitle">
            <?php echo htmlspecialchars($client_data['first_name'] . ' ' . $client_data['last_name']); ?>
            • Created <?php echo format_date($intake['created_at']); ?>
        </p>
    </div>
    <div class="d-flex gap-2" id="page-actions">
        <div class="dropdown">
            <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-gear me-2"></i>
                Actions
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="../documents/upload.php?intake_id=<?php echo $intake_id; ?>">
                    <i class="bi bi-upload me-2"></i>Upload Documents
                </a></li>
                <li><a class="dropdown-item" href="#" onclick="addNote()">
                    <i class="bi bi-chat-text me-2"></i>Add Note
                </a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="edit.php?id=<?php echo $intake_id; ?>">
                    <i class="bi bi-pencil me-2"></i>Edit Intake
                </a></li>
                <li><a class="dropdown-item" href="#" onclick="duplicateIntake()">
                    <i class="bi bi-files me-2"></i>Duplicate
                </a></li>
            </ul>
        </div>
        
        <a href="list.php" class="btn btn-outline-secondary" id="back-to-list">
            <i class="bi bi-arrow-left me-2"></i>
            Back to List
        </a>
    </div>
</div>

<!-- Status and Priority Row -->
<div class="row mb-4" id="status-priority-row">
    <div class="col-md-6">
        <div class="card h-100" id="status-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Current Status</h6>
                        <div class="mb-2">
                            <?php echo get_status_badge($intake['status']); ?>
                        </div>
                        <?php if ($intake['assigned_to']): ?>
                        <small class="text-muted">
                            Assigned to: <?php echo htmlspecialchars($intake['assigned_first_name'] . ' ' . $intake['assigned_last_name']); ?>
                        </small>
                        <?php else: ?>
                        <small class="text-warning">Unassigned</small>
                        <?php endif; ?>
                    </div>
                    <?php if ($auth->hasPermission(ROLE_PARALEGAL)): ?>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                data-bs-toggle="modal" data-bs-target="#statusModal">
                            <i class="bi bi-arrow-repeat"></i>
                            Update
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card h-100" id="priority-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Priority Level</h6>
                        <div class="mb-2">
                            <?php echo get_priority_badge($intake['priority']); ?>
                        </div>
                        <small class="text-muted">
                            Source: <?php echo ucfirst($intake['source'] ?? 'Unknown'); ?>
                        </small>
                    </div>
                    <?php if ($auth->hasPermission(ROLE_PARALEGAL)): ?>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-warning" 
                                onclick="updatePriority()">
                            <i class="bi bi-exclamation-triangle"></i>
                            Change
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content Tabs -->
<div class="card" id="main-content-card">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs" id="main-tabs">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#overview-tab">
                    <i class="bi bi-info-circle me-1"></i>
                    Overview
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#client-tab">
                    <i class="bi bi-person me-1"></i>
                    Client Info
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#incident-tab">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Incident Details
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#documents-tab">
                    <i class="bi bi-file-earmark-text me-1"></i>
                    Documents
                    <?php if (count($documents) > 0): ?>
                    <span class="badge bg-primary ms-1"><?php echo count($documents); ?></span>
                    <?php endif; ?>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#history-tab">
                    <i class="bi bi-clock-history me-1"></i>
                    History
                </button>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content">
            <!-- Overview Tab -->
            <div class="tab-pane fade show active" id="overview-tab">
                <div class="row">
                    <div class="col-md-8">
                        <h5>Incident Summary</h5>
                        <div class="mb-3">
                            <strong>Date:</strong> <?php echo $intake['incident_date'] ? format_date($intake['incident_date']) : 'Not specified'; ?>
                        </div>
                        <div class="mb-3">
                            <strong>Location:</strong> <?php echo htmlspecialchars($intake['incident_location'] ?: 'Not specified'); ?>
                        </div>
                        <div class="mb-3">
                            <strong>Description:</strong>
                            <div class="mt-1 p-3 bg-light rounded">
                                <?php echo nl2br(htmlspecialchars($intake['incident_description'] ?: 'No description provided')); ?>
                            </div>
                        </div>
                        
                        <?php if ($intake['injury_description']): ?>
                        <div class="mb-3">
                            <strong>Injuries:</strong>
                            <div class="mt-1 p-3 bg-light rounded">
                                <?php echo nl2br(htmlspecialchars($intake['injury_description'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-md-4">
                        <h5>Quick Facts</h5>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between">
                                <span>Medical Treatment</span>
                                <?php if ($intake['medical_treatment']): ?>
                                <span class="badge bg-success">Yes</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">No</span>
                                <?php endif; ?>
                            </div>
                            <div class="list-group-item d-flex justify-content-between">
                                <span>Police Report</span>
                                <?php if ($intake['police_report']): ?>
                                <span class="badge bg-success">Yes</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">No</span>
                                <?php endif; ?>
                            </div>
                            <div class="list-group-item d-flex justify-content-between">
                                <span>Insurance Claim</span>
                                <?php if ($intake['insurance_claim']): ?>
                                <span class="badge bg-success">Yes</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">No</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($intake['estimated_damages']): ?>
                            <div class="list-group-item d-flex justify-content-between">
                                <span>Est. Damages</span>
                                <span class="fw-bold"><?php echo format_currency($intake['estimated_damages']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Client Info Tab -->
            <div class="tab-pane fade" id="client-tab">
                <div class="row">
                    <div class="col-md-6">
                        <h5>Personal Information</h5>
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <div class="form-control-plaintext">
                                <?php echo htmlspecialchars($client_data['first_name'] . ' ' . $client_data['last_name']); ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Date of Birth</label>
                            <div class="form-control-plaintext">
                                <?php echo $client_data['date_of_birth'] ? format_date($client_data['date_of_birth']) : 'Not provided'; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Social Security Number</label>
                            <div class="form-control-plaintext">
                                <?php if ($client_data['ssn'] && $auth->hasPermission(ROLE_PARALEGAL)): ?>
                                <span class="text-muted">
                                    <i class="bi bi-shield-lock me-1"></i>
                                    ***-**-<?php echo substr($client_data['ssn'], -4); ?>
                                </span>
                                <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="showFullSSN()">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <?php else: ?>
                                <span class="text-muted">Not provided or insufficient permissions</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Employment Status</label>
                            <div class="form-control-plaintext">
                                <?php echo $intake['employment_status'] ? ucfirst(str_replace('_', ' ', $intake['employment_status'])) : 'Not specified'; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <h5>Contact Information</h5>
                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <div class="form-control-plaintext">
                                <?php if ($client_data['phone']): ?>
                                <a href="tel:<?php echo htmlspecialchars($client_data['phone']); ?>" class="text-decoration-none">
                                    <i class="bi bi-telephone me-1"></i>
                                    <?php echo htmlspecialchars($client_data['phone']); ?>
                                </a>
                                <?php else: ?>
                                <span class="text-muted">Not provided</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <div class="form-control-plaintext">
                                <?php if ($client_data['email']): ?>
                                <a href="mailto:<?php echo htmlspecialchars($client_data['email']); ?>" class="text-decoration-none">
                                    <i class="bi bi-envelope me-1"></i>
                                    <?php echo htmlspecialchars($client_data['email']); ?>
                                </a>
                                <?php else: ?>
                                <span class="text-muted">Not provided</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <div class="form-control-plaintext">
                                <?php if ($client_data['address']): ?>
                                <?php echo nl2br(htmlspecialchars($client_data['address'])); ?>
                                <?php else: ?>
                                <span class="text-muted">Not provided</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Emergency Contact</label>
                            <div class="form-control-plaintext">
                                <?php if ($client_data['emergency_contact']): ?>
                                <?php echo htmlspecialchars($client_data['emergency_contact']); ?>
                                <?php else: ?>
                                <span class="text-muted">Not provided</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Incident Details Tab -->
            <div class="tab-pane fade" id="incident-tab">
                <div class="row">
                    <div class="col-12">
                        <h5>Complete Incident Information</h5>
                        
                        <div class="mb-4">
                            <label class="form-label">Date and Time of Incident</label>
                            <div class="form-control-plaintext">
                                <?php echo $intake['incident_date'] ? format_date($intake['incident_date']) : 'Not specified'; ?>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">Location of Incident</label>
                            <div class="form-control-plaintext">
                                <?php echo htmlspecialchars($intake['incident_location'] ?: 'Not specified'); ?>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">Detailed Description</label>
                            <div class="p-3 bg-light rounded">
                                <?php echo nl2br(htmlspecialchars($intake['incident_description'] ?: 'No description provided')); ?>
                            </div>
                        </div>
                        
                        <?php if ($intake['injury_description']): ?>
                        <div class="mb-4">
                            <label class="form-label">Injury Description</label>
                            <div class="p-3 bg-light rounded">
                                <?php echo nl2br(htmlspecialchars($intake['injury_description'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($intake['estimated_damages']): ?>
                        <div class="mb-4">
                            <label class="form-label">Estimated Damages</label>
                            <div class="form-control-plaintext">
                                <span class="fs-5 fw-bold text-success">
                                    <?php echo format_currency($intake['estimated_damages']); ?>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Documents Tab -->
            <div class="tab-pane fade" id="documents-tab">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5>Documents (<?php echo count($documents); ?>)</h5>
                    <a href="../documents/upload.php?intake_id=<?php echo $intake_id; ?>" class="btn btn-primary">
                        <i class="bi bi-upload me-2"></i>
                        Upload Documents
                    </a>
                </div>
                
                <?php if (empty($documents)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-file-earmark text-muted fs-1"></i>
                    <p class="text-muted mt-2">No documents uploaded yet</p>
                    <a href="../documents/upload.php?intake_id=<?php echo $intake_id; ?>" class="btn btn-outline-primary">
                        Upload First Document
                    </a>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Document</th>
                                <th>Type</th>
                                <th>OCR Status</th>
                                <th>Uploaded</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documents as $doc): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-file-earmark-text text-primary me-2"></i>
                                        <div>
                                            <div class="fw-medium"><?php echo htmlspecialchars($doc['original_filename']); ?></div>
                                            <small class="text-muted"><?php echo number_format($doc['file_size'] / 1024, 1); ?> KB</small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo ucfirst(str_replace('_', ' ', $doc['document_type'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $status_colors = [
                                        'completed' => 'success',
                                        'manual_review' => 'warning',
                                        'failed' => 'danger',
                                        'processing' => 'info',
                                        'pending' => 'secondary'
                                    ];
                                    $color = $status_colors[$doc['ocr_status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $color; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $doc['ocr_status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?php echo format_date($doc['created_at']); ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($doc['ocr_text']): ?>
                                        <button type="button" class="btn btn-outline-primary" 
                                                onclick="viewDocument(<?php echo $doc['id']; ?>)">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($auth->hasPermission(ROLE_PARALEGAL)): ?>
                                        <a href="../documents/review.php#doc-<?php echo $doc['id']; ?>" 
                                           class="btn btn-outline-warning">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- History Tab -->
            <div class="tab-pane fade" id="history-tab">
                <h5>Status History</h5>
                <div class="timeline">
                    <?php foreach ($status_history as $history): ?>
                    <div class="timeline-item mb-3">
                        <div class="d-flex">
                            <div class="flex-shrink-0">
                                <div class="timeline-marker bg-primary"></div>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <div class="timeline-content">
                                    <div class="d-flex justify-content-between">
                                        <strong>Status changed to: <?php echo get_status_badge($history['new_status']); ?></strong>
                                        <small class="text-muted"><?php echo format_date($history['created_at']); ?></small>
                                    </div>
                                    <?php if ($history['reason']): ?>
                                    <div class="text-muted mt-1"><?php echo htmlspecialchars($history['reason']); ?></div>
                                    <?php endif; ?>
                                    <small class="text-muted">
                                        by <?php echo htmlspecialchars($history['first_name'] . ' ' . $history['last_name']); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="status-form">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="new-status" class="form-label">New Status</label>
                        <select class="form-select" id="new-status" name="status" required>
                            <option value="">Select status...</option>
                            <option value="<?php echo STATUS_NEW_INTAKE; ?>">New Intake</option>
                            <option value="<?php echo STATUS_DOCUMENTS_PENDING; ?>">Documents Pending</option>
                            <option value="<?php echo STATUS_UNDER_REVIEW; ?>">Under Review</option>
                            <option value="<?php echo STATUS_ADDITIONAL_INFO_NEEDED; ?>">Additional Info Needed</option>
                            <option value="<?php echo STATUS_ATTORNEY_REVIEW; ?>">Attorney Review</option>
                            <option value="<?php echo STATUS_ACCEPTED; ?>">Accepted</option>
                            <option value="<?php echo STATUS_DECLINED; ?>">Declined</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="status-reason" class="form-label">Reason for Change</label>
                        <textarea class="form-control" id="status-reason" name="reason" rows="3" 
                                  placeholder="Optional reason for status change..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.timeline-marker {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    margin-top: 6px;
}

.timeline-content {
    padding: 1rem;
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
    background: #f8f9fa;
}
</style>

<?php
$additional_js = "
<script>
// Status form submission
document.getElementById('status-form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('intake_id', {$intake_id});
    formData.append('csrf_token', '{$auth->generateCSRFToken()}');
    
    const submitBtn = this.querySelector('button[type=\"submit\"]');
    const originalContent = showLoading(submitBtn);
    
    fetch('../../api/intake/update_status.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        hideLoading(submitBtn, originalContent);
        
        if (data.success) {
            showToast('Status updated successfully', 'success');
            bootstrap.Modal.getInstance(document.getElementById('statusModal')).hide();
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast('Failed to update status: ' + data.message, 'error');
        }
    })
    .catch(error => {
        hideLoading(submitBtn, originalContent);
        showToast('Error updating status', 'error');
        console.error(error);
    });
});

function updatePriority() {
    const newPriority = prompt('Select priority (urgent, high, normal, low):');
    if (newPriority && ['urgent', 'high', 'normal', 'low'].includes(newPriority)) {
        fetch('../../api/intake/update_priority.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                intake_id: {$intake_id},
                priority: newPriority,
                csrf_token: '{$auth->generateCSRFToken()}'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Priority updated successfully', 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showToast('Failed to update priority', 'error');
            }
        });
    }
}

function viewDocument(documentId) {
    // Open document in modal or new window
    window.open('../../api/documents/view.php?id=' + documentId, '_blank');
}

function showFullSSN() {
    if (confirm('Are you sure you want to view the full SSN? This action will be logged.')) {
        // Implementation would show full SSN with audit logging
        showToast('Full SSN display feature would be implemented here', 'info');
    }
}

function addNote() {
    const note = prompt('Enter note:');
    if (note) {
        showToast('Note functionality coming soon', 'info');
    }
}

function duplicateIntake() {
    if (confirm('Create a duplicate of this intake?')) {
        showToast('Duplicate functionality coming soon', 'info');
    }
}
</script>
";

include '../../includes/footer.php';
?>