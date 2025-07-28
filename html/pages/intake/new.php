<?php
/**
 * New Client Intake Form
 * Multi-step intake process with auto-save and validation
 */

define('LEGAL_INTAKE_SYSTEM', true);
session_start();

require_once '../../config/config.php';
require_once '../../includes/auth.php';

$auth = new AuthSystem();
$auth->requireAuth(ROLE_INTAKE_SPECIALIST);

$page_title = 'New Client Intake';
$breadcrumbs = [
    ['title' => 'Intake Management', 'url' => 'list.php'],
    ['title' => 'New Intake']
];

$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!$auth->verifyCSRFToken($csrf_token)) {
        $error_message = 'Security token mismatch. Please try again.';
    } else {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            // Generate intake number
            $year = date('Y');
            $stmt = $conn->prepare("SELECT COUNT(*) + 1 as next_number FROM intake_forms WHERE firm_id = ? AND YEAR(created_at) = ?");
            $stmt->execute([$_SESSION['firm_id'], $year]);
            $next_number = $stmt->fetch()['next_number'];
            $intake_number = $year . '-' . str_pad($next_number, 6, '0', STR_PAD_LEFT);
            
            // Process client information (encrypt sensitive data)
            $client_data = [
                'first_name' => sanitize_input($_POST['first_name'] ?? ''),
                'last_name' => sanitize_input($_POST['last_name'] ?? ''),
                'email' => sanitize_input($_POST['email'] ?? ''),
                'phone' => sanitize_input($_POST['phone'] ?? ''),
                'ssn' => sanitize_input($_POST['ssn'] ?? ''),
                'date_of_birth' => $_POST['date_of_birth'] ?? '',
                'address' => sanitize_input($_POST['address'] ?? ''),
                'emergency_contact' => sanitize_input($_POST['emergency_contact'] ?? '')
            ];
            
            // Generate client number
            $client_number = 'CL-' . $year . '-' . str_pad($next_number, 4, '0', STR_PAD_LEFT);
            
            $conn->beginTransaction();
            
            // Create client record with encrypted data
            $client_sql = "INSERT INTO clients (firm_id, client_number, first_name_encrypted, last_name_encrypted, 
                          email_encrypted, phone_encrypted, ssn_encrypted, date_of_birth_encrypted, 
                          address_encrypted, emergency_contact_encrypted, created_by) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($client_sql);
            $stmt->execute([
                $_SESSION['firm_id'],
                $client_number,
                $db->encrypt($client_data['first_name']),
                $db->encrypt($client_data['last_name']),
                $db->encrypt($client_data['email']),
                $db->encrypt($client_data['phone']),
                $db->encrypt($client_data['ssn']),
                $db->encrypt($client_data['date_of_birth']),
                $db->encrypt($client_data['address']),
                $db->encrypt($client_data['emergency_contact']),
                $_SESSION['user_id']
            ]);
            
            $client_id = $conn->lastInsertId();
            
            // Create intake form
            $intake_sql = "INSERT INTO intake_forms (firm_id, client_id, intake_number, status, priority, source,
                          incident_date, incident_location, incident_description, injury_description,
                          medical_treatment, police_report, insurance_claim, employment_status,
                          estimated_damages, form_data, created_by)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $form_data = json_encode($_POST);
            
            $stmt = $conn->prepare($intake_sql);
            $stmt->execute([
                $_SESSION['firm_id'],
                $client_id,
                $intake_number,
                STATUS_NEW_INTAKE,
                sanitize_input($_POST['priority'] ?? PRIORITY_NORMAL),
                sanitize_input($_POST['source'] ?? 'website'),
                $_POST['incident_date'] ?? null,
                sanitize_input($_POST['incident_location'] ?? ''),
                sanitize_input($_POST['incident_description'] ?? ''),
                sanitize_input($_POST['injury_description'] ?? ''),
                isset($_POST['medical_treatment']) ? 1 : 0,
                isset($_POST['police_report']) ? 1 : 0,
                isset($_POST['insurance_claim']) ? 1 : 0,
                sanitize_input($_POST['employment_status'] ?? ''),
                floatval($_POST['estimated_damages'] ?? 0),
                $form_data,
                $_SESSION['user_id']
            ]);
            
            $intake_id = $conn->lastInsertId();
            
            // Create initial status history
            $status_sql = "INSERT INTO intake_status_history (intake_id, new_status, changed_by, reason)
                          VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($status_sql);
            $stmt->execute([$intake_id, STATUS_NEW_INTAKE, $_SESSION['user_id'], 'Initial intake created']);
            
            $conn->commit();
            
            $_SESSION['flash_message'] = "Intake {$intake_number} created successfully!";
            $_SESSION['flash_type'] = 'success';
            
            header("Location: view.php?id={$intake_id}");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Intake creation error: " . $e->getMessage());
            $error_message = 'Error creating intake. Please try again.';
        }
    }
}

