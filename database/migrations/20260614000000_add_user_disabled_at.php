<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Add Disabled At Column Migration
 *
 * Adds a nullable disabled_at timestamp to the users table to support
 * reversible account suspension (deactivate / reactivate) by admins.
 *
 * Semantics:
 * - disabled_at IS NULL  => account is active
 * - disabled_at NOT NULL => account is suspended (login blocked, existing
 *   tokens rejected, linked crew/boat excluded from selection & flotillas)
 *
 * A suspended account is fully reversible: clearing disabled_at restores it.
 * Accounts are still permanently removed via the end-of-season reset.
 */
final class AddUserDisabledAt extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
             ->addColumn('disabled_at', 'datetime', [
                 'null' => true,
                 'after' => 'last_logout',
             ])
             ->update();
    }
}
