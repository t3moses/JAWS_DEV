# Troubleshooting Skill

Common issues and solutions for JAWS development.

## Database Issues

### Database Locked Error

**Symptom:** `database is locked` error when running queries

**Causes:**
- Another process has an open connection
- Uncommitted transaction
- Dev server holding connection

**Solutions:**

**Windows PowerShell:**
```powershell
# Stop process using local API port (common DB lock source)
Get-NetTCPConnection -LocalPort 8000 -ErrorAction SilentlyContinue |
   ForEach-Object { Stop-Process -Id $_.OwningProcess -Force }

# Then start fresh
php -S localhost:8000 -t public
```

**Mac/Linux:**
```bash
# Check for active connections
lsof database/jaws.db

# Force close (be careful!)
fuser -k database/jaws.db

# Restart dev server
# Kill existing server first
lsof -ti:8000 | xargs kill -9
# Then start fresh
php -S localhost:8000 -t public
```

**Prevention:**
- Close DB connections in finally blocks
- Use WAL mode (already enabled in Connection.php)
- Don't leave transactions uncommitted

---

### Database Permission Errors

**Symptom:** Cannot write to database / Permission denied

**Development:**
```bash
chmod 664 database/jaws.db
chmod 775 database/
```

**Production:**
```bash
sudo chown bitnami:daemon /opt/bitnami/jaws/database/jaws.db
sudo chmod 664 /opt/bitnami/jaws/database/jaws.db
sudo chmod 775 /opt/bitnami/jaws/database/
```

---

### Migration Fails

**Symptom:** Migration throws errors

**Solutions:**

**Check migration status:**
```bash
vendor/bin/phinx status
```

**Rollback and retry:**
```bash
vendor/bin/phinx rollback
vendor/bin/phinx migrate
```

**Manual fix:**
```bash
# Check what went wrong
sqlite3 database/jaws.db ".schema table_name"

# If needed, manually fix
sqlite3 database/jaws.db "DROP TABLE IF EXISTS problem_table;"
```

**For complex schema changes:**
- Use create-copy-drop pattern (SQLite limitation)
- See `/modify-schema` skill

---

### Corrupted Database

**Symptom:** Database queries fail unexpectedly

**Check integrity:**
```bash
sqlite3 database/jaws.db "PRAGMA integrity_check;"
```

**If corrupted, dump and restore:**
```bash
sqlite3 database/jaws.db ".dump" | sqlite3 database/jaws.new.db
mv database/jaws.db database/jaws.corrupted.db
mv database/jaws.new.db database/jaws.db
```

---

## Test Failures

### Unit Tests Failing

**Symptom:** Tests that should pass are failing

**Common causes:**

**1. Deterministic behavior broken:**
```bash
# Check if selection/assignment algorithms produce consistent results
./vendor/bin/phpunit tests/Unit/Domain/Service/SelectionServiceTest.php
```

**2. Dependencies not installed:**
```bash
composer install
```

**3. PHP version mismatch:**
```bash
php -v  # Should be 8.1+
```

---

### Integration Tests Failing

**Symptom:** Tests fail with database errors

**Solutions:**

**Check Phinx migrations work:**
```bash
# Integration tests use in-memory DB with Phinx
# Verify migrations run cleanly
rm -f database/test.db
vendor/bin/phinx migrate -e development
```

**Base class not extended:**
- Integration tests MUST extend `IntegrationTestCase`
- This provides automatic Phinx migration setup

**Missing test data:**
- Use base class utilities: `createTestEvent()`, `createTestUser()`

---

### API Tests Failing

**Symptom:** API test suite fails

**Check dev server:**
```powershell
# PowerShell
Get-NetTCPConnection -LocalPort 8000 -ErrorAction SilentlyContinue

# If not running, start it
$p = Start-Process php -ArgumentList '-S','localhost:8000','-t','public' -PassThru
Start-Sleep -Seconds 2
```

