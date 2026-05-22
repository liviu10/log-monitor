<?php

use Phinx\Migration\AbstractMigration;

class AddFullTextIndexToLogs extends AbstractMigration
{
    public function up()
    {
        // 1. Schimbam coloana context din JSON in TEXT pentru a permite indexarea FULLTEXT
        $this->table('logs')
             ->changeColumn('context', 'text', ['null' => true])
             ->update();

        // 2. Adaugam indexul FULLTEXT pe ambele coloane
        $this->execute('ALTER TABLE logs ADD FULLTEXT INDEX idx_message_context (message, context)');
    }

    public function down()
    {
        // Eliminam indexul
        $this->execute('ALTER TABLE logs DROP INDEX idx_message_context');
        
        // Revenim la JSON (optional, dar pentru consistenta)
        $this->table('logs')
             ->changeColumn('context', 'json', ['null' => true])
             ->update();
    }
}
