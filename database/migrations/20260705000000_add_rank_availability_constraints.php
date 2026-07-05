<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class AddRankAvailabilityConstraints extends AbstractMigration
{
    public function up(): void
    {
        // Backfill commitment_rank: change values > 2 to 2 (normal priority)
        // Default is already 2, so only fix values that are outside 0-2 range
        $this->execute('UPDATE crews SET commitment_rank = 2 WHERE commitment_rank > 2');
        $this->execute('UPDATE crews SET commitment_rank = 2 WHERE commitment_rank = 0');

        // Compress crew_availability status: change values > 1 to 0
        // Note: SQLite already enforces CHECK(status IN (0, 1)) but this ensures no old data violates it
        $this->execute('UPDATE crew_availability SET status = 0 WHERE status > 1');

        // Note: crew_availability table already has CHECK(status IN (0, 1)) constraint
        // crews table now has commitment_rank with default 2
    }

    public function down(): void
    {
        // Rollback is a no-op since we only updated data, not schema
        // The constraints already exist in the schema
    }
}

