<?php
/**
 * Document Upload Interface
 * Drag-and-drop file upload with progress tracking
 */

define('LEGAL_INTAKE_SYSTEM', true);
session_start();

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/document_processor.php';

$auth = new AuthSystem();
$auth->requireAuth(ROLE_INTAKE_SPECIALIST);

$page_title = 'Document Upload';
$breadcrumbs = [
    ['title' => 'Documents', 'url' => 'review.php'],
    ['title' => 'Upload Documents']
];

$error_message = '';
$success_message = '';

// Get intake ID from URL
$intake_id = isset($_GET['intake_id']) ? intval($_GET['intake_id']) : 0;

if (!$intake_id) {
    header('Location: ../intake/list.php');
    exit();
}

// Verify intake exists and user has access
$db = new Database();
$conn = $db->getConnection();

$sql = "SELECT id, intake_number, status FROM intake_forms WHERE id = ? AND firm_id = ?";
$stmt = $conn->prepare($sql);
$stmt->execute([$intake_id, $_SESSION['firm_id']]);
$intake = $stmt->fetch();

if (!$intake) {
    $_SESSION['flash_message'] = 'Intake not found or access denied.';
    $_SESSION['flash_type'] = 'error';
    header('Location: ../intake/list.php');
    exit();
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!$auth->verifyCSRFToken($csrf_token)) {
        $error_message = 'Security token mismatch. Please try again.';
    } elseif (empty($_FILES['documents']['name'][0])) {
        $error_message = 'Please select at least one file to upload.';
    } else {
        $processor = new DocumentProcessor();
        $upload_results = [];
        $success_count = 0;
        $error_count = 0;
        
        // Process multiple files
        for ($i = 0; $i < count($_FILES['documents']['name']); $i++) {
            if ($_FILES['documents']['error'][$i] === UPLOAD_ERR_OK) {
                $file = [
                    'name' => $_FILES['documents']['name'][$i],
                    'type' => $_FILES['documents']['type'][$i],
                    'tmp_name' => $_FILES['documents']['tmp_name'][$i],
                    'error' => $_FILES['documents']['error'][$i],
                    'size' => $_FILES['documents']['size'][$i]
                ];
                
                $document_type = $_POST['document_type'][$i] ?? DOC_TYPE_OTHER;
                
                $result = $processor->uploadDocument($file, $intake_id, $_SESSION['user_id'], $document_type);
                $upload_results[] = $result;
                
                if ($result['success']) {
                    $success_count++;
                    // Trigger OCR processing
                    $processor->processOCR($result['document_id']);
                } else {
                    $error_count++;
                }
            }
        }
        
        if ($success_count > 0) {
            $success_message = "Successfully uploaded {$success_count} document(s).";
            if ($error_count > 0) {
                $success_message .= " {$error_count} upload(s) failed.";
            }
        } else {
            $error_message = "All uploads failed. Please check file types and sizes.";
        }
    }
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4" id="page-header">
    <div>
        <h1 class="h3 mb-0" id="page-title">Upload Documents</h1>
        <p class="text-muted" id="page-subtitle">
            Upload documents for Intake #<?php echo htmlspecialchars($intake['intake_number']); ?>
        </p>
    </div>
    <div>
        <a href="../intake/view.php?id=<?php echo $intake_id; ?>" class="btn btn-outline-secondary" id="back-to-intake">
            <i class="bi bi-arrow-left me-2"></i>
            Back to Intake
        </a>
    </div>
</div>

