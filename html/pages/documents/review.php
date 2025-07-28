<?php
/**
 * Document Review Interface
 * OCR review and correction for paralegals
 */

define('LEGAL_INTAKE_SYSTEM', true);
session_start();

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/document_processor.php';

$auth = new AuthSystem();
$auth->requireAuth(ROLE_PARALEGAL);

$page_title = 'Document Review';
$breadcrumbs = [
    ['title' => 'Documents', 'url' => 'review.php'],
    ['title' => 'Review Queue']
];

// Get documents that need review
$db = new Database();
$conn = $db->getConnection();

$sql = "SELECT d.*, if.intake_number, c.first_name_encrypted, c.last_name_encrypted,
               dc.ocr_text, dc.extracted_data, dc.confidence_scores
        FROM documents d
        JOIN intake_forms if ON d.intake_id = if.id
        JOIN clients c ON if.client_id = c.id
        LEFT JOIN document_content dc ON d.id = dc.document_id
        WHERE if.firm_id = ? AND d.ocr_status IN ('completed', 'manual_review')
        ORDER BY d.created_at DESC
        LIMIT 50";

$stmt = $conn->prepare($sql);
$stmt->execute([$_SESSION['firm_id']]);
$documents = $stmt->fetchAll();

// Decrypt client names for display
foreach ($documents as &$doc) {
    $doc['client_name'] = $db->decrypt($doc['first_name_encrypted']) . ' ' . $db->decrypt($doc['last_name_encrypted']);
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4" id="page-header">
    <div>
        <h1 class="h3 mb-0" id="page-title">Document Review</h1>
        <p class="text-muted" id="page-subtitle">Review OCR results and extracted data</p>
    </div>
    <div class="d-flex gap-2" id="page-actions">
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#filterModal" id="filter-btn">
            <i class="bi bi-funnel me-2"></i>
            Filters
        </button>
        <a href="ocr_queue.php" class="btn btn-outline-info" id="ocr-queue-btn">
            <i class="bi bi-cpu me-2"></i>
            OCR Queue
        </a>
    </div>
</div>

<!-- Review Statistics -->
<div class="row mb-4" id="review-stats">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm h-100" id="total-documents-card">
            <div class="card-body text-center">
                <div class="h4 text-primary mb-2"><?php echo count($documents); ?></div>
                <div class="text-muted">Documents to Review</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm h-100" id="high-confidence-card">
            <div class="card-body text-center">
                <div class="h4 text-success mb-2">
                    <?php 
                    $high_confidence = array_filter($documents, function($doc) {
                        $scores = json_decode($doc['confidence_scores'], true);
                        return $scores && isset($scores['overall']) && $scores['overall'] > 85;
                    });
                    echo count($high_confidence);
                    ?>
                </div>
                <div class="text-muted">High Confidence</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm h-100" id="needs-review-card">
            <div class="card-body text-center">
                <div class="h4 text-warning mb-2">
                    <?php 
                    $needs_review = array_filter($documents, function($doc) {
                        $scores = json_decode($doc['confidence_scores'], true);
                        return $scores && isset($scores['overall']) && $scores['overall'] <= 85;
                    });
                    echo count($needs_review);
                    ?>
                </div>
                <div class="text-muted">Needs Review</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="card border-0 shadow-sm h-100" id="processed-today-card">
            <div class="card-body text-center">
                <div class="h4 text-info mb-2">
                    <?php 
                    $today = array_filter($documents, function($doc) {
                        return date('Y-m-d', strtotime($doc['processed_at'])) === date('Y-m-d');
                    });
                    echo count($today);
                    ?>
                </div>
                <div class="text-muted">Processed Today</div>
            </div>
        </div>
    </div>
</div>

<!-- Documents List -->
<div class="card" id="documents-list-card">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="bi bi-file-earmark-text me-2"></i>
            Documents for Review
        </h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($documents)): ?>
        <div class="text-center py-5" id="no-documents-message">
            <i class="bi bi-inbox text-muted fs-1"></i>
            <p class="text-muted mt-2">No documents to review</p>
        </div>
        <?php else: ?>
        <div class="table-responsive" id="documents-table">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Document</th>
                        <th>Client</th>
                        <th>Intake</th>
                        <th>Type</th>
                        <th>OCR Status</th>
                        <th>Confidence</th>
                        <th>Processed</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documents as $doc): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center">
                                <i class="bi bi-file-earmark-text text-primary me-2 fs-5"></i>
                                <div>
                                    <div class="fw-medium"><?php echo htmlspecialchars($doc['original_filename']); ?></div>
                                    <small class="text-muted"><?php echo number_format($doc['file_size'] / 1024, 1); ?> KB</small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="fw-medium"><?php echo htmlspecialchars($doc['client_name']); ?></div>
                        </td>
                        <td>
                            <a href="../intake/view.php?id=<?php echo $doc['intake_id']; ?>" 
                               class="text-decoration-none">
                                <?php echo htmlspecialchars($doc['intake_number']); ?>
                            </a>
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
                            <?php if ($doc['confidence_scores']): ?>
                                <?php 
                                $scores = json_decode($doc['confidence_scores'], true);
                                $overall = $scores['overall'] ?? 0;
                                $color = $overall > 85 ? 'success' : ($overall > 70 ? 'warning' : 'danger');
                                ?>
                                <div class="d-flex align-items-center">
                                    <div class="progress me-2" style="width: 60px; height: 8px;">
                                        <div class="progress-bar bg-<?php echo $color; ?>" 
                                             style="width: <?php echo $overall; ?>%"></div>
                                    </div>
                                    <small class="text-<?php echo $color; ?>"><?php echo number_format($overall, 1); ?>%</small>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small class="text-muted">
                                <?php echo $doc['processed_at'] ? format_date($doc['processed_at']) : 'Not processed'; ?>
                            </small>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary" 
                                        onclick="reviewDocument(<?php echo $doc['id']; ?>)">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button type="button" class="btn btn-outline-warning" 
                                        onclick="editOCR(<?php echo $doc['id']; ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-outline-success" 
                                        onclick="approveDocument(<?php echo $doc['id']; ?>)">
                                    <i class="bi bi-check"></i>
                                </button>
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

