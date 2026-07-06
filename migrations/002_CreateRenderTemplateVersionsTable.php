<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateRenderTemplateVersionsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('render_template_versions')) {
            return;
        }
        $schema->createTable('render_template_versions', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('template_uuid', 12);
            $table->text('source');
            // Bare user uuid — no cross-package FK (spec §2).
            $table->string('created_by', 12)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique('uuid');
            $table->index('template_uuid', 'idx_render_template_versions_template');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('render_template_versions');
    }

    public function getDescription(): string
    {
        return 'Create render_template_versions (append-only, immutable template sources).';
    }
}