Or on Mac/Linux:
```bash
# API tests need dev server on port 8000
lsof -i :8000

# If not running, start it
php -S localhost:8000 -t public &
```

**Check JWT_SECRET:**
```powershell
# PowerShell
$env:JWT_SECRET

# If missing, set it
$env:JWT_SECRET = "your-test-secret-min-32-chars"
```

Or on Mac/Linux:
```bash
# API tests need valid JWT_SECRET
echo $JWT_SECRET

# If missing, set it
export JWT_SECRET="your-test-secret-min-32-chars"
```

**Database seeded:**
```bash
vendor/bin/phinx seed:run
```

---

## Apache/Server Issues

### Apache Won't Start

**Check logs:**
```bash
sudo tail -50 /opt/bitnami/apache/logs/error_log
```

**Check config:**
```bash
sudo apachectl configtest
```

**Restart:**
```bash
sudo /opt/bitnami/ctlscript.sh restart apache
```

---

### 404 on All API Endpoints

**Symptom:** All `/api/*` routes return 404

**Causes:**
- `.htaccess` not working
- `mod_rewrite` not enabled
- Wrong document root

**Solutions:**

**Check .htaccess exists:**
```bash
ls -la public/.htaccess
```

**Verify mod_rewrite:**
```bash
apache2ctl -M | grep rewrite
```

**Check document root:**
- Should point to `/path/to/jaws/public/`
- NOT `/path/to/jaws/`

---

### 500 Internal Server Error

**Check error logs:**
```bash
# Development
tail -f /var/log/apache2/error.log

# Production
ssh bitnami@16.52.222.15 'sudo tail -f /opt/bitnami/apache/logs/error_log'
```

**Common causes:**
- PHP syntax error (check logs)
- Missing dependency (run `composer install`)
- Database connection error (check permissions)
- Missing .env file (check environment variables)

---

## Composer Issues

### Composer Install Fails

**Clear cache:**
```bash
composer clear-cache
```

**Update composer:**
```bash
composer self-update
```

**Verbose output:**
```bash
composer install -vvv
```

**Memory limit:**
```bash
php -d memory_limit=-1 /usr/bin/composer install
```

---

## Git Issues

### Pre-commit Hook Fails

**Symptom:** Commit blocked by hook

**Don't skip hooks (unless explicitly approved):**
```bash
# DON'T DO THIS without understanding why hook failed
git commit --no-verify
```

**Instead, fix the issue:**
1. Check what hook failed
2. Fix the underlying problem
3. Stage fixes
4. Commit normally

**Common causes:**
- Linting errors (fix code style)
- Test failures (fix failing tests)
- Security issues (fix vulnerabilities)

---

### Merge Conflicts

**Check conflicts:**
```bash
git status
```

**Resolve conflicts:**
1. Open conflicted files
2. Look for `<<<<<<<`, `=======`, `>>>>>>>`
3. Edit to keep desired changes
4. Remove conflict markers
5. Stage resolved files: `git add <file>`
6. Continue: `git merge --continue` or `git rebase --continue`

**Abort if needed:**
```bash
git merge --abort
# or
git rebase --abort
```

---

## PHP Issues

### Class Not Found

**Symptom:** `Class 'App\...' not found`

**Solutions:**

**Regenerate autoload:**
```bash
composer dump-autoload
```

**Check namespace:**
- Namespace should match directory structure
- `App\Domain\Entity\Boat` → `src/Domain/Entity/Boat.php`

**Check class name:**
- Class name must match filename
- Case-sensitive on Linux

---

### Memory Exhausted

**Symptom:** `Fatal error: Allowed memory size exhausted`

**Increase memory limit:**
```bash
# Temporary (command-line)
php -d memory_limit=512M script.php

# PHP.ini (permanent)
memory_limit = 512M
```

**Optimize code:**
- Process large datasets in chunks
- Use generators for large result sets
- Unset large variables when done

---

## Authentication Issues

### JWT Token Invalid