include '../../includes/header.php';
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4" id="page-header">
    <div>
        <h1 class="h3 mb-0" id="page-title">New Client Intake</h1>
        <p class="text-muted" id="page-subtitle">Create a new client intake record</p>
    </div>
    <div>
        <a href="list.php" class="btn btn-outline-secondary" id="back-to-list">
            <i class="bi bi-arrow-left me-2"></i>
            Back to List
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

<!-- Progress Indicator -->
<div class="card mb-4" id="progress-card">
    <div class="card-body">
        <div class="row">
            <div class="col-md-3 text-center">
                <div class="progress-step active" id="step-1">
                    <div class="step-number">1</div>
                    <div class="step-title">Client Info</div>
                </div>
            </div>
            <div class="col-md-3 text-center">
                <div class="progress-step" id="step-2">
                    <div class="step-number">2</div>
                    <div class="step-title">Incident Details</div>
                </div>
            </div>
            <div class="col-md-3 text-center">
                <div class="progress-step" id="step-3">
                    <div class="step-number">3</div>
                    <div class="step-title">Medical/Legal</div>
                </div>
            </div>
            <div class="col-md-3 text-center">
                <div class="progress-step" id="step-4">
                    <div class="step-number">4</div>
                    <div class="step-title">Review</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Form -->
