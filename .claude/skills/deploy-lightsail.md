# Deploy to AWS Lightsail Skill

Step-by-step deployment guide for the JAWS application to AWS Lightsail production environment.

Primary deployment path is the GitHub Actions workflow in `.github/workflows/deploy.yml` (`workflow_dispatch`). Manual SSH/SFTP steps below are fallback procedures.

**Note:** See [docs/DEPLOYMENT.md](../../docs/DEPLOYMENT.md) for complete deployment documentation.

## Prerequisites

- SSH key configured: `LightsailDefaultKey-ca-central-1.pem`
- Production server: `bitnami@16.52.222.15`
- Local changes committed and tested
- All tests passing locally

## Deployment Steps

### 0. Preferred: Run Deployment Workflow

Use GitHub Actions `Deploy to AWS Lightsail` workflow with inputs:
- `environment` (`staging` or `production`)
- `run_migrations` (as needed)
- `skip_backup` (normally `false`)

This path includes pre-deployment checks and API test gating.

### 1. Upload Files via SFTP

```bash
# Add SSH key to agent
ssh-add LightsailDefaultKey-ca-central-1.pem

# Start SFTP session
sftp bitnami@16.52.222.15

# Navigate to application directory
cd /./opt/bitnami/jaws

# Upload directories
put -r public
put -r src
put -r config

# Upload specific files
put composer.json
put composer.lock
put phinx.php

# Upload migrations
put -r database/migrations

# Exit SFTP
bye
```

### 2. Set Permissions and Install Dependencies

```bash
# SSH into server
ssh bitnami@16.52.222.15

# Navigate to application directory
cd /opt/bitnami/jaws

# Set ownership
sudo chown -R bitnami:daemon /opt/bitnami/jaws

# Install dependencies (production mode)
composer install --optimize-autoloader --no-dev

# Set database permissions
sudo chmod 775 database
sudo chmod 664 database/jaws.db

# Restart Apache
sudo /opt/bitnami/ctlscript.sh restart apache
```

### 3. Run Database Migrations (if needed)

```bash
# Still connected via SSH
cd /opt/bitnami/jaws

# Backup database first!
cp database/jaws.db database/jaws.backup.$(date +%Y%m%d_%H%M%S).db

# Run migrations
vendor/bin/phinx migrate -e production

# Verify
vendor/bin/phinx status -e production
```

### 4. Verify Deployment

```bash
# Check Apache status
sudo /opt/bitnami/ctlscript.sh status apache

# Check logs for errors
sudo tail -f /opt/bitnami/apache/logs/error_log

# Test API endpoint
curl https://nsc-sdc.ca/api/events

# Exit SSH
exit
```

## Quick Deploy Script

For routine deployments without database changes:

```bash
#!/bin/bash
set -e

echo "🚀 Starting JAWS deployment..."

# Upload files
ssh-add LightsailDefaultKey-ca-central-1.pem
sftp bitnami@16.52.222.15 <<EOF
cd /./opt/bitnami/jaws
put -r public
put -r src
put -r config
put composer.json
put composer.lock
bye
EOF

# Set permissions and restart
ssh bitnami@16.52.222.15 <<'EOF'
cd /opt/bitnami/jaws
sudo chown -R bitnami:daemon /opt/bitnami/jaws
composer install --optimize-autoloader --no-dev
sudo /opt/bitnami/ctlscript.sh restart apache
EOF

echo "✅ Deployment complete!"
```

## Pre-Deployment Checklist

- [ ] All tests passing (`./vendor/bin/phpunit`)
- [ ] Changes committed to Git
- [ ] Environment variables configured on server (`.env`)
- [ ] Database backup strategy confirmed
- [ ] No breaking API changes (or clients notified)
- [ ] Migration tested locally (if database changes)
- [ ] Prefer workflow run over manual upload when possible

## Post-Deployment Checklist

- [ ] API endpoints responding (`curl https://nsc-sdc.ca/api/events`)
- [ ] Frontend loading correctly
- [ ] Authentication working
- [ ] Database migrations applied successfully
- [ ] No errors in Apache logs
- [ ] Monitor for 10-15 minutes after deployment

## Rollback Procedure

If deployment fails:

### 1. Restore Database (if migrations were run)

```bash
ssh bitnami@16.52.222.15
cd /opt/bitnami/jaws/database
cp jaws.backup.YYYYMMDD_HHMMSS.db jaws.db
```

### 2. Revert Code (via Git)

```bash
ssh bitnami@16.52.222.15
cd /opt/bitnami/jaws
git reset --hard <previous-commit-hash>
composer install --no-dev
sudo /opt/bitnami/ctlscript.sh restart apache
```

## Common Issues

### Permission Denied

```bash
sudo chown -R bitnami:daemon /opt/bitnami/jaws
sudo chmod 775 database
sudo chmod 664 database/jaws.db
```

### Apache Won't Start

```bash
# Check logs
sudo tail -50 /opt/bitnami/apache/logs/error_log

# Check Apache config
sudo apachectl configtest

# Restart
sudo /opt/bitnami/ctlscript.sh restart apache
```

### Database Connection Failed

```bash
# Verify database file exists
ls -la /opt/bitnami/jaws/database/jaws.db

# Check permissions
ls -la /opt/bitnami/jaws/database/

# Verify .env configuration
cat /opt/bitnami/jaws/.env | grep DB_PATH
```

### Composer Install Fails

```bash
# Clear composer cache
composer clear-cache

# Install with verbose output
composer install --optimize-autoloader --no-dev -vvv
```

## Environment Configuration

Ensure these environment variables are set in `/opt/bitnami/jaws/.env`:

```bash
APP_ENV=production
APP_DEBUG=false
DB_PATH=/opt/bitnami/jaws/database/jaws.db
JWT_SECRET=<production-secret>
SMTP_HOST=email-smtp.ca-central-1.amazonaws.com
SMTP_USERNAME=<aws-smtp-username>
SMTP_PASSWORD=<aws-smtp-password>
EMAIL_FROM=noreply@nsc-sdc.ca
CORS_ALLOWED_ORIGINS=https://nsc-sdc.ca
```

## Monitoring

### Check Application Health

```bash
# API health check
curl -I https://nsc-sdc.ca/api/events

# Check response time
time curl https://nsc-sdc.ca/api/events
```

### Monitor Logs

```bash
# Real-time error log
ssh bitnami@16.52.222.15 'sudo tail -f /opt/bitnami/apache/logs/error_log'

# Real-time access log
ssh bitnami@16.52.222.15 'sudo tail -f /opt/bitnami/apache/logs/access_log'
```

## Security Notes

- Never commit `.env` file with production secrets
- Keep SSH key secure (`LightsailDefaultKey-ca-central-1.pem`)
- Use `--no-dev` flag in production to exclude dev dependencies
- Always use HTTPS in production
- Regularly update dependencies for security patches
- Monitor logs for suspicious activity

## Additional Resources

- **Full Deployment Guide:** `docs/DEPLOYMENT.md`
- **Server Access:** Contact team lead for SSH key
- **AWS Console:** https://lightsail.aws.amazon.com/
- **Production URL:** https://nsc-sdc.ca