<!-- Error/Success Messages -->
<?php if ($error_message): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert" id="error-alert">
    <i class="bi bi-exclamation-triangle me-2"></i>
    <?php echo htmlspecialchars($error_message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($success_message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert" id="success-alert">
    <i class="bi bi-check-circle me-2"></i>
    <?php echo htmlspecialchars($success_message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Upload Instructions -->
<div class="card mb-4" id="upload-instructions">
    <div class="card-body">
        <h5 class="card-title">
            <i class="bi bi-info-circle text-primary me-2"></i>
            Upload Instructions
        </h5>
        <div class="row">
            <div class="col-md-6">
                <h6>Accepted File Types</h6>
                <ul class="list-unstyled">
                    <li><i class="bi bi-file-earmark-pdf text-danger me-2"></i>PDF Documents</li>
                    <li><i class="bi bi-file-earmark-image text-info me-2"></i>Images (JPG, PNG, TIFF)</li>
                    <li><i class="bi bi-file-earmark-word text-primary me-2"></i>Word Documents</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h6>Important Notes</h6>
                <ul class="list-unstyled">
                    <li><i class="bi bi-shield-check text-success me-2"></i>Files are encrypted and HIPAA compliant</li>
                    <li><i class="bi bi-cpu text-warning me-2"></i>OCR processing happens automatically</li>
                    <li><i class="bi bi-file-earmark-check text-info me-2"></i>Maximum file size: <?php echo ini_get('upload_max_filesize'); ?></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Upload Form -->
<form method="POST" action="" enctype="multipart/form-data" id="upload-form">
    <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCSRFToken(); ?>">
    
    <div class="card" id="upload-card">
        <div class="card-header">
            <h5 class="card-title mb-0">
                <i class="bi bi-cloud-upload me-2"></i>
                Document Upload
            </h5>
        </div>
        <div class="card-body">
            <!-- Drag and Drop Area -->
            <div class="upload-area border-dashed p-5 text-center mb-4" id="upload-area">
                <div class="upload-icon mb-3">
                    <i class="bi bi-cloud-upload text-muted" style="font-size: 3rem;"></i>
                </div>
                <h5 class="text-muted">Drag and drop files here</h5>
                <p class="text-muted mb-3">or click to select files</p>
                <input type="file" class="form-control d-none" id="file-input" name="documents[]" 
                       multiple accept=".pdf,.jpg,.jpeg,.png,.tiff,.doc,.docx">
                <button type="button" class="btn btn-primary" id="select-files-btn">
                    <i class="bi bi-folder2-open me-2"></i>
                    Select Files
                </button>
            </div>
            
            <!-- File List -->
            <div id="file-list" class="mb-3" style="display: none;">
                <h6>Selected Files</h6>
                <div class="table-responsive">
                    <table class="table table-sm" id="files-table">
                        <thead>
                            <tr>
                                <th>File Name</th>
                                <th>Size</th>
                                <th>Document Type</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="files-tbody">
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Upload Progress -->
            <div id="upload-progress" style="display: none;">
                <h6>Upload Progress</h6>
                <div class="progress mb-2">
                    <div class="progress-bar" role="progressbar" style="width: 0%" id="progress-bar">
                        0%
                    </div>
                </div>
                <div id="upload-status" class="text-muted small"></div>
            </div>
        </div>
        <div class="card-footer">
            <div class="d-flex justify-content-between align-items-center">
                <div id="file-count" class="text-muted">
                    No files selected
                </div>
                <div>
                    <button type="button" class="btn btn-outline-secondary me-2" id="clear-files-btn" 
                            style="display: none;">
                        <i class="bi bi-x-circle me-2"></i>
                        Clear All
                    </button>
                    <button type="submit" class="btn btn-success" id="upload-btn" 
                            style="display: none;">
                        <i class="bi bi-cloud-upload me-2"></i>
                        Upload Documents
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<style>
.border-dashed {
    border: 2px dashed #dee2e6 !important;
    border-radius: 0.375rem;
    transition: border-color 0.15s ease-in-out;
}

.upload-area {
    cursor: pointer;
    transition: all 0.15s ease-in-out;
}

.upload-area:hover {
    border-color: #0d6efd !important;
    background-color: #f8f9fa;
}

.upload-area.dragover {
    border-color: #0d6efd !important;
    background-color: #e7f1ff;
}

.file-item {
    padding: 0.75rem;
    border: 1px solid #dee2e6;
    border-radius: 0.375rem;
    margin-bottom: 0.5rem;
    background: #f8f9fa;
}

.document-type-select {
    max-width: 200px;
}
</style>

<?php
$additional_js = "
<script>
let selectedFiles = [];

// File input and drag-drop handling
const fileInput = document.getElementById('file-input');
const uploadArea = document.getElementById('upload-area');
const fileList = document.getElementById('file-list');
const filesTable = document.getElementById('files-tbody');
const fileCount = document.getElementById('file-count');
const uploadBtn = document.getElementById('upload-btn');
const clearBtn = document.getElementById('clear-files-btn');

// Drag and drop events
uploadArea.addEventListener('click', () => fileInput.click());

uploadArea.addEventListener('dragover', (e) => {
    e.preventDefault();
    uploadArea.classList.add('dragover');
});

uploadArea.addEventListener('dragleave', () => {
    uploadArea.classList.remove('dragover');
});

uploadArea.addEventListener('drop', (e) => {
    e.preventDefault();
    uploadArea.classList.remove('dragover');
    handleFiles(e.dataTransfer.files);
});

// File selection
document.getElementById('select-files-btn').addEventListener('click', () => {
    fileInput.click();
});

fileInput.addEventListener('change', (e) => {
    handleFiles(e.target.files);
});

// Handle selected files
function handleFiles(files) {
    for (let file of files) {
        if (isValidFile(file)) {
            selectedFiles.push(file);
        } else {
            showToast('File \"' + file.name + '\" is not a valid type or exceeds size limit.', 'error');
        }
    }
    updateFileList();
}

// Validate file
function isValidFile(file) {
    const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/tiff', 
                         'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    const maxSize = <?php echo MAX_FILE_SIZE; ?>;
    
    return allowedTypes.includes(file.type) && file.size <= maxSize;
}

// Update file list display
function updateFileList() {
    if (selectedFiles.length === 0) {
        fileList.style.display = 'none';
        fileCount.textContent = 'No files selected';
        uploadBtn.style.display = 'none';
        clearBtn.style.display = 'none';
        return;
    }
    
    fileList.style.display = 'block';
    uploadBtn.style.display = 'inline-block';
    clearBtn.style.display = 'inline-block';
    fileCount.textContent = selectedFiles.length + ' file(s) selected';
    
    // Clear existing rows
    filesTable.innerHTML = '';
    
    // Add file rows
    selectedFiles.forEach((file, index) => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>
                <i class=\"bi bi-file-earmark-text me-2\"></i>
                ${file.name}
            </td>
            <td>${formatFileSize(file.size)}</td>
            <td>
                <select class=\"form-select form-select-sm document-type-select\" name=\"document_type[]\" data-index=\"${index}\">
                    <option value=\"other\">Other</option>
                    <option value=\"medical_record\">Medical Record</option>
                    <option value=\"police_report\">Police Report</option>
                    <option value=\"insurance_document\">Insurance Document</option>
                    <option value=\"employment_record\">Employment Record</option>
                    <option value=\"correspondence\">Correspondence</option>
                    <option value=\"bill_invoice\">Bill/Invoice</option>
                    <option value=\"legal_document\">Legal Document</option>
                </select>
            </td>
            <td>
                <button type=\"button\" class=\"btn btn-sm btn-outline-danger\" onclick=\"removeFile(${index})\">
                    <i class=\"bi bi-trash\"></i>
                </button>
            </td>
        `;
        filesTable.appendChild(row);
    });
}

// Remove file from list
function removeFile(index) {
    selectedFiles.splice(index, 1);
    updateFileList();
}

// Clear all files
clearBtn.addEventListener('click', () => {
    selectedFiles = [];
    fileInput.value = '';
    updateFileList();
});

// Format file size
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Form submission with progress
document.getElementById('upload-form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    if (selectedFiles.length === 0) {
        showToast('Please select at least one file to upload.', 'error');
        return;
    }
    
    // Show progress
    document.getElementById('upload-progress').style.display = 'block';
    uploadBtn.disabled = true;
    uploadBtn.innerHTML = '<span class=\"spinner-border spinner-border-sm me-2\"></span>Uploading...';
    
    // Create FormData with selected files
    const formData = new FormData();
    formData.append('csrf_token', document.querySelector('input[name=\"csrf_token\"]').value);
    
    selectedFiles.forEach((file, index) => {
        formData.append('documents[]', file);
        const typeSelect = document.querySelector(`select[data-index=\"${index}\"]`);
        formData.append('document_type[]', typeSelect.value);
    });
    
    // Upload with progress tracking
    const xhr = new XMLHttpRequest();
    
    xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
            const percentComplete = (e.loaded / e.total) * 100;
            const progressBar = document.getElementById('progress-bar');
            progressBar.style.width = percentComplete + '%';
            progressBar.textContent = Math.round(percentComplete) + '%';
            
            document.getElementById('upload-status').textContent = 
                `Uploading ${Math.round(percentComplete)}% complete...`;
        }
    });
    
    xhr.addEventListener('load', () => {
        if (xhr.status === 200) {
            showToast('Documents uploaded successfully!', 'success');
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else {
            showToast('Upload failed. Please try again.', 'error');
            resetUploadForm();
        }
    });
    
    xhr.addEventListener('error', () => {
        showToast('Upload failed. Please check your connection and try again.', 'error');
        resetUploadForm();
    });
    
    xhr.open('POST', '', true);
    xhr.send(formData);
});

function resetUploadForm() {
    document.getElementById('upload-progress').style.display = 'none';
    uploadBtn.disabled = false;
    uploadBtn.innerHTML = '<i class=\"bi bi-cloud-upload me-2\"></i>Upload Documents';
}
</script>
";

include '../../includes/footer.php';
?>