<form method="POST" action="" id="intake-form" novalidate>
    <input type="hidden" name="csrf_token" value="<?php echo $auth->generateCSRFToken(); ?>">
    
    <!-- Step 1: Client Information -->
    <div class="step-content active" id="step-1-content">
        <div class="card" id="client-info-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-person me-2"></i>
                    Client Information
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="first_name" class="form-label required">First Name</label>
                        <input type="text" class="form-control" id="first_name" name="first_name" required>
                        <div class="invalid-feedback">Please provide a first name.</div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="last_name" class="form-label required">Last Name</label>
                        <input type="text" class="form-control" id="last_name" name="last_name" required>
                        <div class="invalid-feedback">Please provide a last name.</div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email">
                        <div class="invalid-feedback">Please provide a valid email address.</div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="phone" class="form-label required">Phone Number</label>
                        <input type="tel" class="form-control" id="phone" name="phone" required>
                        <div class="invalid-feedback">Please provide a phone number.</div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="date_of_birth" class="form-label">Date of Birth</label>
                        <input type="date" class="form-control" id="date_of_birth" name="date_of_birth">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="ssn" class="form-label">Social Security Number</label>
                        <input type="text" class="form-control" id="ssn" name="ssn" placeholder="XXX-XX-XXXX">
                        <div class="form-text">
                            <i class="bi bi-shield-lock text-success"></i>
                            Encrypted and HIPAA protected
                        </div>
                    </div>
                    
                    <div class="col-12 mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="3"></textarea>
                    </div>
                    
                    <div class="col-12 mb-3">
                        <label for="emergency_contact" class="form-label">Emergency Contact</label>
                        <input type="text" class="form-control" id="emergency_contact" name="emergency_contact" 
                               placeholder="Name and phone number">
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Step 2: Incident Details -->
    <div class="step-content" id="step-2-content">
        <div class="card" id="incident-details-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Incident Details
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="incident_date" class="form-label required">Date of Incident</label>
                        <input type="date" class="form-control" id="incident_date" name="incident_date" required>
                        <div class="invalid-feedback">Please provide the incident date.</div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="priority" class="form-label">Priority Level</label>
                        <select class="form-select" id="priority" name="priority">
                            <option value="<?php echo PRIORITY_NORMAL; ?>">Normal</option>
                            <option value="<?php echo PRIORITY_HIGH; ?>">High</option>
                            <option value="<?php echo PRIORITY_URGENT; ?>">Urgent</option>
                            <option value="<?php echo PRIORITY_LOW; ?>">Low</option>
                        </select>
                    </div>
                    
                    <div class="col-12 mb-3">
                        <label for="incident_location" class="form-label required">Location of Incident</label>
                        <input type="text" class="form-control" id="incident_location" name="incident_location" 
                               placeholder="Street address, city, state" required>
                        <div class="invalid-feedback">Please provide the incident location.</div>
                    </div>
                    
                    <div class="col-12 mb-3">
                        <label for="incident_description" class="form-label required">Description of Incident</label>
                        <textarea class="form-control" id="incident_description" name="incident_description" 
                                  rows="4" placeholder="Please describe what happened..." required></textarea>
                        <div class="invalid-feedback">Please provide a description of the incident.</div>
                    </div>
                    
                    <div class="col-12 mb-3">
                        <label for="injury_description" class="form-label">Description of Injuries</label>
                        <textarea class="form-control" id="injury_description" name="injury_description" 
                                  rows="3" placeholder="Describe any injuries sustained..."></textarea>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="estimated_damages" class="form-label">Estimated Damages ($)</label>
                        <input type="number" class="form-control" id="estimated_damages" name="estimated_damages" 
                               min="0" step="0.01" placeholder="0.00">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="source" class="form-label">How did you hear about us?</label>
                        <select class="form-select" id="source" name="source">
                            <option value="website">Website</option>
                            <option value="referral">Referral</option>
                            <option value="advertisement">Advertisement</option>
                            <option value="social_media">Social Media</option>
                            <option value="phone">Phone</option>
                            <option value="walk_in">Walk-in</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Step 3: Medical and Legal Information -->
    <div class="step-content" id="step-3-content">
        <div class="card" id="medical-legal-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-heart-pulse me-2"></i>
                    Medical and Legal Information
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-12 mb-4">
                        <h6>Medical Treatment</h6>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="medical_treatment" name="medical_treatment">
                            <label class="form-check-label" for="medical_treatment">
                                Did you receive medical treatment for your injuries?
                            </label>
                        </div>
                    </div>
                    
                    <div class="col-12 mb-4">
                        <h6>Police Report</h6>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="police_report" name="police_report">
                            <label class="form-check-label" for="police_report">
                                Was a police report filed for this incident?
                            </label>
                        </div>
                    </div>
                    
                    <div class="col-12 mb-4">
                        <h6>Insurance Claim</h6>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="insurance_claim" name="insurance_claim">
                            <label class="form-check-label" for="insurance_claim">
                                Have you filed an insurance claim related to this incident?
                            </label>
                        </div>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="employment_status" class="form-label">Employment Status</label>
                        <select class="form-select" id="employment_status" name="employment_status">
                            <option value="">Select status...</option>
                            <option value="employed_full_time">Employed Full-time</option>
                            <option value="employed_part_time">Employed Part-time</option>
                            <option value="self_employed">Self-employed</option>
                            <option value="unemployed">Unemployed</option>
                            <option value="retired">Retired</option>
                            <option value="student">Student</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Step 4: Review and Submit -->
    <div class="step-content" id="step-4-content">
        <div class="card" id="review-card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-check-circle me-2"></i>
                    Review and Submit
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info" id="review-notice">
                    <i class="bi bi-info-circle me-2"></i>
                    Please review the information below before submitting the intake form.
                </div>
                
                <div id="review-summary">
                    <!-- Summary will be populated by JavaScript -->
                </div>
                
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="confirm_accuracy" name="confirm_accuracy" required>
                    <label class="form-check-label" for="confirm_accuracy">
                        I confirm that the information provided is accurate to the best of my knowledge.
                    </label>
                    <div class="invalid-feedback">Please confirm the accuracy of the information.</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Navigation Buttons -->
    <div class="d-flex justify-content-between mt-4" id="form-navigation">
        <button type="button" class="btn btn-secondary" id="prev-btn" style="display: none;">
            <i class="bi bi-arrow-left me-2"></i>
            Previous
        </button>
        
        <div class="ms-auto">
            <button type="button" class="btn btn-primary" id="next-btn">
                Next
                <i class="bi bi-arrow-right ms-2"></i>
            </button>
            
            <button type="submit" class="btn btn-success" id="submit-btn" style="display: none;">
                <i class="bi bi-check-circle me-2"></i>
                Create Intake
            </button>
        </div>
    </div>
</form>

<style>
.required::after {
    content: " *";
    color: red;
}

.progress-step {
    position: relative;
}

.step-number {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e9ecef;
    color: #6c757d;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    margin-bottom: 0.5rem;
}

.progress-step.active .step-number {
    background: #0d6efd;
    color: white;
}

.progress-step.completed .step-number {
    background: #198754;
    color: white;
}

.step-title {
    font-size: 0.875rem;
    color: #6c757d;
}

.progress-step.active .step-title {
    color: #0d6efd;
    font-weight: 600;
}

.step-content {
    display: none;
}

.step-content.active {
    display: block;
}
</style>

<?php
$additional_js = "
<script>
let currentStep = 1;
const totalSteps = 4;

// Enable auto-save
enableAutoSave('intake-form');

// Step navigation
document.getElementById('next-btn').addEventListener('click', function() {
    if (validateCurrentStep()) {
        nextStep();
    }
});

document.getElementById('prev-btn').addEventListener('click', function() {
    prevStep();
});

