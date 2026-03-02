# Database Operations Skill

Common database operations for the JAWS project using SQLite and Phinx migrations.

## Apply Migrations

Run pending database migrations:

```bash
vendor/bin/phinx migrate
```

**Check migration status:**
```bash
vendor/bin/phinx status
```

**Rollback last migration:**
```bash
vendor/bin/phinx rollback
```

## Backup Database

Create a timestamped backup:

```bash
cp database/jaws.db database/jaws.backup.$(date +%Y%m%d_%H%M%S).db
```

**List existing backups:**
```bash
ls -lh database/jaws.backup.*.db
```

**Restore from backup:**
```bash
# Replace current database with backup
cp database/jaws.backup.20260216120000.db database/jaws.db
```

## Query Database

### Interactive Mode

```bash
sqlite3 database/jaws.db
```

Common SQLite commands:
- `.tables` - List all tables
- `.schema table_name` - Show table structure
- `.headers on` - Show column headers
- `.mode column` - Format output as columns
- `.quit` - Exit

### One-line Queries

**View recent boats:**
```bash
sqlite3 database/jaws.db "SELECT * FROM boats LIMIT 5;"
```

**Count crews:**
```bash
sqlite3 database/jaws.db "SELECT COUNT(*) FROM crews;"
```

**Check events:**
```bash
sqlite3 database/jaws.db "SELECT event_id, event_date, status FROM events ORDER BY event_date;"
```

**View table structure:**
```bash
sqlite3 database/jaws.db "PRAGMA table_info(crews);"
```

**Show indexes:**
```bash
sqlite3 database/jaws.db "PRAGMA index_list(crew_availability);"
```

## Download from Production

### Using SFTP

```bash
# Add SSH key
ssh-add LightsailDefaultKey-ca-central-1.pem

# Start SFTP session
sftp bitnami@16.52.222.15

# Navigate and download
cd opt/bitnami/jaws/database
get jaws.db
get jaws.db.backup.*
bye
```

### Using SCP (Alternative)

```bash
scp bitnami@16.52.222.15:/opt/bitnami/jaws/database/jaws.db ./database/jaws.prod.db
```

## Seed Test Data

Run database seeders:

```bash
vendor/bin/phinx seed:run
```

**Run specific seeder:**
```bash
vendor/bin/phinx seed:run -s EventSeeder
```

## Initialize Database

**Recommended method (Phinx):**
```bash
vendor/bin/phinx migrate
vendor/bin/phinx seed:run
```

**Legacy method:**
```bash
php database/init_database.php
```

## Common Queries

### Check Availability

**Boats available for specific event:**
```bash
sqlite3 database/jaws.db "
SELECT b.display_name, ba.berths
FROM boats b
JOIN boat_availability ba ON b.boat_key = ba.boat_key
WHERE ba.event_id = 'Fri May 29' AND ba.berths > 0;
"
```

**Crews available for specific event:**
```bash
sqlite3 database/jaws.db "
SELECT c.name, ca.status
FROM crews c
JOIN crew_availability ca ON c.crew_key = ca.crew_key
WHERE ca.event_id = 'Fri May 29' AND ca.status > 0;
"
```

### Check Assignments

**View flotilla for event:**
```bash
sqlite3 database/jaws.db "
SELECT event_id, assignments
FROM flotillas
WHERE event_id = 'Fri May 29';
"
```

### User Management

**List all users:**
```bash
sqlite3 database/jaws.db "SELECT id, email, created_at FROM users;"
```

**Find user by email:**
```bash
sqlite3 database/jaws.db "SELECT * FROM users WHERE email = 'user@example.com';"
```

## Database Maintenance

### Vacuum Database

Reclaim unused space and optimize:

```bash
sqlite3 database/jaws.db "VACUUM;"
```

### Analyze Database

Update query optimizer statistics:

```bash
sqlite3 database/jaws.db "ANALYZE;"
```

### Check Integrity

Verify database integrity:

```bash
sqlite3 database/jaws.db "PRAGMA integrity_check;"
```

### Check Size

```bash
ls -lh database/jaws.db
```

## Production Operations

### Before Making Changes

1. **Always backup first:**
   ```bash
   ssh bitnami@16.52.222.15
   cd /opt/bitnami/jaws/database
   cp jaws.db jaws.backup.$(date +%Y%m%d_%H%M%S).db
   ```

2. **Test locally first:**
   - Download production database
   - Test migration on local copy
   - Verify changes work correctly

### Apply Production Migration

```bash
ssh bitnami@16.52.222.15
cd /opt/bitnami/jaws
vendor/bin/phinx migrate -e production
```

### Monitor Production Database

```bash
ssh bitnami@16.52.222.15
sqlite3 /opt/bitnami/jaws/database/jaws.db "SELECT COUNT(*) FROM boats;"
```

## Troubleshooting

### Database Locked

If you see "database is locked":
```bash
# Check for active connections
lsof database/jaws.db

# Force close (be careful!)
fuser -k database/jaws.db
```

### Corrupted Database

```bash
# Check integrity
sqlite3 database/jaws.db "PRAGMA integrity_check;"

# Dump and restore
sqlite3 database/jaws.db ".dump" | sqlite3 database/jaws.new.db
mv database/jaws.db database/jaws.corrupted.db
mv database/jaws.new.db database/jaws.db
```

### Permission Issues

```bash
# Check permissions
ls -la database/

# Fix permissions (development)
chmod 664 database/jaws.db
chmod 775 database/

# Fix permissions (production)
sudo chown bitnami:daemon /opt/bitnami/jaws/database/jaws.db
sudo chmod 664 /opt/bitnami/jaws/database/jaws.db
```

## Safety Checklist

- [ ] Backup created before any modifications
- [ ] Changes tested locally first
- [ ] Migration is reversible (rollback plan ready)
- [ ] Team notified of maintenance window (if needed)
- [ ] Monitoring in place to catch issues
- [ ] Rollback procedure tested and documented
