<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class AddCronNotifications extends AbstractMigration
{
    public function change(): void
    {
        $this->table('cron_notifications')
            ->addColumn('event_id', 'string', ['limit' => 255])
            ->addColumn('type', 'string', ['limit' => 50, 'comment' => 'reminder or crew_list'])
            ->addColumn('sent_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('recipients_count', 'integer', ['default' => 0])
            ->addColumn('skipped_count', 'integer', ['default' => 0])
            ->addIndex(['event_id', 'type'], ['unique' => true])
            ->create();
    }
}
