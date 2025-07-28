# Design Notes - AI-Powered Legal Intake System

Used for Claude Code's reference for styling the legal intake system UI

## Overview

This document outlines the UI/UX patterns for the AI-Powered Legal Intake System using standard Bootstrap 5 components. The system is designed for personal injury law firms to automate client intake, document processing, and lead qualification while maintaining HIPAA compliance and attorney-client privilege.

## Design Principles

1. **Professional & Trust-building**: Legal industry requires credible, professional appearance
2. **Security-First Design**: Visual indicators for secure sections and compliance
3. **Simplicity**: Use default Bootstrap components without customization
4. **Accessibility**: HIPAA compliance requires accessibility features
5. **Mobile-First**: Responsive design for all devices including tablets for in-office use
6. **Performance**: Bootstrap CDN for optimal loading in law firm environments

## Color Scheme & Legal Industry Adaptation

Using Bootstrap 5 default theme colors adapted for legal industry:
- **Primary**: #0d6efd (Professional Blue - trust and reliability)
- **Secondary**: #495057 (Dark Gray - professional, serious)
- **Success**: #198754 (Green - case acceptance, positive outcomes)
- **Warning**: #ffc107 (Yellow - pending reviews, statute of limitations alerts)
- **Danger**: #dc3545 (Red - declined cases, urgent deadlines)
- **Info**: #0dcaf0 (Cyan - informational alerts)
- **Light**: #f8f9fa (Clean backgrounds)
- **Dark**: #212529 (Professional headers)

## Key User Roles & Interface Adaptations

### 1. Intake Specialist Interface
- Simplified dashboard focused on new intake management
- Quick access to client communication tools
- Document upload progress indicators
- Basic reporting for daily metrics

### 2. Paralegal Interface
- Advanced document review and OCR correction tools
- Case organization and conflict checking features
- Medical record parsing interfaces
- Detailed search and filtering capabilities

### 3. Attorney Interface
- Case evaluation and scoring review
- Attorney work product sections with privilege indicators
- Decision-making interfaces for case acceptance
- Complete case file access

### 4. Managing Partner Interface
- Executive dashboard with firm-wide analytics
- Financial projections and ROI metrics
- Policy configuration interfaces
- High-level reporting tools

### 5. System Administrator Interface
- User management and role configuration
- System monitoring and audit log access
- Integration management interfaces
- Security configuration panels

## Page Layouts

### 1. Authentication Pages
**Reference**: Bootstrap sign-in example
- HIPAA-compliant login with MFA integration
- Professional law firm branding area
- Security indicators and compliance notices
- Password strength indicators
- Session management options

### 2. Main Dashboard Layout
**Reference**: Bootstrap dashboard example
- Role-specific sidebar navigation
- Top navbar with firm branding and secure user menu
- Widget-based dashboard with KPIs
- Quick action buttons for common tasks
- Secure logout with session cleanup

### 3. Intake Management Interface
**Reference**: Bootstrap album/cards layout
- Card-based intake preview system
- Status badges for intake workflow stages
- Filter and search functionality
- Bulk action capabilities
- Priority indicators for urgent cases

### 4. Document Processing Interface
**Reference**: Bootstrap masonry layout
- Split-screen OCR correction interface
- Document classification panels
- Information extraction displays
- Quality scoring indicators
- Batch processing status

### 5. Case Review & Analysis
**Reference**: Bootstrap checkout form layout
- AI analysis results display
- Liability assessment indicators
- Damages calculation breakdowns
- Recommendation engine outputs
- Decision recording interfaces

### 6. Client Communication Center
- Timeline view of all interactions
- Secure messaging interfaces
- Automated communication templates
- Compliance tracking for communications
- Integration with email/SMS systems

## Component Patterns

### Security & Compliance Components
- **Privilege Markers**: Visual indicators for attorney work product
- **HIPAA Compliance Badges**: Show secure data handling
- **Encryption Indicators**: Visual confirmation of data protection
- **Audit Trail Display**: Transparent logging for compliance
- **Access Control Indicators**: Show permission levels

### Legal-Specific Data Display
- **Case Status Badges**: Color-coded workflow stages
- **Priority Indicators**: Urgent, high, normal, low with appropriate colors
- **Document Type Labels**: Medical records, police reports, etc.
- **AI Confidence Scores**: Visual indicators for analysis accuracy
- **Financial Projections**: Currency formatting and calculations

### Workflow Management
- **Multi-Step Forms**: Intake process with progress indicators
- **Review Queues**: Sortable, filterable tables
- **Decision Recording**: Accept/decline with reasoning capture
- **Task Assignment**: User selection and deadline management
- **Status Transitions**: Clear workflow progression

### Document Handling
- **Drag-and-Drop Upload**: With progress indicators
- **OCR Status Display**: Processing, completed, error states
- **Document Viewer**: PDF and image display with annotations
- **Batch Processing**: Multiple document handling
- **File Organization**: Categorized document management

## Forms and Data Entry

