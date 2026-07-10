<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

class AddFullTextIndexToLogs extends AbstractMigration
{
    public function up()
    {
        // 1. Change column context from JSON to TEXT to allow FULLTEXT indexing
        $this->table('logs')
            ->changeColumn('context', 'text', ['null' => true])
            ->update();

        // 2. Add FULLTEXT index on both columns
        $this->execute('ALTER TABLE logs ADD FULLTEXT INDEX idx_message_context (message, context)');
    }

    public function down()
    {
        // Drop the index
        $this->execute('ALTER TABLE logs DROP INDEX idx_message_context');

        // Revert to JSON (optional, but for consistency)
        $this->table('logs')
            ->changeColumn('context', 'json', ['null' => true])
            ->update();
    }
}
