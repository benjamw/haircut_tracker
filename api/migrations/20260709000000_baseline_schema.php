<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Baseline. The full v1 schema is created by db/init/001_schema.sql when the
 * database volume is first created, so this migration is intentionally empty —
 * it just marks the baseline in phinxlog. Add real migrations AFTER this one
 * for any change to an already-populated database.
 */
final class BaselineSchema extends AbstractMigration
{
    public function change(): void
    {
        // no-op: schema baselined from db/init/001_schema.sql
    }
}
