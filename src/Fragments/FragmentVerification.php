<?php

declare(strict_types=1);

namespace Thallo\Render\Fragments;

/**
 * The record of templates verified for fragment rendering (visual builder spec §3.5): the
 * theme, the entry template and the sha256 of every participating template's source, written
 * by the verification test that renders every fixture block-by-block and whole-page and diffs
 * them. A fragment is served only when every template it would use hashes as recorded, so a
 * change to any of them — a shipped edit or a DB override — falls back to the whole page
 * until the test verifies it again. In v1 only the default theme's `entry.twig` is verified.
 */
final class FragmentVerification
{
    public const RECORD = __DIR__ . '/../../fragments-verified.json';

    /** @var array{theme: string, entry_template: string, templates: array<string,string>}|null */
    private ?array $record = null;

    public function __construct(private readonly string $recordPath = self::RECORD)
    {
    }

    /** @return array{theme: string, entry_template: string, templates: array<string,string>} */
    public function record(): array
    {
        if ($this->record === null) {
            $decoded = is_file($this->recordPath)
                ? json_decode((string) file_get_contents($this->recordPath), true)
                : null;
            $this->record = [
                'theme' => (string) ($decoded['theme'] ?? ''),
                'entry_template' => (string) ($decoded['entry_template'] ?? ''),
                'templates' => is_array($decoded['templates'] ?? null) ? $decoded['templates'] : [],
            ];
        }
        return $this->record;
    }

    /**
     * Whether a fragment render through `$entryTemplate` and `$templates` is verified: the
     * record names this theme and entry template, and every template hashes as recorded.
     *
     * @param list<string> $templates
     */
    public function verified(
        TemplateDependencies $dependencies,
        string $theme,
        string $entryTemplate,
        array $templates,
    ): bool {
        $record = $this->record();
        if ($record['theme'] !== $theme || $record['entry_template'] !== $entryTemplate) {
            return false;
        }
        foreach (array_unique([$entryTemplate, ...$templates]) as $name) {
            $hash = $dependencies->templateHash($name);
            if ($hash === null || ($record['templates'][$name] ?? null) !== $hash) {
                return false;
            }
        }
        return true;
    }

    /**
     * The record for the templates as they are now (the verification test writes it once the
     * block-by-block render matches the whole page).
     *
     * @param list<string> $templates
     * @return array{theme: string, entry_template: string, templates: array<string,string>}
     */
    public function build(
        TemplateDependencies $dependencies,
        string $theme,
        string $entryTemplate,
        array $templates,
    ): array {
        $hashes = [];
        foreach (array_unique([$entryTemplate, ...$templates]) as $name) {
            $hash = $dependencies->templateHash($name);
            if ($hash !== null) {
                $hashes[$name] = $hash;
            }
        }
        ksort($hashes);
        return ['theme' => $theme, 'entry_template' => $entryTemplate, 'templates' => $hashes];
    }

    /** @param array{theme: string, entry_template: string, templates: array<string,string>} $record */
    public function write(array $record): void
    {
        file_put_contents(
            $this->recordPath,
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
        $this->record = null;
    }
}
