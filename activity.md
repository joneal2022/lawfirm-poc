# Activity Log

## Date: 2025-07-27

### Prompt: Add system status message to dashboard

**Request**: Because this project is a work in progress, I want to add a message to dashboard.php that I can update by putting a record in a table in the database. Create a table called system status that contains a text field. If there is a record in this table when the user enters dashboard.php I want to display the text as an banner on the page that does not go away. Place it between the dashboard-stats-row and the community-posts-section.

**Actions taken**:

1. Created a new database table `system_status` with the following structure:
   - id (INT, auto-increment primary key)
   - message (TEXT, not null)
   - created_at (TIMESTAMP)
   - updated_at (TIMESTAMP)
   - is_active (BOOLEAN, default true)
   - Added indexes on is_active and created_at for performance

2. Modified `dashboard.php`:
   - Added query to fetch the most recent active system status message (lines 156-173)
   - Added conditional banner display between dashboard-stats-row and community-posts-section (lines 312-322)
   - Banner uses Bootstrap alert styling with info-circle icon

3. Tested the feature by:
   - Creating the table successfully
   - Inserting a test message
   - Verifying the banner appears on the dashboard

**Technical details**:
- Used PDO for database queries with proper error handling
- Implemented HTML escaping for security (htmlspecialchars)
- Banner only displays when an active message exists
- Messages are fetched in descending order by creation date (most recent first)

**File changes**:
- Created: `/var/www/html/migrations/013_create_system_status_table.sql`
- Modified: `/var/www/html/dashboard.php`

### Prompt: Do not have the banner disappear automatically

**Request**: Do not have the banner disappear automatically, which is what it currently is doing.

**Actions taken**:

1. Identified the issue:
   - Found JavaScript in `header.php` that auto-dismisses alerts after 5 seconds
   - The code was not excluding our system status banner from auto-dismissal

2. Fixed the issue by:
   - Added `system-status-banner` class to the alert div in dashboard.php
   - Updated the JavaScript selector in header.php to exclude `.system-status-banner` from auto-dismissal
   - Also updated the inner condition check to skip system status banners

**Technical details**:
- The auto-dismiss JavaScript was targeting all `.alert` elements except `.alert-danger` and `.welcome-banner`
- Added `.system-status-banner` to the exclusion list in both the querySelector and the inner condition check
- Banner will now remain visible until manually dismissed or the page is refreshed

**File changes**:
- Modified: `/var/www/html/dashboard.php` (line 316)
- Modified: `/var/www/html/includes/header.php` (lines 348, 354)

### Prompt: Create complete trouble ticket module

**Request**: Next, I want to create a complete module for trouble tickets and service requests. For non admin users I want a trouble ticket dropdown to be where the Admin dropdown is for admin users. The title of the dropdown should be called "Tickets". It should have "New Ticket", "Open Tickets", and "Closed Tickets" in the list. For admin users, add "Open Tickets" and "Closed Tickets" to be in the Admin dropdown. Model the system off any popular open source help desk software you find.

**Actions taken**:

1. **Database Design & Implementation**:
   - Created comprehensive ticket system tables:
     - `ticket_categories` - For organizing tickets by type
     - `tickets` - Main ticket table with auto-generated ticket numbers
     - `ticket_replies` - For conversation threads
     - `ticket_attachments` - For file uploads (structure created)
     - `ticket_status_history` - Track all status changes
     - `ticket_assignments` - Track staff assignments
   - Added triggers for automatic timestamp updates
   - Inserted default categories (General Support, Technical Issue, Feature Request, etc.)

2. **Navigation Updates**:
   - Modified `header.php` to add ticket dropdown for non-admin users
   - Added ticket options to admin dropdown for admin users
   - Used Bootstrap icons for visual consistency

3. **Created Core Pages**:
   - **New Ticket** (`/tickets/new.php`):
     - Form with subject, category, priority, and description
     - Auto-generates ticket numbers (YYYY-000001 format)
     - File attachment support (prepared for implementation)
   
   - **Open Tickets** (`/tickets/open.php`):
     - Lists all tickets with status: new, open, in_progress, on_hold
     - Admins see all tickets, users see only their own
     - Filterable by category, priority, and search terms
     - Sortable table with pagination
   
   - **Closed Tickets** (`/tickets/closed.php`):
     - Lists resolved and closed tickets
     - Similar filtering and display as open tickets
     - Shows time since closure
   
   - **View Ticket** (`/tickets/view.php`):
     - Detailed ticket view with full conversation thread
     - Reply functionality for users and staff
     - Admin features:
       - Status management with history tracking
       - Assignment to staff members
       - Internal notes (not visible to users)
     - Status workflow: New → Open → In Progress → On Hold → Resolved → Closed

4. **Key Features Implemented**:
   - **Ticket Lifecycle Management**:
     - Auto-generated unique ticket numbers
     - Multiple status states with proper transitions
     - Priority levels (Low, Normal, High, Urgent)
     - Category-based organization
   
   - **User Features**:
     - Submit and track own tickets
     - Reply to tickets
     - View ticket history
   
   - **Admin Features**:
     - View and manage all tickets
     - Assign tickets to staff
     - Change status and priority
     - Add internal notes
     - Complete audit trail

5. **Security & Access Control**:
   - Users can only view their own tickets
   - Admins have full access to all tickets
   - Proper input validation and sanitization
   - SQL injection prevention with prepared statements

**Technical Implementation**:
- Used existing authentication system (global_role)
- Followed project's coding patterns and UI design
- Responsive Bootstrap 5 layout
- Real-time status badges and visual indicators
- Proper error handling and user feedback

**File changes**:
- Created: `/var/www/html/migrations/014_create_ticket_system_tables.sql`
- Modified: `/var/www/html/includes/header.php`
- Created: `/var/www/html/tickets/new.php`
- Created: `/var/www/html/tickets/open.php`
- Created: `/var/www/html/tickets/closed.php`
- Created: `/var/www/html/tickets/view.php`

**Testing**:
- Created test ticket #2025-000001 to verify functionality
- All core features working: ticket creation, listing, viewing, and management

**Note**: Email notifications are prepared in the structure but not yet implemented, as this would require email configuration details.