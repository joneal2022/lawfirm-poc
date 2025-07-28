# Legal Intake System - Local Setup Guide

## Recommended Setup: MAMP (Easiest Option)

### 1. Download and Install MAMP

1. Go to https://www.mamp.info/en/downloads/
2. Download MAMP for macOS (free version is sufficient)
3. Install MAMP to your Applications folder
4. Launch MAMP from Applications

### 2. Start MAMP Services

1. Open MAMP application
2. Click "Start Servers" (starts Apache and MySQL)
3. Note the ports shown (usually Apache: 8888, MySQL: 8889)

### 3. Set Up Project Files

```bash
# Copy project files to MAMP directory
cp -r /Users/jackoneal/python_projects/lawfirm-poc/html /Applications/MAMP/htdocs/legal-intake
```

### 4. Set Up Database (Using MAMP's phpMyAdmin)

1. Open http://localhost:8888/phpMyAdmin/ in your browser
2. Click "Databases" tab
3. Create new database named `legal_intake_system`
4. Click the database name to select it
5. Click "Import" tab
6. Choose file: `/Users/jackoneal/python_projects/lawfirm-poc/html/migrations/001_initial_database_schema.sql`
7. Click "Go" to import

### 5. Configure Database Connection

Update `/Applications/MAMP/htdocs/legal-intake/config/database.php`:

```php
private $host = 'localhost:8889';  // MAMP MySQL port
private $dbname = 'legal_intake_system';
private $username = 'root';         // MAMP default
private $password = 'root';         // MAMP default
```

### 6. Access the Application

Open your browser and go to: http://localhost:8888/legal-intake

## Demo Login Credentials

- **Admin**: username: `admin`, password: `AdminPassword123!`
- **Attorney**: username: `attorney1`, password: `AdminPassword123!`
- **Paralegal**: username: `paralegal1`, password: `AdminPassword123!`
- **Intake Specialist**: username: `intake1`, password: `AdminPassword123!`

## Troubleshooting

### PHP Extensions
If you get extension errors, install required PHP extensions:
```bash
# Install common PHP extensions
brew install php@8.2-mysql php@8.2-pdo-mysql

# Or reinstall PHP with all extensions
brew reinstall php@8.2
```

### File Permissions
Make sure upload directories are writable:
```bash
chmod -R 755 html/assets/uploads/
chmod -R 755 html/assets/exports/
```

### MySQL Connection Issues
Check MySQL is running:
```bash
brew services list | grep mysql
```

If not running:
```bash
brew services start mysql
```

## Alternative: Command Line Setup (Advanced Users)

If you prefer installing directly on macOS without Homebrew:

### Option 1: Download PHP and MySQL Directly
1. Download PHP from https://www.php.net/downloads.php
2. Download MySQL from https://dev.mysql.com/downloads/mysql/
3. Follow manual installation instructions

### Option 2: Use MacPorts
```bash
# Install MacPorts first, then:
sudo port install php82 mysql8-server
```

### Option 3: Use Built-in PHP (Limited)
macOS includes PHP, but it may be outdated:
```bash
php --version  # Check if available
```

## Features to Test

1. **Login System**: Try different user roles
2. **Create Intake**: Use the multi-step intake form
3. **Upload Documents**: Test drag-and-drop file upload
4. **OCR Processing**: Upload a PDF and see simulated OCR results
5. **Document Review**: Review OCR text as a paralegal
6. **Workflow Management**: Change intake status and assignments
7. **Analytics Dashboard**: View reports as a managing partner

## Security Notes

- This is a POC with demo credentials
- In production, use strong passwords and enable HTTPS
- The encryption key should be stored securely (not in code)
- Enable proper firewall rules for production deployment