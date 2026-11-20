<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateNotificationTemplatesTable extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('notification_templates')) {
            return;
        }

        $table = $this->table('notification_templates', ['signed' => false]);
        $table->addColumn('event_name', 'string', ['limit' => 255, 'null' => false])
            ->addColumn('channel', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('language', 'string', ['limit' => 10, 'default' => 'en', 'null' => false])
            ->addColumn('subject', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('body_html', 'text', ['null' => true])
            ->addColumn('body_text', 'text', ['null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true, 'null' => false])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['event_name', 'channel', 'language'], ['unique' => true, 'name' => 'idx_event_channel_lang'])
            ->create();
    }
}
