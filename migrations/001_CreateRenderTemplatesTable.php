<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateRenderTemplatesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('render_templates')) {
            return;
        }
        $schema->createTable('render_templates', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('theme', 64);
            $table->string('path', 190);
            // Nullable between row creation and the first version insert (one transaction).
            $table->string('current_version_uuid', 12)->nullable();
            // DELETE = deactivate (spec §2): the loader ignores inactive rows; history stays.
            $table->boolean('active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['theme', 'path'], 'uniq_render_template_theme_path');
            $table->unique('uuid');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('render_templates');
    }

    public function getDescription(): string
    {
        return 'Create render_templates (DB template override identity per theme+path).';
    }
}
