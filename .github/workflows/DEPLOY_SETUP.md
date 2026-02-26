# GitHub Actions Deployment Setup

This guide explains how to set up automated deployment to AWS Lightsail using GitHub Actions.

## Overview

The `deploy.yml` workflow allows you to deploy JAWS to AWS Lightsail with a single click. It includes:

- ✅ Pre-deployment tests and validation
- ✅ Automatic database backup
- ✅ File upload and extraction
- ✅ Dependency installation
- ✅ Optional database migrations
- ✅ Proper permission setting
- ✅ Apache restart
- ✅ Deployment verification
- ✅ Detailed deployment summary

## Required GitHub Secrets

Before using the deployment workflow, you must configure the following secrets in your GitHub repository:

**Settings → Secrets and variables → Actions → New repository secret**

### Secrets to Configure

| Secret Name | Description | Example |
|------------|-------------|---------|
| `LIGHTSAIL_SSH_KEY` | Private SSH key for authentication | Contents of `LightsailDefaultKey-ca-central-1.pem` |
| `LIGHTSAIL_HOST` | Server IP address or hostname | `16.52.222.15` |
| `LIGHTSAIL_USER` | SSH username | `bitnami` |

### Optional Staging Secrets (Required if using `staging` environment)

| Secret Name | Description | Example |
|------------|-------------|---------|
| `LIGHTSAIL_SSH_KEY_STAGING` | Staging private SSH key | Contents of staging PEM key |
| `LIGHTSAIL_HOST_STAGING` | Staging server IP/hostname | `15.156.254.113` |
| `LIGHTSAIL_USER_STAGING` | Staging SSH username | `bitnami` |

If you select `staging` in the workflow and these staging secrets are not configured, the workflow fails fast with a clear error.

### How to Get the SSH Key

1. **Locate your Lightsail SSH key file:**
   ```bash
   # On Windows
   C:\Users\YourUsername\.ssh\LightsailDefaultKey-ca-central-1.pem

   # On Mac/Linux
   ~/.ssh/LightsailDefaultKey-ca-central-1.pem
   ```

2. **Copy the entire contents of the key file:**
   ```bash
   # On Mac/Linux
   cat ~/.ssh/LightsailDefaultKey-ca-central-1.pem | pbcopy

   # On Windows (PowerShell)
   Get-Content LightsailDefaultKey-ca-central-1.pem | Set-Clipboard
   ```

3. **Paste into GitHub:**
   - Go to your repository
   - Settings → Secrets and variables → Actions
   - Click "New repository secret"
   - Name: `LIGHTSAIL_SSH_KEY`
   - Value: Paste the entire key (including `-----BEGIN RSA PRIVATE KEY-----` and `-----END RSA PRIVATE KEY-----`)
   - Click "Add secret"

### Setting Up Other Secrets

**LIGHTSAIL_HOST:**
- Go to AWS Lightsail console
- Find your instance's public IP address
- Add as secret with name `LIGHTSAIL_HOST`

**LIGHTSAIL_USER:**
- For Bitnami LAMP stack, this is typically `bitnami`
- Add as secret with name `LIGHTSAIL_USER`

## How to Deploy

### Manual Deployment (Recommended)

1. **Go to GitHub Actions tab** in your repository

2. **Select "Deploy to AWS Lightsail"** workflow from the left sidebar

3. **Click "Run workflow"** button

4. **Configure deployment options:**
   - **Use workflow from:** `main` (or your branch)
   - **Run database migrations:** Check if you have new migrations
   - **Skip database backup:** Leave unchecked (backup is recommended)
   - **Deployment environment:** Select `production` or `staging`

5. **Click "Run workflow"** to start deployment

6. **Monitor progress:**
   - Watch the workflow execution in real-time
   - Check the summary at the end
   - Verify deployment was successful

### Deployment Options Explained

**Deployment environment (`production` vs `staging`):**
- ✅ The workflow now routes SSH host/user/key based on this selection
- ✅ `production` uses `LIGHTSAIL_HOST`, `LIGHTSAIL_USER`, `LIGHTSAIL_SSH_KEY`
- ✅ `staging` uses `LIGHTSAIL_HOST_STAGING`, `LIGHTSAIL_USER_STAGING`, `LIGHTSAIL_SSH_KEY_STAGING`
- ✅ The selected value is also used as the GitHub job environment (`environment: ${{ inputs.environment }}`), enabling optional approval gates and environment protection rules

**Run database migrations after deployment:**
- ✅ Check this if you have new migration files in `database/migrations/`
- ⚠️ Always test migrations locally first
- ℹ️ Migrations are automatically backed up before running