### Intake Forms
- **Progressive Disclosure**: Show relevant fields based on previous answers
- **Validation**: Real-time validation with legal-specific rules
- **Auto-Save**: Prevent data loss during long forms
- **Required Field Indicators**: Clear marking for mandatory information
- **Help Text**: Legal terminology explanations

### Document Metadata Forms
- **Smart Classification**: AI-suggested document types
- **Date Pickers**: For incident dates, treatment dates
- **Provider Selection**: Medical provider and insurance company lookups
- **Amount Fields**: Currency formatting for damages
- **Text Extraction**: Editable OCR results

## Responsive Design Considerations

### Desktop (Law Office Workstations)
- Multi-panel layouts for document review
- Detailed tables with extensive filtering
- Full feature access for complex workflows
- Keyboard shortcuts for power users

### Tablet (Client Consultation)
- Simplified intake forms for client use
- Touch-optimized controls
- Larger text for readability
- Camera integration for document capture

### Mobile (Remote Access)
- Essential functions only
- Simplified navigation
- Quick status checking
- Emergency contact features

## Accessibility & Compliance

### HIPAA Technical Safeguards
- Visual session timeout warnings
- Secure logout confirmations
- Screen overlay for sensitive data
- Print prevention for protected information
- Automatic screen locking indicators

### Legal Accessibility Requirements
- Screen reader compatibility
- Keyboard navigation
- High contrast options
- Text scaling support
- Language translation support

## Performance Guidelines

### Legal Document Processing
- Progress indicators for OCR processing
- Lazy loading for large document sets
- Efficient pagination for case lists
- Caching for frequently accessed data
- Background processing indicators

### Network Considerations
- Offline capability for document viewing
- Progressive loading for slow connections
- Compression for document transfers
- CDN optimization for static assets

## File Organization

```
/html/
  /assets/
    /images/         # Firm logos, user avatars, icons
    /uploads/        # Client documents (encrypted storage)
    /exports/        # Generated reports and exports
  /includes/
    header.php       # Role-based navigation
    footer.php       # Compliance footer information
    sidebar.php      # Context-sensitive sidebar
    auth-check.php   # Session and permission validation
  /pages/
    login.php        # MFA-enabled authentication
    dashboard.php    # Role-specific dashboard
    intake/          # Client intake management
    documents/       # Document processing interfaces
    analysis/        # AI analysis and review
    reports/         # Analytics and reporting
    admin/           # System administration
  /api/
    auth/           # Authentication endpoints
    intake/         # Intake management API
    documents/      # Document processing API
    analysis/       # AI analysis API
    reports/        # Reporting API
  /migrations/      # Database schema updates
  /config/         # Application configuration
```

## Security Visual Indicators

### Data Classification
- **Public**: Standard styling
- **Confidential**: Yellow border/background
- **Privileged**: Red border with lock icon
- **PHI/PII**: Blue border with shield icon

### User Permissions
- **Admin Functions**: Dark background with admin badge
- **Restricted Access**: Grayed out with permission note
- **Temporary Access**: Orange indicators with expiration
- **Audit Required**: Special indicators for logged actions

## Implementation Examples

### Legal Dashboard Card
```html
<div class="card mb-3" id="intake-summary-card">
  <div class="card-header d-flex justify-content-between">
    <h5 class="mb-0">Today's Intake Summary</h5>
    <span class="badge bg-primary">5 New</span>
  </div>
  <div class="card-body">
    <div class="row">
      <div class="col-md-3">
        <div class="text-center">
          <h3 class="text-success">12</h3>
          <small class="text-muted">Total Received</small>
        </div>
      </div>
      <div class="col-md-3">
        <div class="text-center">
          <h3 class="text-warning">5</h3>
          <small class="text-muted">Under Review</small>
        </div>
      </div>
      <div class="col-md-3">
        <div class="text-center">
          <h3 class="text-success">3</h3>
          <small class="text-muted">Accepted</small>
        </div>
      </div>
      <div class="col-md-3">
        <div class="text-center">
          <h3 class="text-danger">2</h3>
          <small class="text-muted">Declined</small>
        </div>
      </div>
    </div>
  </div>
</div>
```

### Case Status Badge System
```html
<span class="badge bg-secondary" id="intake-status-new">New Intake</span>
<span class="badge bg-info" id="intake-status-documents">Documents Pending</span>
<span class="badge bg-warning" id="intake-status-review">Under Review</span>
<span class="badge bg-primary" id="intake-status-attorney">Attorney Review</span>
<span class="badge bg-success" id="intake-status-accepted">Accepted</span>
<span class="badge bg-danger" id="intake-status-declined">Declined</span>
```

## References

- Bootstrap 5 Documentation: https://getbootstrap.com/docs/5.0/
- HIPAA Technical Safeguards: https://www.hhs.gov/hipaa/for-professionals/security/
- ADA Accessibility Guidelines: https://www.ada.gov/
- Legal Industry UX Best Practices
- Personal Injury Law Firm Workflows

## Notes

- All interfaces must support HIPAA audit requirements
- Attorney-client privilege must be visually indicated
- Session management must be prominent for security
- Mobile interfaces should focus on essential functions only
- All forms must have proper validation and error handling
- Document processing status must be clearly communicated