<!-- Document Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-text me-2"></i>
                    Document Review
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <!-- Document Preview -->
                    <div class="col-md-6">
                        <h6>Document Preview</h6>
                        <div class="border rounded p-3 bg-light" style="height: 400px; overflow-y: auto;" id="document-preview">
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-file-earmark fs-1"></i>
                                <p>Select a document to preview</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- OCR Text -->
                    <div class="col-md-6">
                        <h6>OCR Extracted Text</h6>
                        <textarea class="form-control" id="ocr-text" rows="15" readonly></textarea>
                    </div>
                </div>
                
                <!-- Extracted Data -->
                <div class="mt-4">
                    <h6>Extracted Data</h6>
                    <div class="row" id="extracted-data">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">Dates Found</h6>
                                    <div id="extracted-dates" class="text-muted">No dates extracted</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">Amounts Found</h6>
                                    <div id="extracted-amounts" class="text-muted">No amounts extracted</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-warning" id="edit-ocr-btn">
                    <i class="bi bi-pencil me-2"></i>
                    Edit OCR
                </button>
                <button type="button" class="btn btn-success" id="approve-document-btn">
                    <i class="bi bi-check-circle me-2"></i>
                    Approve
                </button>
            </div>
        </div>
    </div>
</div>

<!-- OCR Edit Modal -->
<div class="modal fade" id="ocrEditModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-pencil me-2"></i>
                    Edit OCR Text
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="ocr-edit-form">
                    <input type="hidden" id="edit-document-id" name="document_id">
                    <div class="mb-3">
                        <label for="corrected-text" class="form-label">Corrected Text</label>
                        <textarea class="form-control" id="corrected-text" name="corrected_text" 
                                  rows="15" placeholder="Enter corrected OCR text..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="correction-notes" class="form-label">Correction Notes</label>
                        <textarea class="form-control" id="correction-notes" name="notes" 
                                  rows="3" placeholder="Notes about corrections made..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="save-corrections-btn">
                    <i class="bi bi-save me-2"></i>
                    Save Corrections
                </button>
            </div>
        </div>
    </div>
</div>

<?php
$additional_js = "
<script>
let currentDocumentId = null;