**Skip database backup (NOT RECOMMENDED):**
- ⚠️ Only use this for non-production testing
- ✅ Always keep unchecked for production deployments
- ℹ️ Backups are named with timestamp: `jaws.backup.YYYYMMDD_HHMMSS.db`

**Deployment environment:**
- `production` - Live production server (default)
- `staging` - Staging/test server (if configured)

## Workflow Stages

The deployment workflow executes in 5 stages:

### 1. Pre-Deployment Checks
- ✅ Runs unit tests
- ✅ Validates PHP syntax in config files
- ✅ Checks migration files exist (if migrations enabled)

### 2. Backup Database
- ✅ Creates timestamped backup: `jaws.backup.YYYYMMDD_HHMMSS.db`
- ✅ Verifies backup was created
- ✅ Lists recent backups for reference

### 3. Deploy Application
- ✅ Creates deployment package (tar.gz)
- ✅ Uploads to server via SCP
- ✅ Extracts files to `/opt/bitnami/jaws/`
- ✅ Installs Composer dependencies
- ✅ Runs database migrations (if enabled)
- ✅ Sets proper file permissions
- ✅ Restarts Apache

### 4. Verify Deployment
- ✅ Waits 10 seconds for server to stabilize
- ✅ Tests API health endpoint
- ✅ Validates response format
- ✅ Shows Apache logs if verification fails

### 5. Post-Deployment Notification
- ✅ Creates deployment summary
- ✅ Shows deployment status
- ✅ Provides next steps checklist

## Post-Deployment Verification

After deployment completes, manually verify:

### 1. Check API Health
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

### 2. Test Authentication
- Visit: `https://your-domain.com/app/account.html`
- Log in with test credentials
- Verify token is accepted

### 3. Use Debug Page
Visit: `https://your-domain.com/app/debug.html`

Check:
- ✅ Token Present: Should show if authenticated
- ✅ Environment: Should show "production"
- ✅ API Connection: Should show "Connected"

### 4. Monitor Apache Logs
```bash
ssh bitnami@16.52.222.15
sudo tail -f /opt/bitnami/apache/logs/jaws-error.log
```

Watch for any errors during first requests.

### 5. Test Critical Features
- [ ] Login works
- [ ] Availability updates work
- [ ] Assignments load correctly
- [ ] Admin features accessible (if applicable)

## Troubleshooting

### Deployment Failed at "Pre-Deployment Checks"

**Cause:** Tests are failing or configuration files have syntax errors

**Solution:**
1. Run tests locally: `./vendor/bin/phpunit`
2. Fix any failing tests
3. Validate PHP syntax: `php -l config/config.php`
4. Commit fixes and re-run deployment

### Deployment Failed at "Backup Database"

**Cause:** SSH connection failed or database file not accessible

**Solution:**
1. Verify SSH key is correct in GitHub secrets
2. Check server is accessible: `ssh bitnami@16.52.222.15`
3. Verify database path: `/opt/bitnami/jaws/database/jaws.db`

### Deployment Failed at "Deploy Application"

**Cause:** File upload or extraction error

**Solution:**
1. Check SSH connection is stable
2. Verify server has enough disk space: `df -h`
3. Check permissions on `/opt/bitnami/jaws/`
4. Review workflow logs for specific error

### Deployment Failed at "Verify Deployment"

**Cause:** API not responding or returning errors

**Solution:**
1. SSH into server: `ssh bitnami@16.52.222.15`
2. Check Apache logs:
   ```bash
   sudo tail -50 /opt/bitnami/apache/logs/jaws-error.log
   ```
3. Verify Apache is running:
   ```bash
   sudo /opt/bitnami/ctlscript.sh status apache
   ```
4. Check file permissions:
   ```bash
   ls -la /opt/bitnami/jaws/database/
   ```
5. If needed, follow rollback procedures (see below)

### Migrations Failed

**Cause:** Migration file has errors or database locked

**Solution:**
1. SSH into server
2. Check Phinx status:
   ```bash
   cd /opt/bitnami/jaws
   vendor/bin/phinx status --environment=production
   ```
3. If migration partially applied, rollback:
   ```bash
   vendor/bin/phinx rollback --environment=production
   ```
4. Fix migration file locally
5. Test locally before re-deploying
6. Re-run deployment with migrations enabled

## Rollback Procedures

If deployment introduced issues:

### Quick Rollback (Code Only)

If only code has issues (not database):

1. Go to GitHub Actions
2. Find the last successful deployment
3. Click "Re-run all jobs"
4. This will deploy the previous working version

