<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class RemoveEmailColumns extends AbstractMigration
{
    public function change(): void
    {
        $this->table('crews')
            ->removeColumn('email')
            ->save();

        $this->table('boats')
            ->removeColumn('owner_email')
            ->save();
    }
}