function nextStep() {
    if (currentStep < totalSteps) {
        // Hide current step
        document.getElementById('step-' + currentStep + '-content').classList.remove('active');
        document.getElementById('step-' + currentStep).classList.remove('active');
        document.getElementById('step-' + currentStep).classList.add('completed');
        
        // Show next step
        currentStep++;
        document.getElementById('step-' + currentStep + '-content').classList.add('active');
        document.getElementById('step-' + currentStep).classList.add('active');
        
        updateNavigation();
        
        if (currentStep === 4) {
            populateReviewSummary();
        }
    }
}

function prevStep() {
    if (currentStep > 1) {
        // Hide current step
        document.getElementById('step-' + currentStep + '-content').classList.remove('active');
        document.getElementById('step-' + currentStep).classList.remove('active');
        
        // Show previous step
        currentStep--;
        document.getElementById('step-' + currentStep + '-content').classList.add('active');
        document.getElementById('step-' + currentStep).classList.add('active');
        document.getElementById('step-' + currentStep).classList.remove('completed');
        
        updateNavigation();
    }
}

function updateNavigation() {
    const prevBtn = document.getElementById('prev-btn');
    const nextBtn = document.getElementById('next-btn');
    const submitBtn = document.getElementById('submit-btn');
    
    // Show/hide previous button
    prevBtn.style.display = currentStep > 1 ? 'block' : 'none';
    
    // Show/hide next/submit buttons
    if (currentStep === totalSteps) {
        nextBtn.style.display = 'none';
        submitBtn.style.display = 'block';
    } else {
        nextBtn.style.display = 'block';
        submitBtn.style.display = 'none';
    }
}

function validateCurrentStep() {
    const currentContent = document.getElementById('step-' + currentStep + '-content');
    const requiredFields = currentContent.querySelectorAll('[required]');
    let isValid = true;
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.classList.add('is-invalid');
            isValid = false;
        } else {
            field.classList.remove('is-invalid');
            field.classList.add('is-valid');
        }
    });
    
    return isValid;
}

function populateReviewSummary() {
    const form = document.getElementById('intake-form');
    const formData = new FormData(form);
    let summary = '<div class=\"row\">';
    
    // Client Information
    summary += '<div class=\"col-md-6 mb-3\">';
    summary += '<h6>Client Information</h6>';
    summary += '<p><strong>Name:</strong> ' + (formData.get('first_name') || '') + ' ' + (formData.get('last_name') || '') + '</p>';
    summary += '<p><strong>Phone:</strong> ' + (formData.get('phone') || 'Not provided') + '</p>';
    summary += '<p><strong>Email:</strong> ' + (formData.get('email') || 'Not provided') + '</p>';
    summary += '</div>';
    
    // Incident Information
    summary += '<div class=\"col-md-6 mb-3\">';
    summary += '<h6>Incident Information</h6>';
    summary += '<p><strong>Date:</strong> ' + (formData.get('incident_date') || 'Not provided') + '</p>';
    summary += '<p><strong>Location:</strong> ' + (formData.get('incident_location') || 'Not provided') + '</p>';
    summary += '<p><strong>Priority:</strong> ' + (formData.get('priority') || 'Normal') + '</p>';
    summary += '</div>';
    
    summary += '</div>';
    
    document.getElementById('review-summary').innerHTML = summary;
}

// Form validation
document.getElementById('intake-form').addEventListener('submit', function(e) {
    if (!this.checkValidity() || !document.getElementById('confirm_accuracy').checked) {
        e.preventDefault();
        e.stopPropagation();
        showToast('Please complete all required fields and confirm accuracy.', 'error');
    } else {
        // Show loading state
        const submitBtn = document.getElementById('submit-btn');
        const originalContent = showLoading(submitBtn);
    }
    
    this.classList.add('was-validated');
});

// Real-time validation
document.querySelectorAll('input, select, textarea').forEach(field => {
    field.addEventListener('blur', function() {
        if (this.hasAttribute('required')) {
            if (!this.value.trim()) {
                this.classList.add('is-invalid');
                this.classList.remove('is-valid');
            } else {
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            }
        }
    });
});

// Phone number formatting
document.getElementById('phone').addEventListener('input', function() {
    let value = this.value.replace(/\D/g, '');
    if (value.length >= 6) {
        value = value.replace(/(\d{3})(\d{3})(\d{4})/, '($1) $2-$3');
    } else if (value.length >= 3) {
        value = value.replace(/(\d{3})(\d{0,3})/, '($1) $2');
    }
    this.value = value;
});

// SSN formatting
document.getElementById('ssn').addEventListener('input', function() {
    let value = this.value.replace(/\D/g, '');
    if (value.length >= 5) {
        value = value.replace(/(\d{3})(\d{2})(\d{4})/, '$1-$2-$3');
    } else if (value.length >= 3) {
        value = value.replace(/(\d{3})(\d{0,2})/, '$1-$2');
    }
    this.value = value;
});
</script>
";

include '../../includes/footer.php';
?>