// Review document
function reviewDocument(documentId) {
    currentDocumentId = documentId;
    
    // Show loading
    document.getElementById('document-preview').innerHTML = '<div class=\"text-center py-5\"><div class=\"spinner-border\"></div><p class=\"mt-2\">Loading document...</p></div>';
    document.getElementById('ocr-text').value = 'Loading...';
    
    // Fetch document data
    fetch('../api/documents/get.php?id=' + documentId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                populateReviewModal(data.document);
                new bootstrap.Modal(document.getElementById('reviewModal')).show();
            } else {
                showToast('Failed to load document: ' + data.message, 'error');
            }
        })
        .catch(error => {
            showToast('Error loading document', 'error');
            console.error(error);
        });
}

// Populate review modal
function populateReviewModal(document) {
    // Set document preview
    document.getElementById('document-preview').innerHTML = 
        '<div class=\"text-center py-3\">' +
        '<i class=\"bi bi-file-earmark-text text-primary fs-1\"></i>' +
        '<h6 class=\"mt-2\">' + document.original_filename + '</h6>' +
        '<p class=\"text-muted\">OCR Confidence: ' + (document.ocr_confidence || 0) + '%</p>' +
        '</div>';
    
    // Set OCR text
    document.getElementById('ocr-text').value = document.ocr_text || 'No OCR text available';
    
    // Set extracted data
    if (document.extracted_data) {
        const data = JSON.parse(document.extracted_data);
        
        // Dates
        const datesDiv = document.getElementById('extracted-dates');
        if (data.dates && data.dates.length > 0) {
            datesDiv.innerHTML = data.dates.map(date => '<span class=\"badge bg-info me-1\">' + date + '</span>').join('');
        } else {
            datesDiv.innerHTML = '<span class=\"text-muted\">No dates found</span>';
        }
        
        // Amounts
        const amountsDiv = document.getElementById('extracted-amounts');
        if (data.amounts && data.amounts.length > 0) {
            amountsDiv.innerHTML = data.amounts.map(amount => '<span class=\"badge bg-success me-1\">' + amount + '</span>').join('');
        } else {
            amountsDiv.innerHTML = '<span class=\"text-muted\">No amounts found</span>';
        }
    }
}

// Edit OCR text
function editOCR(documentId) {
    currentDocumentId = documentId;
    
    // Get current OCR text
    fetch('../api/documents/get.php?id=' + documentId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit-document-id').value = documentId;
                document.getElementById('corrected-text').value = data.document.ocr_text || '';
                new bootstrap.Modal(document.getElementById('ocrEditModal')).show();
            } else {
                showToast('Failed to load document for editing', 'error');
            }
        });
}

// Edit OCR from review modal
document.getElementById('edit-ocr-btn').addEventListener('click', function() {
    if (currentDocumentId) {
        bootstrap.Modal.getInstance(document.getElementById('reviewModal')).hide();
        setTimeout(() => editOCR(currentDocumentId), 300);
    }
});

// Save OCR corrections
document.getElementById('save-corrections-btn').addEventListener('click', function() {
    const form = document.getElementById('ocr-edit-form');
    const formData = new FormData(form);
    
    const button = this;
    const originalContent = showLoading(button);
    
    fetch('../api/documents/update_ocr.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        hideLoading(button, originalContent);
        
        if (data.success) {
            showToast('OCR corrections saved successfully', 'success');
            bootstrap.Modal.getInstance(document.getElementById('ocrEditModal')).hide();
            setTimeout(() => window.location.reload(), 1000);
        } else {
            showToast('Failed to save corrections: ' + data.message, 'error');
        }
    })
    .catch(error => {
        hideLoading(button, originalContent);
        showToast('Error saving corrections', 'error');
        console.error(error);
    });
});

// Approve document
function approveDocument(documentId) {
    if (confirm('Are you sure you want to approve this document? This will mark the OCR as reviewed and accurate.')) {
        fetch('../api/documents/approve.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                document_id: documentId,
                csrf_token: '<?php echo $auth->generateCSRFToken(); ?>'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Document approved successfully', 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showToast('Failed to approve document: ' + data.message, 'error');
            }
        })
        .catch(error => {
            showToast('Error approving document', 'error');
            console.error(error);
        });
    }
}

// Approve from review modal
document.getElementById('approve-document-btn').addEventListener('click', function() {
    if (currentDocumentId) {
        approveDocument(currentDocumentId);
        bootstrap.Modal.getInstance(document.getElementById('reviewModal')).hide();
    }
});
</script>
";

include '../../includes/footer.php';
?>