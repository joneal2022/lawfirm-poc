    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global JavaScript for Legal Intake System
        
        // Initialize all tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // Initialize all popovers
        const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
        const popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl);
        });
        
        // Global CSRF token for AJAX requests
        const csrfToken = '<?php echo $auth->generateCSRFToken(); ?>';
        
        // Utility function for AJAX requests with CSRF protection
        function makeAjaxRequest(url, data = {}, method = 'POST') {
            data.csrf_token = csrfToken;
            
            return fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .catch(error => {
                console.error('Ajax request failed:', error);
                throw error;
            });
        }
        
        // Show loading spinner
        function showLoading(element) {
            const originalContent = element.innerHTML;
            element.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Loading...';
            element.disabled = true;
            return originalContent;
        }
        
        // Hide loading spinner
        function hideLoading(element, originalContent) {
            element.innerHTML = originalContent;
            element.disabled = false;
        }
        
        // Show toast notification
        function showToast(message, type = 'info') {
            const toastContainer = document.getElementById('toast-container') || createToastContainer();
            
            const toastId = 'toast-' + Date.now();
            const iconClass = {
                success: 'bi-check-circle-fill',
                error: 'bi-exclamation-triangle-fill',
                warning: 'bi-exclamation-triangle-fill',
                info: 'bi-info-circle-fill'
            }[type] || 'bi-info-circle-fill';
            
            const bgClass = {
                success: 'bg-success',
                error: 'bg-danger',
                warning: 'bg-warning',
                info: 'bg-primary'
            }[type] || 'bg-primary';
            
            const toastHTML = `
                <div id="${toastId}" class="toast align-items-center ${bgClass} text-white border-0" role="alert">
                    <div class="d-flex">
                        <div class="toast-body">
                            <i class="bi ${iconClass} me-2"></i>
                            ${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            `;
            
            toastContainer.insertAdjacentHTML('beforeend', toastHTML);
            
            const toastElement = document.getElementById(toastId);
            const toast = new bootstrap.Toast(toastElement);
            toast.show();
            
            // Remove toast element after it's hidden
            toastElement.addEventListener('hidden.bs.toast', function() {
                this.remove();
            });
        }
        
        // Create toast container if it doesn't exist
        function createToastContainer() {
            const container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1055';
            document.body.appendChild(container);
            return container;
        }
        
        // Confirm dialog for destructive actions
        function confirmAction(message, callback) {
            if (confirm(message)) {
                callback();
            }
        }
        
        // Format currency
        function formatCurrency(amount) {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: 'USD'
            }).format(amount);
        }
        
        // Format date
        function formatDate(dateString) {
            return new Date(dateString).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }
        
        // Auto-save form data to localStorage
        function enableAutoSave(formId, interval = 30000) {
            const form = document.getElementById(formId);
            if (!form) return;
            
            const saveKey = 'autosave_' + formId;
            
            // Load saved data
            const savedData = localStorage.getItem(saveKey);
            if (savedData) {
                try {
                    const data = JSON.parse(savedData);
                    Object.keys(data).forEach(key => {
                        const element = form.querySelector(`[name="${key}"]`);
                        if (element) {
                            if (element.type === 'checkbox' || element.type === 'radio') {
                                element.checked = data[key];
                            } else {
                                element.value = data[key];
                            }
                        }
                    });
                } catch (e) {
                    console.error('Failed to load autosave data:', e);
                }
            }
            
            // Save data periodically
            setInterval(() => {
                const formData = new FormData(form);
                const data = {};
                for (let [key, value] of formData.entries()) {
                    data[key] = value;
                }
                localStorage.setItem(saveKey, JSON.stringify(data));
            }, interval);
            
            // Clear saved data on successful submission
            form.addEventListener('submit', () => {
                localStorage.removeItem(saveKey);
            });
        }
        
        // Security reminder for sensitive pages
        function showSecurityReminder() {
            const reminder = document.createElement('div');
            reminder.className = 'alert alert-info alert-dismissible fade show position-fixed';
            reminder.style.cssText = 'top: 70px; right: 20px; z-index: 1050; max-width: 300px;';
            reminder.innerHTML = `
                <i class="bi bi-shield-check me-2"></i>
                <strong>Security Reminder:</strong> You're viewing confidential client information protected by attorney-client privilege.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            document.body.appendChild(reminder);
            
            // Auto-dismiss after 10 seconds
            setTimeout(() => {
                const alert = new bootstrap.Alert(reminder);
                alert.close();
            }, 10000);
        }
        
        // Page-specific security reminder for sensitive areas
        if (window.location.pathname.includes('/documents/') || 
            window.location.pathname.includes('/analysis/') ||
            window.location.pathname.includes('/intake/')) {
            setTimeout(showSecurityReminder, 2000);
        }
    </script>
    
    <?php if (isset($additional_js)): ?>
        <?php echo $additional_js; ?>
    <?php endif; ?>
</body>
</html>