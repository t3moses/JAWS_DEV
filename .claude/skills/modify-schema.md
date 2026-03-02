# Modify Database Schema Skill

Step-by-step guide for safely modifying the database schema in the JAWS application using Phinx migrations.

## Workflow Steps

### 1. Create New Migration File

```bash
vendor/bin/phinx create MyMigrationName
```

This creates a timestamped migration file in `database/migrations/`.

**Example:** `database/migrations/20260216120000_add_crew_notes.php`

### 2. Write Migration Logic

**Migration Structure:**
```php
<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCrewNotes extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('crews');
        $table->addColumn('notes', 'text', ['null' => true])
              ->update();
    }
}
```

**Common Operations:**

**Add Column:**
```php
$table = $this->table('boats');
$table->addColumn('rating', 'integer', ['default' => 0])
      ->update();
```

**Add Index:**
```php
$table = $this->table('crew_availability');
$table->addIndex(['crew_key', 'event_id'], ['unique' => true])
      ->update();
```

**Create Table:**
```php
$table = $this->table('notifications');
$table->addColumn('user_id', 'integer', ['null' => false])
      ->addColumn('message', 'text')
      ->addColumn('read_at', 'timestamp', ['null' => true])
      ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
      ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
      ->create();
```

**Remove Column:**
```php
$table = $this->table('boats');
$table->removeColumn('old_field')
      ->update();
```

### 3. Update Domain Entity

**Location:** `src/Domain/Entity/*.php`

Add or modify properties and methods:

```php
class Crew
{
    private ?string $notes = null;

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = $notes;
    }
}
```

### 4. Update Repository Implementation

**Location:** `src/Infrastructure/Persistence/SQLite/*.php`

Update SQL queries to include new fields:

```php
public function save(Crew $crew): void
{
    $sql = "INSERT INTO crews (crew_key, name, notes, ...)
            VALUES (:crew_key, :name, :notes, ...)
            ON CONFLICT(crew_key) DO UPDATE SET
            name = :name, notes = :notes, ...";

    $stmt = $this->pdo->prepare($sql);
    $stmt->execute([
        'crew_key' => $crew->getKey()->getValue(),
        'name' => $crew->getName(),
        'notes' => $crew->getNotes(),
        // ...
    ]);
}
```

### 5. Update DTOs (if API changes)

**Location:** `src/Application/DTO/Request|Response/*.php`

```php
class CrewResponse
{
    public static function fromEntity(Crew $crew): self
    {
        return new self(
            crewKey: $crew->getKey()->getValue(),
            name: $crew->getName(),
            notes: $crew->getNotes(),  // Add new field
            // ...
        );
    }
}
```

### 6. Write Tests

Create tests to verify the schema change:

```php
public function testCrewNotesCanBeSaved(): void
{
    $crew = new Crew(...);
    $crew->setNotes('Experienced sailor');

    $this->repository->save($crew);
    $found = $this->repository->findByKey($crew->getKey());

    $this->assertEquals('Experienced sailor', $found->getNotes());
}
```

### 7. Apply Migration

**Development:**
```bash
vendor/bin/phinx migrate
```

**Production:**
```bash
# Backup first!
cp database/jaws.db database/jaws.backup.$(date +%Y%m%d_%H%M%S).db

# Then apply migration
vendor/bin/phinx migrate -e production
```

## Checklist

- [ ] Migration file created with `vendor/bin/phinx create`
- [ ] Migration logic implemented (idempotent if possible)
- [ ] Domain entity updated with new field/methods
- [ ] Repository implementation updated (SQL queries)
- [ ] DTOs updated if API exposes the change
- [ ] Tests written and passing
- [ ] Migration tested locally (`vendor/bin/phinx migrate`)
- [ ] Database backup strategy confirmed for production

## Important Notes

### SQLite Limitations

SQLite has limited ALTER TABLE support. For complex changes, use the create-copy-drop pattern:

```php
public function change(): void
{
    // Create new table with desired schema
    $this->execute("CREATE TABLE crews_new (...)");

    // Copy data
    $this->execute("INSERT INTO crews_new SELECT * FROM crews");

    // Drop old table
    $this->execute("DROP TABLE crews");

    // Rename new table
    $this->execute("ALTER TABLE crews_new RENAME TO crews");
}
```

### Best Practices

1. **Test migrations in development first**
2. **Always backup before production migrations**
3. **Write reversible migrations when possible**
4. **Use transactions (Phinx does this automatically)**
5. **Document breaking changes in migration comments**

### Rollback

If something goes wrong:

```bash
# Rollback last migration
vendor/bin/phinx rollback

# Check migration status
vendor/bin/phinx status
```

### Verifying Schema

```bash
# Query the database structure
sqlite3 database/jaws.db ".schema crews"

# Check specific table
sqlite3 database/jaws.db "PRAGMA table_info(crews);"
```

## Common Scenarios

**Adding a nullable field:**
- Safe to deploy without data migration
- Existing records will have NULL values

**Adding a required field:**
- Must provide a default value or migrate existing data
- Use `['default' => 'value']` in column definition

**Renaming a field:**
- Create new field, copy data, remove old field
- Update all queries before removing old field

**Changing field type:**
- Create new field, migrate data with conversion, remove old field
- Test conversion logic thoroughly
