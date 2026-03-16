<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class CreatePasswordResetTokensTable extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('password_reset_tokens');
        $table->addColumn('user_id', 'integer', ['null' => false])
              ->addColumn('token_hash', 'string', ['limit' => 64, 'null' => false])
              ->addColumn('expires_at', 'datetime', ['null' => false])
              ->addColumn('created_at', 'datetime', ['null' => false])
              ->addIndex(['token_hash'], ['unique' => true, 'name' => 'idx_prt_token_hash'])
              ->addIndex(['user_id'], ['name' => 'idx_prt_user_id'])
              ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
              ->create();
    }
}
