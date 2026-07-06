<?php

declare(strict_types=1);

namespace Thallo\Render\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Http\Response;
use Thallo\Contracts\Delivery\PreviewThemeValidator;
use Thallo\Render\Http\TemplateSaveBody;
use Thallo\Render\Templates\TemplateCatalog;
use Thallo\Render\Templates\TemplateLinter;
use Thallo\Render\Templates\TemplateRepository;
use Thallo\Render\Templates\TemplateUpdated;
use Thallo\Render\Templates\ThemeCloner;
use Thallo\Render\ThemeLocator;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * DB-edited templates admin API (spec §5–§6). Triple-gated at the route layer
 * (capability → auth → lemma_permission:templates.manage). Save = live: syntactic path
 * check → theme check (RenderThemeValidator via the PreviewThemeValidator binding) →
 * policy lint (422 with ALL line-numbered violations) → transactional save →
 * TemplateUpdated (also on delete/restore — every mutation that changes what renders).
 */
final class TemplatesAdminController
{
    /** The one non-twig path (custom-css spec §2): DB-only, CSS-validated. */
    private const CUSTOM_CSS = 'custom.css';

    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly TemplateLinter $linter,
        private readonly TemplateCatalog $catalog,
        private readonly PreviewThemeValidator $themeValidator,
        private readonly ThemeLocator $activeTheme,
        private readonly EventService $events,
        private readonly ApplicationContext $context,
        private readonly ?ThemeCloner $themeCloner = null,
    ) {
    }

    #[ApiOperation(
        summary: 'List selectable themes (validator-accepted) and the active one',
        tags: ['Lemma Templates'],
    )]
    #[ApiResponse(200, description: 'Selectable themes + the currently active theme.')]
    public function themes(): Response
    {
        return Response::success([
            'themes' => $this->availableThemes(),
            'active' => $this->activeTheme->activePaths()['name'],
        ]);
    }

    #[ApiOperation(
        summary: 'Clone a theme into a new app theme directory (themes/{name})',
        tags: ['Lemma Templates'],
    )]
    #[ApiResponse(200, description: 'Created; response carries the new theme and the refreshed theme list.')]
    #[ApiResponse(422, description: 'Invalid name/source, name taken, or the themes directory is not writable.')]
    public function createTheme(Request $request): Response
    {
        if ($this->themeCloner === null) {
            return Response::error('Theme cloning is unavailable.', 404);
        }
        $body = (array) json_decode((string) $request->getContent(), true);
        $name = is_string($body['name'] ?? null) ? $body['name'] : '';
        $from = is_string($body['from'] ?? null) && $body['from'] !== '' ? $body['from'] : 'default';
        try {
            $created = $this->themeCloner->clone($name, $from);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return Response::error($e->getMessage(), 422);
        }
        return Response::success([
            'theme' => $created['name'],
            'themes' => $this->availableThemes(),
        ], 'Theme created.');
    }

    /**
     * @queryParam theme:string="Theme name; defaults to the active theme."
     */
    #[ApiOperation(summary: 'List resolvable templates (filesystem + DB) for a theme', tags: ['Lemma Templates'])]
    #[ApiResponse(200, description: 'Merged listing with per-path origin (db|theme|default).')]
    public function index(Request $request): Response
    {
        $theme = $this->theme($request);
        if ($theme === null) {
            return Response::error('Unknown theme.', 404);
        }
        // Read-only theme files (assets + theme.json) join the listing flagged,
        // so the admin can browse class names to override in custom.css.
        $readonly = array_map(
            static fn(array $row): array => $row + ['overridden' => false, 'updated_at' => null, 'readonly' => true],
            $this->catalog->listReadOnly($theme),
        );
        return Response::success([
            'theme' => $theme,
            'themes' => $this->availableThemes(),
            'templates' => [...$this->catalog->list($theme), ...$readonly],
        ]);
    }

    /**
     * Selectable themes for the admin switcher: the pack 'default' plus every
     * app theme directory the validator accepts (same ladder ThemeLocator uses).
     *
     * @return list<string>
     */
    private function availableThemes(): array
    {
        $themes = ['default'];
        foreach ($this->catalog->themeCandidates() as $name) {
            if ($this->themeValidator->isValidTheme($name)) {
                $themes[] = $name;
            }
        }
        return $themes;
    }

    /**
     * @queryParam theme:string="Theme name; defaults to the active theme."
     */
    #[ApiOperation(summary: 'Current template source (DB override or filesystem)', tags: ['Lemma Templates'])]
    #[ApiResponse(200, description: 'Source + origin; filesystem sources are the copy-from-disk start.')]
    #[ApiResponse(404, description: 'Unknown theme, invalid path, or nothing at this path.')]
    public function show(Request $request, string $path): Response
    {
        $theme = $this->theme($request);
        if ($theme === null) {
            return Response::error('Not Found', 404);
        }
        // Read-only theme files: filesystem view, no DB layer, never editable
        // (save/delete/restore reject these paths — the grammar never allowed them).
        if ($this->isReadOnlyPath($path)) {
            $file = $this->catalog->readReadOnlyFile($theme, $path);
            if ($file === null) {
                return Response::error('Not Found', 404);
            }
            return Response::success([
                'path' => $path,
                'theme' => $theme,
                'origin' => $file['origin'],
                'source' => $file['source'],
                'version_uuid' => null,
                'readonly' => true,
            ]);
        }
        if (!$this->pathAllowed($path)) {
            return Response::error('Not Found', 404);
        }
        $db = $this->templates->findCurrentSource($theme, $path);
        if ($db !== null) {
            return Response::success([
                'path' => $path,
                'theme' => $theme,
                'origin' => 'db',
                'source' => $db['source'],
                'version_uuid' => $db['version_uuid'],
            ]);
        }
        // custom.css is DB-only by contract (spec §1): never fall through to the
        // filesystem — an accidental theme custom.css file must not become the source.
        if ($this->isCustomCss($path)) {
            return Response::error('Not Found', 404);
        }
        $file = $this->catalog->readFile($theme, $path);
        if ($file === null) {
            return Response::error('Not Found', 404);
        }
        return Response::success([
            'path' => $path,
            'theme' => $theme,
            'origin' => $file['origin'],
            'source' => $file['source'],
            'version_uuid' => null,
        ]);
    }

    /**
     * @queryParam theme:string="Theme name; defaults to the active theme."
     */
    #[ApiOperation(
        summary: 'Save a template override (create or update; DB-only paths allowed)',
        tags: ['Lemma Templates'],
    )]
    #[\Glueful\Routing\Attributes\ApiRequestBody(
        schema: TemplateSaveBody::class,
        description: 'The full Twig source to save as the new current version.',
    )]
    #[ApiResponse(200, description: 'Saved and live; response carries the new version uuid.')]
    #[ApiResponse(404, description: 'Unknown theme.')]
    #[ApiResponse(422, description: 'Invalid path/source, or policy violations ({line, message} list).')]
    public function save(Request $request, string $path): Response
    {
        $theme = $this->theme($request);
        if ($theme === null) {
            return Response::error('Unknown theme.', 404);
        }
        if (!$this->pathAllowed($path)) {
            return Response::error(
                'Invalid template path (slash-separated [A-Za-z0-9._-] segments, ending .twig).',
                422,
            );
        }
        $body = (array) json_decode((string) $request->getContent(), true);
        $source = $body['source'] ?? null;
        if (!is_string($source)) {
            return Response::error('source must be a string.', 422);
        }
        // Type-aware validation (custom-css spec §2): the well-known CSS path
        // skips the Twig policy linter — CSS cannot 500 the site, so only
        // encoding + size gate it. Twig paths lint exactly as before.
        if ($this->isCustomCss($path)) {
            $cssError = $this->cssViolation($source);
            if ($cssError !== null) {
                return Response::error($cssError, 422);
            }
        } else {
            $violations = $this->linter->lint($source, $path);
            if ($violations !== []) {
                return new Response([
                    'success' => false,
                    'message' => 'Template policy violations.',
                    'errors' => $violations,
                ], 422);
            }
        }
        $ids = $this->templates->save($theme, $path, $source, $this->userUuid($request));
        $this->events->dispatch(new TemplateUpdated($theme, $path));
        return Response::success([
            'path' => $path,
            'theme' => $theme,
            'version_uuid' => $ids['version_uuid'],
        ], 'Template saved.');
    }

    /**
     * @queryParam theme:string="Theme name; defaults to the active theme."
     */
    #[ApiOperation(
        summary: 'Delete the override (deactivate — history preserved), fall back to filesystem',
        tags: ['Lemma Templates'],
    )]
    #[ApiResponse(200, description: 'Override deactivated.')]
    #[ApiResponse(404, description: 'No active override at this path.')]
    public function delete(Request $request, string $path): Response
    {
        $theme = $this->theme($request);
        if ($theme === null || !$this->pathAllowed($path)) {
            return Response::error('Not Found', 404);
        }
        if (!$this->templates->deactivate($theme, $path)) {
            return Response::error('No active override at this path.', 404);
        }
        $this->events->dispatch(new TemplateUpdated($theme, $path));
        return Response::success(['path' => $path, 'theme' => $theme], 'Override removed.');
    }

    /**
     * @queryParam theme:string="Theme name; defaults to the active theme."
     */
    #[ApiOperation(summary: 'Version history (newest first; survives delete)', tags: ['Lemma Templates'])]
    #[ApiResponse(200, description: 'Versions: {uuid, created_by, created_at, current}.')]
    #[ApiResponse(404, description: 'This path has never been saved.')]
    public function versions(Request $request, string $path): Response
    {
        $theme = $this->theme($request);
        if ($theme === null || !$this->pathAllowed($path)) {
            return Response::error('Not Found', 404);
        }
        if ($this->templates->find($theme, $path) === null) {
            return Response::error('This path has no saved versions.', 404);
        }
        return Response::success([
            'path' => $path,
            'theme' => $theme,
            'versions' => $this->templates->versions($theme, $path),
        ]);
    }

    /**
     * @queryParam theme:string="Theme name; defaults to the active theme."
     */
    #[ApiOperation(summary: 'One version\'s source', tags: ['Lemma Templates'])]
    #[ApiResponse(200, description: 'The immutable stored source.')]
    #[ApiResponse(404, description: 'Unknown version for this path.')]
    public function showVersion(Request $request, string $path, string $uuid): Response
    {
        $theme = $this->theme($request);
        if ($theme === null || !$this->pathAllowed($path)) {
            return Response::error('Not Found', 404);
        }
        $version = $this->templates->findVersion($theme, $path, $uuid);
        if ($version === null) {
            return Response::error('Unknown version for this path.', 404);
        }
        return Response::success(['path' => $path, 'theme' => $theme] + $version);
    }

    /**
     * @queryParam theme:string="Theme name; defaults to the active theme."
     */
    #[ApiOperation(
        summary: 'Restore a version (append-as-new-current; reactivates a deleted override)',
        tags: ['Lemma Templates'],
    )]
    #[ApiResponse(200, description: 'Restored; response carries the NEW version uuid.')]
    #[ApiResponse(404, description: 'Unknown version for this path.')]
    #[ApiResponse(422, description: 'The stored version violates the CURRENT policy ({line, message} list).')]
    public function restore(Request $request, string $path, string $uuid): Response
    {
        $theme = $this->theme($request);
        if ($theme === null || !$this->pathAllowed($path)) {
            return Response::error('Not Found', 404);
        }
        $version = $this->templates->findVersion($theme, $path, $uuid);
        if ($version === null) {
            return Response::error('Unknown version for this path.', 404);
        }
        // Re-validate against TODAY'S rules (spec §4): old versions can predate a
        // policy tightening or have been mutated around the API — restore must not
        // put a template live that a fresh save would reject. Same 422 payloads as
        // save(): the CSS path re-runs the CSS branch (Twig-linting stored CSS
        // would 422 valid rules); twig paths re-lint.
        if ($this->isCustomCss($path)) {
            $cssError = $this->cssViolation($version['source']);
            if ($cssError !== null) {
                return Response::error($cssError, 422);
            }
        } else {
            $violations = $this->linter->lint($version['source'], $path);
            if ($violations !== []) {
                return new Response([
                    'success' => false,
                    'message' => 'Template policy violations.',
                    'errors' => $violations,
                ], 422);
            }
        }
        $ids = $this->templates->save($theme, $path, $version['source'], $this->userUuid($request));
        $this->events->dispatch(new TemplateUpdated($theme, $path));
        return Response::success([
            'path' => $path,
            'theme' => $theme,
            'version_uuid' => $ids['version_uuid'],
        ], 'Version restored.');
    }

    /** @return string|null resolved theme; null = invalid (caller 404s) */
    private function theme(Request $request): ?string
    {
        $theme = (string) $request->query->get('theme', '');
        if ($theme === '') {
            return $this->activeTheme->activePaths()['name'];
        }
        return $this->themeValidator->isValidTheme($theme) ? $theme : null;
    }

    /**
     * Syntactic only (spec §5): slash-separated segments, each [A-Za-z0-9._-]+ and
     * not "."/"..", ending .twig. Conservative and URL-SAFE by construction — the
     * admin client injects {path} into URLs RAW (slash-preserving), so ?, #, spaces,
     * and every other URL-significant character must be unrepresentable, not merely
     * discouraged. The charset also excludes \, :, and scheme syntax. Empty segments
     * cover leading slashes and "//". DB-only paths are FINE.
     */
    private function isCustomCss(string $path): bool
    {
        return $path === self::CUSTOM_CSS;
    }

    /**
     * Read-only browsable theme files: theme.json and assets/… stylesheets/
     * scripts. Same URL-safe segment grammar as templates (each segment
     * [A-Za-z0-9._-]+, no '.'/'..'), different extensions — and NEVER writable:
     * save/delete/restore go through pathAllowed(), which rejects these.
     */
    private function isReadOnlyPath(string $path): bool
    {
        if ($path === 'theme.json') {
            return true;
        }
        if (!str_starts_with($path, 'assets/')) {
            return false;
        }
        if (!str_ends_with($path, '.css') && !str_ends_with($path, '.js')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || preg_match('/^[A-Za-z0-9._-]+$/', $segment) !== 1
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * The full allowed-path set: the exact CUSTOM_CSS path plus the twig
     * grammar. invalidPath() itself stays twig-only so its "ending .twig"
     * error copy remains true — every OTHER non-twig path still 422s.
     */
    private function pathAllowed(string $path): bool
    {
        return $this->isCustomCss($path) || !$this->invalidPath($path);
    }

    /**
     * The CSS validation branch (custom-css spec §2): encoding + size only —
     * broken CSS loses in the browser, it cannot 500 the site, so there is
     * deliberately no syntax gate. Shared by save() and restore().
     */
    private function cssViolation(string $source): ?string
    {
        if (!mb_check_encoding($source, 'UTF-8')) {
            return 'custom.css must be valid UTF-8.';
        }
        $max = (int) config($this->context, 'lemma_render.custom_css.max_bytes', 262144);
        if (strlen($source) > $max) {
            return "custom.css exceeds the size limit ({$max} bytes).";
        }
        return null;
    }

    private function invalidPath(string $path): bool
    {
        if ($path === '' || !str_ends_with($path, '.twig')) {
            return true;
        }
        foreach (explode('/', $path) as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || preg_match('/^[A-Za-z0-9._-]+$/', $segment) !== 1
            ) {
                return true;
            }
        }
        return false;
    }

    private function userUuid(Request $request): ?string
    {
        $user = (array) $request->attributes->get('user', []);
        return isset($user['uuid']) && is_string($user['uuid']) ? $user['uuid'] : null;
    }
}