### Database Rollback

If migration caused issues:

```bash
ssh bitnami@16.52.222.15
cd /opt/bitnami/jaws/database

# List available backups
ls -lh jaws.backup.*

# Restore from backup (replace with actual timestamp)
sudo cp jaws.backup.20260208_143022.db jaws.db
sudo chown bitnami:daemon jaws.db
sudo chmod 664 jaws.db

# Restart Apache
sudo /opt/bitnami/ctlscript.sh restart apache
```

### Full System Rollback

If both code and database need rollback:

1. Restore database (see above)
2. Re-deploy previous version from GitHub Actions
3. Verify system is working

## Security Best Practices

### SSH Key Security

- ✅ Store SSH key only in GitHub secrets (never commit to repo)
- ✅ Use repository-level secrets (not organization-level)
- ✅ Rotate SSH keys periodically
- ✅ Limit GitHub Actions permissions to minimum required

### Deployment Security

- ✅ Always review workflow logs for sensitive data exposure
- ✅ Never log environment variables or secrets
- ✅ Keep `APP_DEBUG=false` in production `.env`
- ✅ Verify `.env` file permissions: `chmod 640 .env`

### Access Control

- ✅ Limit who can trigger deployments (use GitHub branch protection)
- ✅ Require pull request reviews before merging to main
- ✅ Enable two-factor authentication for GitHub accounts
- ✅ Audit deployment logs regularly

## Monitoring Deployments

### View Deployment History

1. Go to **Actions** tab in GitHub
2. Filter by workflow: "Deploy to AWS Lightsail"
3. Click on any deployment to see:
   - Duration
   - Which jobs succeeded/failed
   - Full logs for each step
   - Deployment summary

### Set Up Notifications

Configure notifications for deployment events:

1. Go to **Settings → Notifications**
2. Enable notifications for:
   - ✅ Actions workflow runs
   - ✅ Failed deployments
   - ✅ Successful deployments (optional)

## Advanced Configuration

### Deploy to Staging Environment

Staging support is already wired into the workflow.

1. **Create staging server secrets:**
   - `LIGHTSAIL_HOST_STAGING`
   - `LIGHTSAIL_USER_STAGING`
   - `LIGHTSAIL_SSH_KEY_STAGING` (if different)

2. **Run the workflow and select `staging` as the deployment environment.**

3. **Optional:** configure GitHub environment protection for `staging` (or `production`) under Settings → Environments.

### Scheduled Deployments

To deploy automatically on schedule:

```yaml
on:
  workflow_dispatch:
    # ... existing inputs ...
  schedule:
    - cron: '0 2 * * 1'  # Every Monday at 2 AM
```

### Deployment Approval

To require manual approval:

```yaml
deploy:
  name: Deploy Application
  runs-on: ubuntu-latest
  needs: [pre-deployment-checks, backup-database]
  environment:
    name: production
    url: https://your-domain.com
```

Then configure environment protection rules in GitHub:
- Settings → Environments → production → Required reviewers

## Comparison with Manual Deployment

| Aspect | Manual (SFTP) | Automated (GitHub Actions) |
|--------|---------------|---------------------------|
| **Time** | 10-15 minutes | 5-7 minutes |
| **Consistency** | Manual steps, prone to errors | Automated, consistent every time |
| **Testing** | Manual, often skipped | Automated pre-deployment tests |
| **Backup** | Manual, sometimes forgotten | Automatic, never skipped |
| **Verification** | Manual, time-consuming | Automatic health checks |
| **Rollback** | Manual, error-prone | Easy re-run of previous version |
| **Audit Trail** | None | Full logs, timestamps, who deployed |
| **Documentation** | Static docs | Self-documenting workflow |

## Next Steps

✅ **Setup Complete!**

Now you can:
1. Test deployment to staging (if configured)
2. Perform a dry-run deployment to production
3. Set up deployment notifications
4. Configure branch protection rules
5. Document your deployment schedule

## Related Documentation

- **[DEPLOYMENT.md](../../docs/DEPLOYMENT.md)** - Manual deployment procedures
- **[DEVELOPER_GUIDE.md](../../docs/DEVELOPER_GUIDE.md)** - Development workflow
- **[CI/CD Documentation](.github/workflows/ci.yml)** - Continuous integration setup

## Support

If you encounter issues:

1. Review the workflow logs in GitHub Actions
2. Check the [Troubleshooting](#troubleshooting) section above
3. Consult [DEPLOYMENT.md](../../docs/DEPLOYMENT.md) for manual deployment steps
4. Create an issue in the GitHub repository
