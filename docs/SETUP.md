# JAWS Setup Guide

This guide will walk you through setting up JAWS for local development, from installing prerequisites to verifying your installation works correctly.

## Table of Contents

- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Environment Configuration](#environment-configuration)
- [Database Initialization](#database-initialization)
- [Starting the Development Server](#starting-the-development-server)
- [Verification](#verification)
- [Frontend Integration](#frontend-integration)
- [Common Setup Issues](#common-setup-issues)
- [Next Steps](#next-steps)

---

## Prerequisites

Before setting up JAWS, ensure you have the following installed:

### Required Software

**1. PHP 8.1 or higher**

Check your version:
```bash
php -v
```

Download from: https://www.php.net/downloads

**2. Composer** (PHP dependency manager)

Check your version:
```bash
composer -v
```

Download from: https://getcomposer.org/download/

**3. SQLite 3** (usually comes with PHP)

Verify SQLite extensions are enabled:
```bash
php -m | grep sqlite
```

You should see both `pdo_sqlite` and `sqlite3` in the output.

**4. Node.js 18 or higher** (for JavaScript linting)

Check your version:
```bash
node -v
npm -v
```

Download from: https://nodejs.org/

**5. Git** (for version control)

Check your version:
```bash
git --version
```

Download from: https://git-scm.com/downloads

### Required PHP Extensions

Ensure these extensions are enabled in your `php.ini` file:

```ini
extension=pdo_sqlite
extension=sqlite3
extension=curl
extension=mbstring
extension=openssl
```

To check all enabled extensions:
```bash
php -m
```

### Optional but Recommended

- **SQLite Browser** - Visual database inspection tool
  - Download: https://sqlitebrowser.org/

- **Postman** - API testing tool
  - Download: https://www.postman.com/downloads/

- **Docker Desktop** - Optional for local SMTP testing (MailHog)
  - Download: https://www.docker.com/products/docker-desktop

---

## Installation

### Step 1: Clone the Repository

```bash
git clone <repository-url>
cd JAWS
```

### Step 2: Install Dependencies

Install PHP dependencies using Composer:

```bash
composer install
```

Install JavaScript dependencies using npm (required for linting):

```bash
npm install
```

This will install:

- `phpmailer/phpmailer` - Email service via SMTP
- `eluceo/ical` - iCalendar file generation
- `phpunit/phpunit` (dev) - Testing framework
- `robmorgan/phinx` (dev) - Database migrations

**Expected Output:**
```
Loading composer repositories with package information
Installing dependencies (including require-dev) from lock file
...
Generating autoload files
```

---

## Environment Configuration

### Step 1: Create Environment File

Copy the example environment file:

```bash
cp .env.example .env
```

If `.env.example` doesn't exist, create a new `.env` file:

```bash
# On Windows
type nul > .env

# On Mac/Linux
touch .env
```

### Step 2: Configure Environment Variables

Edit `.env` with your preferred text editor and add the following configuration:

```bash
# Database
DB_PATH=database/jaws.db

# JWT Authentication (REQUIRED)
# Must be at least 32 characters long
JWT_SECRET=your-secret-key-at-least-32-characters-long-change-this-in-production
JWT_EXPIRATION_MINUTES=60

# Email - Local Development (APP_ENV=development or local)
# Emails are captured by MailHog (https://github.com/mailhog/MailHog) instead of being delivered.
# Start MailHog, then view captured emails at http://localhost:8025
SMTP_HOST=localhost
SMTP_PORT=1025
SMTP_SECURE=
SMTP_USERNAME=
SMTP_PASSWORD=

# Email - Production (APP_ENV=production)
# Mailjet API keys (https://app.mailjet.com → Account Settings → API Key Management)
MJ_APIKEY_PUBLIC=your_mailjet_public_api_key
MJ_APIKEY_PRIVATE=your_mailjet_private_api_key

# Email Configuration
EMAIL_FROM=noreply@example.com
EMAIL_FROM_NAME="JAWS System"
ADMIN_NOTIFICATION_EMAIL=admin@example.com

# Application
APP_DEBUG=true
APP_ENV=development
APP_TIMEZONE=America/Toronto

# CORS (for frontend development)
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://localhost:8080
CORS_ALLOWED_HEADERS=Content-Type,Authorization
```

**Important Notes:**

- **JWT_SECRET** is required and must be at least 32 characters
- For production, set `APP_DEBUG=false` and use strong, unique credentials
- If you don't create a `.env` file, the application will use default values from `config/config.php`

---

## Database Initialization

JAWS uses Phinx for database migrations. The database must be initialized before running the application.

### Step 1: Run Migrations

Create the database and apply all migrations:

```bash
vendor/bin/phinx migrate
```

This command will:
- Create `database/jaws.db` (SQLite database file)
- Create the `phinxlog` table to track migrations
- Apply all pending migrations in order
- Set up the complete database schema with 12 tables

**Expected Output:**
```
Phinx by CakePHP - https://phinx.org.

using config file ./phinx.php
using config parser php
using migration paths
 - d:\source\repos\JAWS\database\migrations

 == 20260101000000 InitialSchema: migrating
 == 20260101000000 InitialSchema: migrated (0.0234s)

 == 20260130000000 AddUsersAuthentication: migrating
 == 20260130000000 AddUsersAuthentication: migrated (0.0187s)

All Done. Took 0.0421s
```

### Step 2: (Optional) Seed Test Data

If you want to populate the database with test data:

```bash
vendor/bin/phinx seed:run
```

### Step 3: Verify Database Creation

Check that the database file was created:

```bash
# On Windows
dir database\jaws.db

# On Mac/Linux
ls -lh database/jaws.db
```

You should see the `jaws.db` file in the `database/` directory.

📖 **See also:** [Database README](../database/README.md) - Detailed database management documentation

---

## Starting the Development Server

PHP includes a built-in web server suitable for development:

### Start the Server

```bash
php -S localhost:8000 -t public
```

**Options:**
- `-S localhost:8000` - Listen on localhost port 8000
- `-t public` - Serve files from the `public` directory

To use a different port:
```bash
php -S localhost:3000 -t public
```

**Expected Output:**
```
[Wed Feb  5 10:30:15 2026] PHP 8.1.0 Development Server (http://localhost:8000) started
```

The development server is now running. Keep this terminal window open.

⚠️ **Important:** The built-in PHP server is for development only. Do not use it in production.

---

## Verification

Verify your installation is working correctly:

### Test 1: Check API Health

Open a new terminal window and test the API:

```bash
curl http://localhost:8000/api/events
```

**Expected Response:**
```json
{
  "success": true,
  "data": {
    "events": []
  }
}
```

If you see this response, the API is working correctly!

### Test 2: Browser Test

Open your web browser and navigate to:

```
http://localhost:8000/api/events
```

You should see the same JSON response.

### Test 3: Frontend Test

Navigate to the frontend:

```
http://localhost:8000
```

You should see the JAWS frontend application (or a placeholder if the frontend hasn't been set up yet).

### Troubleshooting Verification Issues

**Problem:** "Connection refused" or "Failed to connect"
- **Solution:** Ensure the development server is running (`php -S localhost:8000 -t public`)

**Problem:** "Database not found" error
- **Solution:** Run migrations: `vendor/bin/phinx migrate`

**Problem:** "JWT_SECRET not configured" error
- **Solution:** Set `JWT_SECRET` in your `.env` file (minimum 32 characters)

---

## Frontend Integration

The JAWS frontend is located in the `public/app/` directory.

### Quick Overview

- **Main HTML:** `public/app/index.html`
- **JavaScript:** `public/app/js/main.js`
- **Styles:** `public/app/css/styles.css`
- **Assets:** `public/app/assets/`

### Replacing the Sample Frontend

To integrate your own frontend:

1. Replace `public/app/index.html` with your main HTML file
2. Add your JavaScript files to `public/app/js/`
3. Add your CSS files to `public/app/css/`
4. Add images/assets to `public/app/assets/`

### API Integration

The backend REST API is available at `/api`. All endpoints are documented in the [API Reference](API.md).

Example API call from JavaScript:
```javascript
const token = localStorage.getItem('jaws_token');

fetch('/api/users/me', {
    headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json'
    }
})
.then(response => response.json())
.then(data => console.log(data));
```

📖 **See also:** [Frontend Setup Guide](FRONTEND_SETUP.md) - Complete frontend integration documentation

➡️ **Next:** Continue to [API Reference](API.md) - Learn about available API endpoints

---

## Common Setup Issues

### Issue: "Composer not found"

**Problem:** Composer is not installed or not in PATH

**Solution:**
1. Download Composer from https://getcomposer.org/download/
2. Install globally (Windows: use the installer; Mac/Linux: move to `/usr/local/bin/composer`)
3. Verify: `composer -v`

### Issue: "PDO SQLite extension not loaded"

**Problem:** Required PHP extension is disabled

**Solution:**
1. Locate your `php.ini` file: `php --ini`
2. Edit `php.ini` and uncomment (remove `;` from):
   ```ini
   extension=pdo_sqlite
   extension=sqlite3
   ```
3. Restart PHP or your terminal

### Issue: "Permission denied" when creating database

**Problem:** Database directory is not writable

**Solution:**
```bash
# On Windows (run as Administrator)
icacls database /grant Users:F

# On Mac/Linux
chmod 775 database
```

### Issue: "Migration failed" or "Table already exists"

**Problem:** Partial migration or migration applied out of order

**Solution:**
```bash
# Check migration status
vendor/bin/phinx status

# Rollback last migration
vendor/bin/phinx rollback

# Re-run migrations
vendor/bin/phinx migrate
```

### Issue: "Port already in use"

**Problem:** Another process is using port 8000

**Solution:**
```bash
# Use a different port
php -S localhost:3000 -t public

# Or find and kill the process using port 8000 (Windows)
netstat -ano | findstr :8000
taskkill /PID <process_id> /F

# Or find and kill the process (Mac/Linux)
lsof -ti:8000 | xargs kill
```

### Issue: ".env file not being read"

**Problem:** Environment variables not loading

**Solution:**
1. Verify `.env` file exists in project root (not in `config/` or `public/`)
2. Check file encoding is UTF-8 without BOM
3. Ensure no syntax errors in `.env` file (no spaces around `=`)
4. Restart the development server

---

## Next Steps

Now that you have JAWS set up locally, here's what to do next:

### For New Users:
✅ Installation complete!
➡️ **Next:** Read [Developer Guide](DEVELOPER_GUIDE.md) - Learn about the project architecture and development workflow

### For Developers:
✅ Environment configured!
➡️ **Next:** Read [Developer Guide](DEVELOPER_GUIDE.md) - Learn about Clean Architecture and coding patterns

### For API Consumers:
✅ API running!
➡️ **Next:** Read [API Reference](API.md) - Learn about available endpoints and authentication

### For Operators:
✅ Local setup complete!
➡️ **Next:** Read [Deployment Guide](DEPLOYMENT.md) - Learn about production deployment

---

📖 **Additional Resources:**

- [Database Management](../database/README.md) - Migrations, backups, queries
- [Contributing Guide](CONTRIBUTING.md) - Code style and Git workflow
- [CLAUDE.md](../CLAUDE.md) - Complete technical specifications (for AI assistants)