**Symptom:** 401 Unauthorized on authenticated endpoints

**Check JWT_SECRET:**
```bash
# Must be same secret used to generate token
echo $JWT_SECRET
```

**Check token expiration:**
- Default: 60 minutes
- Token may have expired
- Login again to get fresh token

**Check header format:**
```bash
curl -H "Authorization: Bearer YOUR_TOKEN" http://localhost:8000/api/users/me
```

---

### User Not Found

**Symptom:** `UserNotFoundException` on login

**Check if user exists:**
```bash
sqlite3 database/jaws.db "SELECT * FROM users WHERE email = 'user@example.com';"
```

**Register user:**
```bash
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password123"}'
```

---

## Performance Issues

### Slow Queries

**Enable query logging:**
- Add logging to `Connection.php`
- Use SQLite `EXPLAIN QUERY PLAN`

**Check indexes:**
```bash
sqlite3 database/jaws.db "PRAGMA index_list(table_name);"
```

**Optimize:**
- Add indexes on frequently queried columns
- Use `EXPLAIN QUERY PLAN` to analyze
- Consider denormalization for read-heavy queries

---

### Slow Tests

**Symptom:** Test suite takes too long

**Solutions:**

**Run only what you need:**
```bash
# Unit tests only (fastest)
./vendor/bin/phpunit tests/Unit

# Specific test
./vendor/bin/phpunit tests/Unit/Domain/Service/SelectionServiceTest.php
```

**Parallel testing:**
```bash
# Not currently implemented, but could use paratest
composer require --dev brianium/paratest
vendor/bin/paratest
```

---

## CI/CD Issues

### GitHub Actions Failing

**Check logs:**
- Go to Actions tab in GitHub
- Click on failed workflow
- Review job logs

**Common issues:**

**Unit tests fail on push:**
- Fix unit tests locally first
- Run `./vendor/bin/phpunit tests/Unit`

**Integration tests fail on PR:**
- Check database setup job
- Verify Phinx migrations work

**API tests fail on PR:**
- Check if dev server starts correctly
- Verify seeds run successfully

---

## Production Issues

### Deployment Failed

**Check deployment log:**
- Review SFTP upload errors
- Check SSH connection
- Verify file permissions

**Rollback:**
```bash
ssh bitnami@16.52.222.15
cd /opt/bitnami/jaws
git reset --hard <previous-commit-hash>
composer install --no-dev
sudo /opt/bitnami/ctlscript.sh restart apache
```

**For database rollback:**
```bash
cd /opt/bitnami/jaws/database
cp jaws.backup.YYYYMMDD_HHMMSS.db jaws.db
```

---

### Production Data Issue

**NEVER directly modify production database unless:**
1. You have a backup
2. You've tested the change locally
3. You understand the impact

**Safe procedure:**
1. Backup production DB
2. Download backup to local
3. Test fix on local copy
4. Create migration if schema change
5. Deploy migration properly

---

## Getting Help

If none of these solutions work:

1. **Check logs:**
   - Development: Browser console + PHP errors
   - Production: Apache error logs

2. **Search the codebase:**
   - Use Grep skill: `/database-ops`
   - Check similar implementations

3. **Review documentation:**
   - `docs/DEVELOPER_GUIDE.md`
   - `CLAUDE.md`
   - Skills in `.claude/skills/`

4. **Ask for help:**
   - Provide error messages
   - Show what you've tried
   - Include relevant logs

---

## Prevention Tips

**Development:**
- Run tests before committing
- Use proper error handling
- Check logs regularly
- Keep dependencies updated

**Database:**
- Always backup before migrations
- Test migrations locally first
- Use Phinx for all schema changes
- Never bypass migrations

**Git:**
- Commit often with good messages
- Don't skip pre-commit hooks
- Resolve conflicts carefully
- Keep branches up to date

**Production:**
- Test in development first
- Have rollback plan ready
- Monitor logs after deployment
- Keep backups current
