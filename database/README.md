# JAWS Database

This directory contains the SQLite database and Phinx migration scripts for the JAWS application.

📖 **See also:**
- [Setup Guide](../docs/SETUP.md) - Complete setup instructions for new installations
- [Deployment Guide](../docs/DEPLOYMENT.md) - Production database management procedures
- [Developer Guide](../docs/DEVELOPER_GUIDE.md#database-schema-changes) - How to create and apply migrations during development

## Database Initialization

The project uses [Phinx](https://phinx.org/) for database migrations. To create and initialize the database, run:

```bash
# Install dependencies first (if not already done)
composer install

# Run all migrations to create database and schema
vendor/bin/phinx migrate

# Optional: Seed test data
vendor/bin/phinx seed:run
```

This will:
1. Create `database/jaws.db` (SQLite database file)
2. Create the `phinxlog` table to track migrations
3. Apply all pending migrations in order
4. Set up the complete database schema

**Note:** The legacy `init_database.php` script is maintained for backward compatibility. All new migrations should use Phinx.

## Database Structure

The database contains the following tables:

### Core Entities

- **boats** - Boat information, owner details, berth capacity, ranking data
- **crews** - Crew member information, skills, preferences, ranking data
- **events** - Event schedule and metadata
- **users** - User accounts for authentication (email, password hash, tokens)
- **season_config** - Season-wide configuration (singleton table)

### Relationships

- **boat_availability** - Berths offered per boat per event
- **crew_availability** - Crew availability status per event
- **boat_history** - Boat participation history
- **crew_history** - Crew assignment history (which boat)
- **crew_whitelist** - Crew preferences for specific boats

### Generated Data

- **flotillas** - Generated flotilla assignments (JSON)

## Migrations

The project uses Phinx for database migrations. All migration files are PHP classes stored in `database/migrations/`.

### Migration Commands

```bash
# Create a new migration
vendor/bin/phinx create MyMigrationName

# Run pending migrations
vendor/bin/phinx migrate

# Rollback last migration
vendor/bin/phinx rollback

# Check migration status
vendor/bin/phinx status

# Seed test data
vendor/bin/phinx seed:run
```

### Current Migrations

- `20260101000000_initial_schema.php` - Initial database schema (all core tables)
- `20260130000000_add_users_authentication.php` - User authentication system
- `20260131000000_add_last_logout_column.php` - Last logout tracking
- `20260201000000_make_display_name_nullable.php` - Allow nullable display names

### Writing Migrations

Migrations are PHP classes extending `Phinx\Migration\AbstractMigration`:

```php
<?php
use Phinx\Migration\AbstractMigration;

final class MyMigration extends AbstractMigration
{
    public function change(): void
    {
        // Use Phinx methods to create/modify tables
        $table = $this->table('my_table');
        $table->addColumn('name', 'string', ['limit' => 255])
              ->addIndex(['name'], ['unique' => true])
              ->create();
    }
}
```

See [Phinx documentation](https://book.cakephp.org/phinx/0/en/migrations.html) for more details.

## File Permissions (AWS Lightsail)

After uploading the database to production:

```bash
chgrp www-data /var/www/html/database/jaws.db
chmod 664 /var/www/html/database/jaws.db
chgrp www-data /var/www/html/database
chmod 775 /var/www/html/database
```

## Backup and Restore

### Backup

```bash
sqlite3 database/jaws.db .dump > database/backup_$(date +%Y%m%d_%H%M%S).sql
# Or simply copy the file
cp database/jaws.db database/backup_$(date +%Y%m%d_%H%M%S).db
```

### Restore

```bash
sqlite3 database/jaws.db < database/backup_YYYYMMDD_HHMMSS.sql
# Or copy the backup file
cp database/backups/backup_YYYYMMDD_HHMMSS.db database/jaws.db
```

## Test Data Seeding

The project includes a seeder for populating test data:

```bash
# Run all seeders
vendor/bin/phinx seed:run

# Run specific seeder
vendor/bin/phinx seed:run -s TestDataSeeder
```

**Seeders:**

- `TestDataSeeder.php` - Creates sample boats, crews, events, and assignments for testing

### Creating New Seeders

```bash
# Phinx doesn't have a seed generator, so create manually
touch database/seeds/MySeeder.php
```

See [Phinx Seeding documentation](https://book.cakephp.org/phinx/0/en/seeding.html) for details.

## Legacy CSV Migration

**Note:** This script is for historical reference only. The project has been fully migrated to SQLite.

## Development vs Production

The database uses the same schema in development and production. The `season_config.source` field controls time behavior:

- `production` - Uses real system time
- `simulated` - Uses `season_config.simulated_date` for testing

## Foreign Keys

Foreign key constraints are enabled by default. All cascade deletes are configured:

- Deleting a boat removes its availability, history, and whitelist references
- Deleting a crew removes its availability, history, and whitelist entries
- Deleting an event removes all associated availability and history records
- Deleting a user cascades to related authentication tokens

## Phinx Configuration

Phinx is configured in [phinx.php](../phinx.php) at the project root.

**Environments:**

- `development` - Uses `./database/jaws.db` (default)
- `testing` - Uses in-memory SQLite (`:memory:`)
- `production` - Uses `DB_PATH` environment variable or falls back to `./database/jaws.db`

**Migration Table:** `phinxlog` - Tracks which migrations have been applied

## Query Examples

```sql
-- Get all available boats for an event
SELECT b.* FROM boats b
JOIN boat_availability ba ON b.id = ba.boat_id
WHERE ba.event_id = 'Fri May 29' AND ba.berths > 0;

-- Get all available crews for an event
SELECT c.* FROM crews c
JOIN crew_availability ca ON c.id = ca.crew_id
WHERE ca.event_id = 'Fri May 29' AND ca.status IN (1, 2);

-- Withdraw a boat from an event.  "event id" takes the form: 'Fri May 29'.  "boat display name" takes the form: 'Astraeus'.
UPDATE boat_availability
SET berths = 0
WHERE event_id = "event id"
  AND boat_id = (
    SELECT id FROM boats WHERE display_name = "boat display name"
  );

-- Get crew assignment history
SELECT c.display_name, ch.event_id, ch.boat_key
FROM crews c
JOIN crew_history ch ON c.id = ch.crew_id
WHERE c.key = 'johndoe'
ORDER BY ch.event_id;

-- Get boat's crew assignments for an event
SELECT c.display_name, c.skill
FROM crews c
JOIN crew_history ch ON c.id = ch.crew_id
WHERE ch.event_id = 'Fri May 29' AND ch.boat_key = 'sailaway';

-- Check user authentication
SELECT u.email, u.is_active, u.email_verified_at
FROM users u
WHERE u.email = 'user@example.com';

-- Get migration status
SELECT * FROM phinxlog ORDER BY version DESC;

-- Move the date of an event without modifying the lists of available boats and crew.  An event id takes the form: Fri May 29.  The corresponding event date takes the form: 2026-05-29.
PRAGMA foreign_keys = OFF;
UPDATE events SET event_id = REPLACE(event_id, 'old event id', 'new event id') WHERE event_id LIKE '%old event id%';
UPDATE events SET event_date = REPLACE(event_date, 'old event date', 'new event date') WHERE event_date LIKE '%old event date%';
UPDATE "boat_availability" SET event_id = REPLACE(event_id, 'old event id', 'new event id') WHERE event_id LIKE '%old event id%';
UPDATE "crew_availability" SET event_id = REPLACE(event_id, 'old event id', 'new event id') WHERE event_id LIKE '%old event id%';
UPDATE flotillas SET event_id = REPLACE(event_id, 'old event id', 'new event id') WHERE event_id LIKE '%old event id%';
UPDATE flotillas SET flotilla_data = REPLACE(flotilla_data, 'old event id', 'new event id') WHERE flotilla_data LIKE '%old event id%';
PRAGMA foreign_keys = ON;
```
