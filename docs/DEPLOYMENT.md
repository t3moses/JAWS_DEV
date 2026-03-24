# JAWS Deployment Guide

Complete guide for deploying JAWS to production on AWS Lightsail.

## Table of Contents

- [Pre-Deployment Checklist](#pre-deployment-checklist)
- [AWS Lightsail Deployment](#aws-lightsail-deployment)
- [Environment Configuration](#environment-configuration)
- [Database Management](#database-management)
- [Cron Jobs](#cron-jobs)
- [Monitoring](#monitoring)
  - [Shipping Logs to CloudWatch Logs (Optional)](#shipping-logs-to-cloudwatch-logs-optional)
- [Rollback Procedures](#rollback-procedures)
- [Troubleshooting](#troubleshooting)

---

## Pre-Deployment Checklist

Before deploying to production, verify the following:

- [ ] All tests pass locally: `./vendor/bin/phpunit`
- [ ] Code reviewed and approved via Pull Request
- [ ] Database migrations tested locally
- [ ] Production `.env` file prepared with secure credentials
- [ ] Mailjet API credentials configured and email sending tested
- [ ] Backup of current production database created
- [ ] Deployment window scheduled (avoid event hours 10:00-18:00)
- [ ] Rollback plan prepared

---

## AWS Lightsail Deployment

JAWS is deployed on AWS Lightsail with a Bitnami LAMP stack. This guide follows [Bitnami's best practices for custom PHP applications](https://docs.bitnami.com/general/infrastructure/lamp/administration/create-custom-application-php/).

### Prerequisites

- **AWS Lightsail instance running**
- **SSH key file**: e.g., `LightsailDefaultKey-ca-central-1.pem`
- **SSH access** to the server
- **SFTP client** for file uploads
- **Bitnami LAMP stack** installed on Lightsail

### Initial Setup (One-Time Configuration)

These steps are only needed when setting up a new server.

#### 1. Create Application Directory

Following Bitnami conventions, create the application directory at `/opt/bitnami/jaws`:

```bash
sudo mkdir -p /opt/bitnami/jaws
sudo chown -R bitnami:daemon /opt/bitnami/jaws
sudo chmod -R g+w /opt/bitnami/jaws
```

#### 2. Configure Apache Virtual Host

Create Apache virtual host configuration to route requests to the JAWS application.

**File:** `/opt/bitnami/apache/conf/vhosts/jaws-vhost.conf`

```apache
<VirtualHost 127.0.0.1:80 _default_:80>
    ServerName your-domain.com
    ServerAlias *
    DocumentRoot /opt/bitnami/jaws/public

    <Directory /opt/bitnami/jaws/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Important Configuration Notes:**

- `AllowOverride All` is **required** for `.htaccess` support. See [Bitnami's .htaccess documentation](https://docs.bitnami.com/general/infrastructure/lamp/administration/use-htaccess/) for details.
- The `.htaccess` file in the `public/` directory contains **critical Authorization header forwarding** for JWT authentication:
  ```apache
  RewriteCond %{HTTP:Authorization} .
  RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
  ```
  Without this directive, JWT authentication will fail in production.

> **Best Practice:** Bitnami recommends consolidating `.htaccess` directives into the virtual host configuration for better security and performance. However, JAWS uses `.htaccess` for deployment flexibility. For stable production environments, consider migrating rules to `/opt/bitnami/apache/conf/vhosts/jaws-htaccess.conf` and setting `AllowOverride None`.

Restart Apache:
```bash
sudo /opt/bitnami/ctlscript.sh restart apache
```

#### 3. Configure SSL/HTTPS with Let's Encrypt

Follow these steps to install certbot, obtain a certificate, and configure Apache for HTTPS.

##### Install Certbot

```bash
sudo apt install certbot python3-certbot-dns-route53
```

##### Configure AWS Credentials for DNS Validation

Certbot runs as root and needs access to the AWS IAM credentials to create DNS records for domain validation. Copy the existing Bitnami credentials to the root account:

```bash
sudo mkdir -p /root/.aws
sudo cp /home/bitnami/.aws/credentials /root/.aws/credentials
sudo cp /home/bitnami/.aws/config /root/.aws/config
```

##### Issue the Certificate

```bash
sudo certbot certonly \
  --dns-route53 \
  -d nsc-sdc.ca \
  -d www.nsc-sdc.ca
```

Certbot will use DNS-01 validation via Route 53 and store the certificate at:

```
/etc/letsencrypt/live/nsc-sdc.ca/
```

> **Note:** If the certificate has been issued before, certbot may use a numbered directory such as `/etc/letsencrypt/live/nsc-sdc.ca-0001/`. Run `certbot certificates` to confirm the actual path.

##### Create SSL Certificate Symbolic Links

Let's Encrypt stores certificates in `/etc/letsencrypt/live/your-domain.com/`. Apache expects certificates in `/opt/bitnami/apache/conf/`. Create symbolic links to point Apache to the Let's Encrypt certificates:

```bash
cd /opt/bitnami/apache/conf

# Create symlinks to Let's Encrypt certificates
sudo ln -sf /etc/letsencrypt/live/$DOMAIN/fullchain.pem server.crt
sudo ln -sf /etc/letsencrypt/live/$DOMAIN/privkey.pem server.key

# Verify symlinks created successfully
ls -l server.crt server.key
```

**Expected output:**
```
lrwxrwxrwx 1 root root 57 Feb 16 12:00 server.crt -> /etc/letsencrypt/live/your-domain.com/fullchain.pem
lrwxrwxrwx 1 root root 55 Feb 16 12:00 server.key -> /etc/letsencrypt/live/your-domain.com/privkey.pem
```

**Important Notes:**
- Set the `$DOMAIN` environment variable with `DOMAIN=your-domain.com`
- The `-f` flag forces creation, replacing any existing files
- Symlinks automatically point to renewed certificates after Let's Encrypt renewal

##### Configure HTTPS Virtual Host

Create an HTTPS virtual host configuration for SSL traffic.

**File:** `/opt/bitnami/apache2/conf/vhosts/jaws-https-vhost.conf`

```apache
<VirtualHost 127.0.0.1:443 _default_:443>
    ServerName your-domain.com
    ServerAlias *
    DocumentRoot /opt/bitnami/jaws/public

    # SSL Configuration
    SSLEngine on
    SSLCertificateFile "/opt/bitnami/apache2/conf/server.crt"
    SSLCertificateKeyFile "/opt/bitnami/apache2/conf/server.key"

    <Directory /opt/bitnami/jaws/public>
        Options -Indexes +FollowSymLinks -MultiViews
        AllowOverride All
        Require all granted
    </Directory>

    Include "/opt/bitnami/apache2/conf/vhosts/htaccess/jaws-htaccess.conf"
</VirtualHost>
```

**Configuration Notes:**

- The SSL directives point to the symlinks created in the previous step
- When Let's Encrypt renews certificates (every 90 days), the symlinks automatically reference the new certificates
- No Apache configuration changes are needed during certificate renewal
- Both HTTP (port 80) and HTTPS (port 443) virtual hosts can coexist

> **Alternative:** Instead of using symlinks, you can point the `SSLCertificateFile` and `SSLCertificateKeyFile` directives directly to the Let's Encrypt paths (e.g. `/etc/letsencrypt/live/nsc-sdc.ca/fullchain.pem`). Either approach works; symlinks make the vhost config domain-agnostic.

##### Restart Apache and Verify

```bash
sudo /opt/bitnami/ctlscript.sh restart apache
```

Check the certificate status and confirm paths:

```bash
certbot certificates
```

Confirm that automatic renewal will work:

```bash
sudo certbot renew --dry-run
```

Then visit the site in a browser and verify the certificate details.

#### 4. Install Composer (if not already installed)

```bash
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"
sudo mv composer.phar /usr/local/bin/composer
```

Verify installation:
```bash
composer --version
```

### Deployment Steps

Follow these steps for each deployment.

#### Step 1: Add SSH Key to Agent

```bash
ssh-add LightsailDefaultKey-ca-central-1.pem
```

On Windows, use PuTTY Pageant or Windows SSH Agent.

#### Step 2: Backup Production Database

**Always backup before deploying:**

```bash
ssh bitnami@16.52.222.15
cd /opt/bitnami/jaws/database
sudo cp jaws.db jaws.backup.$(date +%Y%m%d_%H%M%S).db
ls -lh jaws.backup.*
exit
```

Verify the backup was created successfully before proceeding.

#### Step 3: Upload Files via SFTP

Upload the following files and directories to `/opt/bitnami/jaws`:

```bash
sftp bitnami@16.52.222.15
cd /./opt/bitnami/jaws

# Upload public folder (all files)
put -r public

# Upload source code
put -r src

# Upload configuration
put -r config

# Upload dependency manifests
put composer.json
put composer.lock

# Upload migration configuration
put phinx.php

# Upload database migrations (required for Phinx)
mkdir -p database/migrations
put -r database/migrations

# Optional: Upload database seeds (only needed for test environments)
# mkdir -p database/seeds
# put -r database/seeds

bye
```

**Files/Folders Uploaded:**

| Path | Contents | Required? |
|------|----------|-----------|
| `public/` | index.php, .htaccess, debug_jwt.php, app/, debug.html | Yes |
| `src/` | Application source code (Domain, Application, Infrastructure, Presentation) | Yes |
| `config/` | config.php, container.php, routes.php | Yes |
| `composer.json` | Dependency manifest | Yes |
| `composer.lock` | Locked dependency versions | Yes |
| `phinx.php` | Migration configuration | Yes |
| `database/migrations/` | Phinx migration files | Yes |
| `database/seeds/` | Test data seeders | Optional (test only) |

**Tip:** To upload specific files only (for hotfixes):
```bash
put src/Domain/Service/SelectionService.php src/Domain/Service/SelectionService.php
```

#### Step 4: Set Initial File Permissions

SSH into the server and set ownership **before** running Composer:

```bash
ssh bitnami@16.52.222.15
cd /opt/bitnami/jaws

# Set ownership following Bitnami conventions
sudo chown -R bitnami:daemon /opt/bitnami/jaws
sudo chmod -R g+w /opt/bitnami/jaws
```

This ensures Composer can write to the `vendor/` directory.

#### Step 5: Install Dependencies

**Critical:** Do **NOT** use the `--no-dev` flag:

```bash
cd /opt/bitnami/jaws
composer install --optimize-autoloader
```

**Why no `--no-dev` flag?**

The `--no-dev` flag excludes development dependencies, but **Phinx** (the database migration tool) is listed in `require-dev`. Without Phinx, database migrations cannot be run in production. While this increases deployment size slightly, it's necessary for database management.

**Alternative for maximum security:** Run migrations, then remove development dependencies:
```bash
composer install --optimize-autoloader
vendor/bin/phinx migrate --environment=production
composer install --no-dev --optimize-autoloader
```

#### Step 6: Run Database Migrations (if applicable)

If you have new migrations:

```bash
# Check migration status
vendor/bin/phinx status --environment=production

# Run pending migrations
vendor/bin/phinx migrate --environment=production

# Verify migrations applied successfully
vendor/bin/phinx status --environment=production
```

**Important:** Always test migrations locally first!

#### Step 7: Set Final File Permissions

After Composer and migrations complete, set final permissions:

```bash
# Ensure proper ownership (bitnami:daemon per Bitnami convention)
sudo chown -R bitnami:daemon /opt/bitnami/jaws

# Application code - standard permissions
sudo find /opt/bitnami/jaws/src -type d -exec chmod 755 {} \;
sudo find /opt/bitnami/jaws/src -type f -exec chmod 644 {} \;
sudo find /opt/bitnami/jaws/config -type d -exec chmod 755 {} \;
sudo find /opt/bitnami/jaws/config -type f -exec chmod 644 {} \;

# Public files - readable by web server
sudo find /opt/bitnami/jaws/public -type d -exec chmod 755 {} \;
sudo find /opt/bitnami/jaws/public -type f -exec chmod 644 {} \;

# Database - read/write for Apache (daemon group)
sudo chmod 775 /opt/bitnami/jaws/database
sudo chmod 664 /opt/bitnami/jaws/database/jaws.db
```

**Permission Reference:**

| Item | Permission | Octal | Reason |
|------|------------|-------|--------|
| Database directory | `drwxrwxr-x` | 775 | Apache daemon needs to create WAL/SHM journal files |
| Database file | `-rw-rw-r--` | 664 | Apache daemon needs write access for SQLite |
| Standard directories | `drwxr-xr-x` | 755 | Owner full access, group/world read/execute |
| Standard files | `-rw-r--r--` | 644 | Owner read/write, group/world read |

**Ownership:** `bitnami:daemon` follows Bitnami conventions, where `daemon` is the group Apache runs under.

#### Step 8: Verify Environment Configuration

Ensure `.env` file exists with production configuration:

```bash
cat /opt/bitnami/jaws/.env
```

If `.env` doesn't exist or needs updates, see [Environment Configuration](#environment-configuration) section.

**Critical environment variables:**
- `DB_PATH=/opt/bitnami/jaws/database/jaws.db`
- `JWT_SECRET` (must be at least 32 characters)
- `APP_DEBUG=false` (never true in production)

#### Step 9: Restart Apache

```bash
sudo /opt/bitnami/ctlscript.sh restart apache
```

#### Step 10: Verify Deployment

Test the API endpoint:

```bash
curl https://your-domain.com/api/events
```

Expected response:
```json
{
  "success": true,
  "data": {
    "events": [...]
  }
}
```

**Use the Debug Page for System Diagnostics:**

Visit `https://your-domain.com/app/debug.html` to check:
- Authentication token status
- Current environment name
- API base URL
- Live connection status test

This page is especially useful for troubleshooting authentication issues after deployment.

If you get an error, check Apache logs:
```bash
sudo tail -f /opt/bitnami/apache/logs/jaws-error.log
```

#### Step 11: Test Critical Functionality

- [ ] Login works (test at `/app/account.html`)
- [ ] Availability updates work
- [ ] Assignments retrieved correctly
- [ ] Email notifications send (test with admin account)
- [ ] Frontend loads correctly
- [ ] Debug page shows correct system status

---

## Environment Configuration

Production environment variables must be configured in `.env` file.

### Creating Production .env File

SSH into server:

```bash
ssh bitnami@16.52.222.15
cd /opt/bitnami/jaws
nano .env
```

Add the following configuration:

```bash
# Database
DB_PATH=/opt/bitnami/jaws/database/jaws.db

# JWT Authentication (REQUIRED - CHANGE THIS!)
JWT_SECRET=your-production-secret-key-at-least-32-characters-long-must-be-different-from-dev
JWT_EXPIRATION_MINUTES=60

# Mailjet Email API
MJ_APIKEY_PUBLIC=your_mailjet_public_api_key
MJ_APIKEY_PRIVATE=your_mailjet_private_api_key
EMAIL_FROM=noreply@nsc-sdc.ca
EMAIL_FROM_NAME="Nepean Sailing Club - Social Day Cruising"
ADMIN_NOTIFICATION_EMAIL=nsc-sdc@nsc.ca

# Application
APP_DEBUG=false
APP_ENV=production
APP_TIMEZONE=America/Toronto
APP_URL=https://your-domain.com

# CORS (adjust for production frontend domain)
CORS_ALLOWED_ORIGINS=https://your-frontend-domain.com
CORS_ALLOWED_HEADERS=Content-Type,Authorization
```

Save and exit (Ctrl+X, Y, Enter).

### Security Considerations

**Critical Security Settings:**

1. **JWT_SECRET**:
   - Must be at least 32 characters
   - Must be different from development secret
   - Use a cryptographically secure random string
   - Generate with: `openssl rand -base64 32`

2. **APP_DEBUG**:
   - Must be `false` in production
   - When `true`, exposes sensitive error details

3. **Database Permissions**:
   - Database file must not be world-readable
   - Use `chmod 664` (owner + group only)
   - Ownership must be `bitnami:daemon` for Apache access

4. **File Permissions**:
   - PHP files should be `644` (not executable)
   - Directories should be `755` (standard permissions)
   - Database directory requires `775` for SQLite WAL mode
   - Never use `777` permissions

5. **Environment File**:
   - `.env` should not be in version control
   - Should be readable only by `bitnami` user and `daemon` group
   - Use `chmod 640 .env`

6. **Development Dependencies in Production**:
   - JAWS includes Phinx (dev dependency) in production for database migrations
   - This is a trade-off: migration capability vs. minimal attack surface
   - **Alternative for maximum security:**
     - Deploy with full dependencies
     - Run migrations
     - Remove dev dependencies: `composer install --no-dev --optimize-autoloader`
     - Note: Future migrations will require re-installing dev dependencies
   - **Future consideration:** Use a dedicated migration deployment pipeline that doesn't require Phinx in production

---

## Database Management

### Running Migrations in Production

Always backup before running migrations:

```bash
ssh bitnami@16.52.222.15
cd /opt/bitnami/jaws

# 1. Backup database
sudo cp database/jaws.db database/jaws.backup.$(date +%Y%m%d_%H%M%S).db

# 2. Check migration status
vendor/bin/phinx status --environment=production

# 3. Run migrations
vendor/bin/phinx migrate --environment=production

# 4. Verify
vendor/bin/phinx status --environment=production
```

If a migration fails, see [Rollback Procedures](#rollback-procedures).

### Backup Procedures

#### Automated Backups

Set up a daily backup cron job:

```bash
crontab -e
```

Add the following line:

```bash
# Daily backup at 2 AM
0 2 * * * /usr/bin/cp /opt/bitnami/jaws/database/jaws.db /opt/bitnami/jaws/database/backups/jaws.backup.$(date +\%Y\%m\%d).db
```

Create backups directory:

```bash
mkdir -p /opt/bitnami/jaws/database/backups
```

#### Manual Backups

**Before deployment:**
```bash
ssh bitnami@16.52.222.15
cd /opt/bitnami/jaws/database
sudo cp jaws.db jaws.backup.$(date +%Y%m%d_%H%M%S).db
```

**Download backup to local machine:**
```bash
sftp bitnami@16.52.222.15
cd var/www/html/database
get jaws.db
get jaws.backup.*
bye
```

#### Database Backup Strategy

**Retention Policy:**
- Daily backups: Keep for 7 days
- Weekly backups: Keep for 4 weeks
- Monthly backups: Keep for 6 months
- Pre-deployment backups: Keep indefinitely

**Cleanup old backups:**
```bash
# Delete backups older than 7 days
find /opt/bitnami/jaws/database/backups -name "jaws.backup.*.db" -mtime +7 -delete
```

### Restore Procedures

If you need to restore from a backup:

```bash
ssh bitnami@16.52.222.15
cd /opt/bitnami/jaws/database

# List available backups
ls -lh jaws.backup.*

# Restore from backup (replace YYYYMMDD_HHMMSS with actual backup timestamp)
sudo cp jaws.backup.YYYYMMDD_HHMMSS.db jaws.db

# Set permissions
sudo chown bitnami:daemon jaws.db
sudo chmod 664 jaws.db

# Restart Apache
sudo /opt/bitnami/ctlscript.sh restart apache
```

### Querying the Production Database

**Read-only queries are safe:**

```bash
ssh bitnami@16.52.222.15
cd /opt/bitnami/jaws/database

# Query database
sqlite3 jaws.db "SELECT * FROM boats LIMIT 5;"
sqlite3 jaws.db "SELECT COUNT(*) FROM crews;"
sqlite3 jaws.db "SELECT event_id, event_date FROM events ORDER BY event_date;"
```

**Important:** Never run UPDATE/DELETE queries directly on production database. Use migrations instead.

---

## Cron Jobs

JAWS sends two types of automated email notifications via cron jobs:

| Notification | Timing | Recipients |
|---|---|---|
| **Crew Reminder** | ~24 hours before event start (23–25 h window) | All registered crew members individually |
| **Crew List** | On event day, within the first hour of the blackout window (default 10:00–11:00) | Admin (TO) + all boat owners with linked accounts (CC) |

Idempotency is enforced via the `cron_notifications` database table (UNIQUE constraint on `event_id + type`), so even if the cron fires multiple times in the same window, the email is only ever sent once.

### Initial Setup (One-Time)

#### 1. Create Logs Directory

```bash
mkdir -p /opt/bitnami/jaws/logs
sudo chown bitnami:daemon /opt/bitnami/jaws/logs
sudo chmod 775 /opt/bitnami/jaws/logs
```

#### 2. Set Script Executable (optional)

```bash
chmod +x /opt/bitnami/jaws/bin/notify.php
```

#### 3. Run the Migration

The `cron_notifications` table is created by Phinx migration. If you haven't already run migrations after this feature was deployed:

```bash
cd /opt/bitnami/jaws
vendor/bin/phinx migrate --environment=production
```

#### 4. Configure Crontab

```bash
crontab -e
```

Add the following two lines (run hourly; the script handles its own timing logic):

```bash
# JAWS: crew reminder email (~24h before event)
0 * * * * /usr/bin/php /opt/bitnami/jaws/bin/notify.php --type=reminder >> /opt/bitnami/jaws/logs/cron.log 2>&1

# JAWS: crew list email (on event day when blackout window opens)
0 * * * * /usr/bin/php /opt/bitnami/jaws/bin/notify.php --type=crew-list >> /opt/bitnami/jaws/logs/cron.log 2>&1
```

**Note:** `/usr/bin/php` is the Bitnami system PHP. Verify the path with `which php` if needed.

#### 5. Verify Required Environment Variables

Ensure the following is set in `/opt/bitnami/jaws/.env`:

```bash
ADMIN_NOTIFICATION_EMAIL=your-admin@example.com
```

This is the TO address for the crew list email. All other email settings (SMTP credentials, FROM address) are shared with the regular notification emails.

### How the Timing Windows Work

The script runs hourly but exits early unless conditions are met:

**Reminder (`--type=reminder`):**
- Checks if the next event starts between 23 and 25 hours from now
- This 2-hour window provides tolerance for hourly cron scheduling drift

**Crew List (`--type=crew-list`):**
- Checks if today is the event day
- Checks if the current time falls within `blackout_from` to `blackout_from + 1 hour` (as configured in `season_config`)
- Default window: 10:00–11:00 local time

### Manual Test Run

You can run the script manually to test without waiting for the cron:

```bash
cd /opt/bitnami/jaws

# Dry-run output only — script will exit if outside the timing window
php bin/notify.php --type=reminder
php bin/notify.php --type=crew-list
```

To force a send outside the normal window (e.g. for testing), temporarily comment out the timing check in `bin/notify.php`, run it, then revert.

**Important:** Delete the `cron_notifications` row for that event first if you want to re-send:

```bash
sqlite3 /opt/bitnami/jaws/database/jaws.db \
  "DELETE FROM cron_notifications WHERE event_id = 'Fri May 29' AND type = 'reminder';"
```

### Monitoring Cron Logs

```bash
# Live log tail
tail -f /opt/bitnami/jaws/logs/cron.log

# Show last 50 lines
tail -50 /opt/bitnami/jaws/logs/cron.log

# Show only sends (filter out early-exit lines)
grep "Done\." /opt/bitnami/jaws/logs/cron.log
```

**Sample log output:**

```
[2026-05-28 10:00:01] notify.php --type=reminder
  Sent reminder to Alice Smith (alice@example.com)
  Sent reminder to Bob Jones (bob@example.com)
Done. sent=2 skipped=0

[2026-05-29 10:00:02] notify.php --type=crew-list
  CC: owner@example.com (owner of Sailaway)
  Crew list sent to admin@nsc-sdc.ca with 1 CC recipient(s)
Done. sent=1 skipped=0
```

### Viewing Notification History

Query the `cron_notifications` table to see what has been sent:

```bash
sqlite3 /opt/bitnami/jaws/database/jaws.db \
  "SELECT event_id, type, sent_at, recipients_count, skipped_count FROM cron_notifications ORDER BY sent_at DESC LIMIT 20;"
```

### Log Rotation

Cron logs will grow over time. Set up log rotation:

```bash
sudo nano /etc/logrotate.d/jaws-cron
```

```
/opt/bitnami/jaws/logs/cron.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
}
```

---

## Monitoring

### Check Application Logs

**Structured application log** (JSON, written by JAWS directly):
```bash
# Today's log
tail -f /opt/bitnami/jaws/logs/app.log

# All rotated files
ls -lh /opt/bitnami/jaws/logs/app-*.log

# Filter by event type
grep '"event":"auth.login"' /opt/bitnami/jaws/logs/app-$(date +%Y-%m-%d).log | jq .
```

Log rotation is handled automatically by Monolog (`RotatingFileHandler`): 30 daily files are kept, named `app-YYYY-MM-DD.log`. No system-level logrotate configuration is needed for this file.

**Apache Error Log** (PHP fatal errors, Apache-level problems):
```bash
sudo tail -f /opt/bitnami/apache/logs/error_log
```

**Apache Access Log:**
```bash
sudo tail -f /opt/bitnami/apache/logs/access_log
```

**Filter Apache log for errors only:**
```bash
sudo grep -i error /opt/bitnami/apache/logs/error_log | tail -20
```

### Check Database Size

```bash
ls -lh /opt/bitnami/jaws/database/jaws.db
```

Monitor database growth over time to identify potential issues.

### Health Checks

**API Health Check:**
```bash
curl -s https://your-domain.com/api/events | jq '.success'
```

Expected output: `true`

**Database Connection Check:**
```bash
curl -s https://your-domain.com/api/events | grep -o '"success":[^,]*'
```

### Performance Monitoring

**Check Apache Process Count:**
```bash
ps aux | grep httpd | wc -l
```

**Check Memory Usage:**
```bash
free -h
```

**Check Disk Space:**
```bash
df -h /opt/bitnami/jaws
```

### Setting Up Alerts

Create a monitoring script that sends alerts when issues are detected:

**File:** `/home/bitnami/monitor.sh`

```bash
#!/bin/bash

# Check if API is responding
API_STATUS=$(curl -s -o /dev/null -w "%{http_code}" https://your-domain.com/api/events)

if [ "$API_STATUS" != "200" ]; then
    echo "API is down! Status: $API_STATUS" | mail -s "JAWS API Alert" admin@example.com
fi

# Check disk space
DISK_USAGE=$(df -h /opt/bitnami/jaws | awk 'NR==2 {print $5}' | sed 's/%//')

if [ "$DISK_USAGE" -gt 80 ]; then
    echo "Disk usage is at ${DISK_USAGE}%!" | mail -s "JAWS Disk Alert" admin@example.com
fi
```

Add to cron (run every 15 minutes):
```bash
*/15 * * * * /home/bitnami/monitor.sh
```

### Shipping Logs to CloudWatch Logs (Optional)

Forwarding `logs/app.log` to AWS CloudWatch Logs gives you searchable, alertable structured logs in the AWS Console with long-term retention.

#### 1. Install the CloudWatch Agent

```bash
wget https://s3.amazonaws.com/amazoncloudwatch-agent/debian/amd64/latest/amazon-cloudwatch-agent.deb
sudo dpkg -i amazon-cloudwatch-agent.deb
```

#### 2. Attach an IAM Role to the Lightsail Instance

The agent needs `logs:CreateLogGroup`, `logs:CreateLogStream`, and `logs:PutLogEvents` permissions. The easiest approach is to attach an IAM role with the `CloudWatchAgentServerPolicy` managed policy to your Lightsail instance. See the [AWS docs](https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/create-iam-roles-for-cloudwatch-agent.html) for details.

#### 3. Configure the Agent

Create the config file:

```bash
sudo nano /opt/aws/amazon-cloudwatch-agent/etc/amazon-cloudwatch-agent.json
```

```json
{
  "logs": {
    "logs_collected": {
      "files": {
        "collect_list": [
          {
            "file_path": "/opt/bitnami/jaws/logs/app-*.log",
            "log_group_name": "jaws/application",
            "log_stream_name": "{instance_id}",
            "timestamp_format": "%Y-%m-%dT%H:%M:%S",
            "multi_line_start_pattern": "^\\{"
          }
        ]
      }
    }
  }
}
```

The wildcard `app-*.log` picks up all rotated daily files automatically.

#### 4. Start the Agent

```bash
sudo /opt/aws/amazon-cloudwatch-agent/bin/amazon-cloudwatch-agent-ctl \
  -a fetch-config \
  -m ec2 \
  -c file:/opt/aws/amazon-cloudwatch-agent/etc/amazon-cloudwatch-agent.json \
  -s
```

Verify it is running:
```bash
sudo systemctl status amazon-cloudwatch-agent
```

#### 5. Query Logs in CloudWatch

In the AWS Console → CloudWatch → Log Insights, select log group `jaws/application`:

```
# All errors in the last hour
fields @timestamp, level, message, event
| filter level = "ERROR"
| sort @timestamp desc
| limit 50

# Failed logins
fields @timestamp, context.email, context.ip
| filter event = "auth.login_failed"
| sort @timestamp desc
```

---

## Rollback Procedures

If deployment fails or introduces critical bugs, follow these steps to rollback.

### Rollback Checklist

- [ ] Identify the issue (check logs, test endpoints)
- [ ] Determine rollback scope (code only, or code + database)
- [ ] Notify team/users if downtime is required
- [ ] Execute rollback procedures
- [ ] Verify system is working
- [ ] Document what went wrong

### Code Rollback

If the issue is in the code (not database):

```bash
ssh bitnami@16.52.222.15
cd /opt/bitnami/jaws

# Option 1: Checkout previous git commit (if using git on server)
git checkout <previous-commit-hash>

# Option 2: Re-upload previous version via SFTP
# (Upload previous src/, config/, public/index.php from local backup)

# Reinstall dependencies
composer install --no-dev --optimize-autoloader

# Restart Apache
sudo /opt/bitnami/ctlscript.sh restart apache
```

### Database Rollback

If a migration caused issues:

```bash
ssh bitnami@16.52.222.15
cd /opt/bitnami/jaws

# Option 1: Rollback last migration via Phinx
vendor/bin/phinx rollback --environment=production

# Option 2: Restore from backup
cd database
sudo cp jaws.backup.YYYYMMDD_HHMMSS.db jaws.db
sudo chown bitnami:daemon jaws.db
sudo chmod 664 jaws.db

# Restart Apache
sudo /opt/bitnami/ctlscript.sh restart apache
```

### Full System Rollback

If both code and database need to be rolled back:

```bash
ssh bitnami@16.52.222.15
cd /opt/bitnami/jaws

# 1. Restore database
sudo cp database/jaws.backup.YYYYMMDD_HHMMSS.db database/jaws.db
sudo chown bitnami:daemon database/jaws.db
sudo chmod 664 database/jaws.db

# 2. Restore code (via git or SFTP)
git checkout <previous-commit-hash>
# OR re-upload via SFTP

# 3. Reinstall dependencies
composer install --no-dev --optimize-autoloader

# 4. Restart Apache
sudo /opt/bitnami/ctlscript.sh restart apache

# 5. Verify
curl https://your-domain.com/api/events
```

### Post-Rollback

After rolling back:

1. **Verify** system is working correctly
2. **Document** what went wrong in deployment notes
3. **Fix** the issue in development environment
4. **Test** thoroughly before next deployment
5. **Update** deployment procedures if needed

---

## Troubleshooting

### Common Production Issues

#### Issue: "500 Internal Server Error"

**Possible Causes:**
- PHP syntax error
- Missing dependencies
- Database connection error
- File permission issues

**Solution:**

1. Check Apache error log:
   ```bash
   sudo tail -50 /opt/bitnami/apache/logs/error_log
   ```

2. Look for specific error message (syntax errors, file not found, etc.)

3. Verify file permissions:
   ```bash
   ls -la /opt/bitnami/jaws/src
   ls -la /opt/bitnami/jaws/database
   ```

4. Verify dependencies installed:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

#### Issue: "Database locked" error

**Cause:** Multiple processes trying to write to SQLite simultaneously

**Solution:**

1. Check for open connections:
   ```bash
   fuser /opt/bitnami/jaws/database/jaws.db
   ```

2. Restart Apache to clear connections:
   ```bash
   sudo /opt/bitnami/ctlscript.sh restart apache
   ```

3. If problem persists, verify WAL mode is enabled:
   ```bash
   sqlite3 /opt/bitnami/jaws/database/jaws.db "PRAGMA journal_mode;"
   ```
   Should return: `wal`

#### Issue: "Permission denied" when accessing database

**Cause:** Incorrect file permissions

**Solution:**

```bash
sudo chown bitnami:daemon /opt/bitnami/jaws/database/jaws.db
sudo chmod 664 /opt/bitnami/jaws/database/jaws.db
sudo chown bitnami:daemon /opt/bitnami/jaws/database
sudo chmod 775 /opt/bitnami/jaws/database
```

#### Issue: "JWT token invalid" after deployment

**Cause:** JWT_SECRET changed or token format changed

**Solution:**

1. Verify `JWT_SECRET` in `.env` hasn't changed
2. If it changed, all users need to login again to get new tokens
3. Clear any cached tokens on frontend

#### Issue: "Authentication not working after deployment"

**Cause:** Authorization header not being forwarded to PHP, or JWT configuration issues

**Solution:**

1. Visit `https://your-domain.com/app/debug.html` to diagnose the issue
2. Check **"Token Present"** status - should show if authentication token exists
3. Check **"API Connection"** status - tests live connectivity to the API
4. Verify Authorization header forwarding in `.htaccess`:
   ```apache
   RewriteCond %{HTTP:Authorization} .
   RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
   ```
5. If the header forwarding rule is missing, JWT authentication will fail silently
6. Ensure `AllowOverride All` is set in the Apache virtual host configuration
7. Restart Apache after any `.htaccess` changes:
   ```bash
   sudo /opt/bitnami/ctlscript.sh restart apache
   ```

#### Issue: Email notifications not sending

**Possible Causes:**

- Mailjet API credentials missing or incorrect
- Sender domain/address not verified in Mailjet
- Mailjet account is in sandbox mode (outbound blocked)

**Solution:**

1. Check Mailjet credentials in `.env`:
   ```bash
   cat /opt/bitnami/jaws/.env | grep MJ_
   ```

2. Verify the keys are valid by logging into [app.mailjet.com](https://app.mailjet.com) → Account Settings → API Key Management.

3. Check Apache error log for Mailjet response status codes:
   ```bash
   tail -f /opt/bitnami/apache/logs/error_log | grep -i "email send"
   ```
   A non-2xx status (e.g. 401 = bad credentials, 403 = domain not verified) will be logged.

4. Ensure the sender address (`EMAIL_FROM`) is a verified sender in your Mailjet account under **Sender domains & addresses**.

5. Confirm the Mailjet account is not in sandbox/test mode, which blocks real outbound delivery.

#### Issue: Frontend not loading

**Possible Causes:**
- Apache configuration incorrect
- .htaccess not working
- File permissions

**Solution:**

1. Verify Apache virtual host configuration:
   ```bash
   sudo cat /opt/bitnami/apache2/conf/vhosts/jaws-vhost.conf
   ```

2. Verify .htaccess exists:
   ```bash
   cat /opt/bitnami/jaws/public/.htaccess
   ```

3. Test Apache rewrite module:
   ```bash
   sudo apachectl -M | grep rewrite
   ```
   Should see: `rewrite_module (shared)`

4. Restart Apache:
   ```bash
   sudo /opt/bitnami/ctlscript.sh restart apache
   ```

---

## Next Steps

Now that you understand JAWS deployment:

✅ Deployment complete!
➡️ **Next:** Set up [monitoring alerts](#setting-up-alerts) for proactive issue detection

✅ Production running!
➡️ **Next:** Review [Database Management](../database/README.md) for ongoing maintenance

✅ Rollback plan ready!
➡️ **Next:** Document your deployment process in team wiki

---

📖 **Additional Resources:**

- [Setup Guide](SETUP.md) - Local development setup
- [Database README](../database/README.md) - Database management
- [API Reference](API.md) - API endpoint documentation
- [Developer Guide](DEVELOPER_GUIDE.md) - Architecture and patterns
