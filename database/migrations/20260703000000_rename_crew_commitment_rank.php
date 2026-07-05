<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class RenameCrewCommitmentRank extends AbstractMigration
{
    public function change(): void
    {
        // Rename rank_commitment to commitment_rank for consistency
        $this->table('crews')
            ->renameColumn('rank_commitment', 'commitment_rank')
            ->save();
    }
}
