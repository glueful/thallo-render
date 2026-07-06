<?php

declare(strict_types=1);

namespace Thallo\Render\Templates;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

/**
 * DB template overrides (spec §2): one row per (theme, path); versions are APPEND-ONLY
 * and immutable — save() always inserts a version and repoints current_version_uuid;
 * deactivate() hides a row from the loader WITHOUT touching history; re-saving a
 * deactivated path reactivates the same row so history continues.
 */
final class TemplateRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,string> path => current_version_uuid (ACTIVE rows only) */
    public function overrideMap(string $theme): array
    {
        $map = [];
        foreach (
            $this->db->table('lemma_render_templates')
                ->select(['path', 'current_version_uuid'])
                ->where('theme', '=', $theme)
                ->where('active', '=', 1)
                ->get() as $row
        ) {
            $row = (array) $row;
            if (is_string($row['current_version_uuid'] ?? null) && $row['current_version_uuid'] !== '') {
                $map[(string) $row['path']] = (string) $row['current_version_uuid'];
            }
        }
        return $map;
    }

    /** @return array<string,mixed>|null the raw row, any active state */
    public function find(string $theme, string $path): ?array
    {
        $row = $this->db->table('lemma_render_templates')
            ->where('theme', '=', $theme)
            ->where('path', '=', $path)
            ->first();
        return $row === null ? null : (array) $row;
    }

    /** @return array{source:string,version_uuid:string}|null null = missing or inactive */
    public function findCurrentSource(string $theme, string $path): ?array
    {
        $tpl = $this->find($theme, $path);
        if ($tpl === null || (int) $tpl['active'] !== 1 || !is_string($tpl['current_version_uuid'])) {
            return null;
        }
        $version = $this->db->table('lemma_render_template_versions')
            ->where('uuid', '=', (string) $tpl['current_version_uuid'])
            ->first();
        if ($version === null) {
            return null;
        }
        return [
            'source' => (string) ((array) $version)['source'],
            'version_uuid' => (string) $tpl['current_version_uuid'],
        ];
    }

    /** @return array<string,string> path => updated_at (ACTIVE rows only) */
    public function listActive(string $theme): array
    {
        $out = [];
        foreach (
            $this->db->table('lemma_render_templates')
                ->select(['path', 'updated_at'])
                ->where('theme', '=', $theme)
                ->where('active', '=', 1)
                ->get() as $row
        ) {
            $row = (array) $row;
            $out[(string) $row['path']] = (string) ($row['updated_at'] ?? '');
        }
        return $out;
    }

    /**
     * Create-or-reactivate + append a version + repoint, in ONE transaction (spec §5).
     *
     * @return array{template_uuid:string,version_uuid:string}
     */
    public function save(string $theme, string $path, string $source, ?string $createdBy): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $pdo = $this->db->getPDO();
        $pdo->beginTransaction();
        try {
            $tpl = $this->find($theme, $path);
            if ($tpl === null) {
                $templateUuid = Utils::generateNanoID();
                $this->db->table('lemma_render_templates')->insert([
                    'uuid' => $templateUuid,
                    'theme' => $theme,
                    'path' => $path,
                    'current_version_uuid' => null,
                    'active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $templateUuid = (string) $tpl['uuid'];
            }
            $versionUuid = Utils::generateNanoID();
            $this->db->table('lemma_render_template_versions')->insert([
                'uuid' => $versionUuid,
                'template_uuid' => $templateUuid,
                'source' => $source,
                'created_by' => $createdBy,
                'created_at' => $now,
            ]);
            $this->db->table('lemma_render_templates')
                ->where('uuid', '=', $templateUuid)
                ->update(['current_version_uuid' => $versionUuid, 'active' => 1, 'updated_at' => $now]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['template_uuid' => $templateUuid, 'version_uuid' => $versionUuid];
    }

    /** Deactivate (spec §2: DELETE preserves history). False = no row or already inactive. */
    public function deactivate(string $theme, string $path): bool
    {
        $tpl = $this->find($theme, $path);
        if ($tpl === null || (int) $tpl['active'] !== 1) {
            return false;
        }
        $this->db->table('lemma_render_templates')
            ->where('uuid', '=', (string) $tpl['uuid'])
            ->update(['active' => 0, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        return true;
    }

    /**
     * Newest first; readable on INACTIVE rows too (history survives delete; restore
     * reactivates).
     *
     * @return list<array{uuid:string,created_by:?string,created_at:string,current:bool}>
     */
    public function versions(string $theme, string $path): array
    {
        $tpl = $this->find($theme, $path);
        if ($tpl === null) {
            return [];
        }
        $current = is_string($tpl['current_version_uuid']) ? $tpl['current_version_uuid'] : '';
        $out = [];
        foreach (
            $this->db->table('lemma_render_template_versions')
                ->select(['uuid', 'created_by', 'created_at'])
                ->where('template_uuid', '=', (string) $tpl['uuid'])
                ->orderBy('id', 'DESC')
                ->get() as $row
        ) {
            $row = (array) $row;
            $out[] = [
                'uuid' => (string) $row['uuid'],
                'created_by' => is_string($row['created_by'] ?? null) ? $row['created_by'] : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'current' => ((int) $tpl['active']) === 1 && (string) $row['uuid'] === $current,
            ];
        }
        return $out;
    }

    /** @return array{uuid:string,source:string,created_by:?string,created_at:string}|null */
    public function findVersion(string $theme, string $path, string $versionUuid): ?array
    {
        $tpl = $this->find($theme, $path);
        if ($tpl === null) {
            return null;
        }
        $row = $this->db->table('lemma_render_template_versions')
            ->where('uuid', '=', $versionUuid)
            ->where('template_uuid', '=', (string) $tpl['uuid'])
            ->first();
        if ($row === null) {
            return null;
        }
        $row = (array) $row;
        return [
            'uuid' => (string) $row['uuid'],
            'source' => (string) $row['source'],
            'created_by' => is_string($row['created_by'] ?? null) ? $row['created_by'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
