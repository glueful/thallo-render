<?php

declare(strict_types=1);

use Thallo\Render\Http\Controllers\TemplatesAdminController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * DB-edited templates admin API. Triple-gated like the other packs:
 *   1. capability + kill-switch — this file loads only when thallo.render is enabled
 *      AND render.db_templates is true (else 404).
 *   2. auth — group middleware.
 *   3. content_permission — templates.manage on every route.
 *
 * Route grammar (spec §6 + custom-css follow-up): {path} spans slashes, so VERSION
 * routes register FIRST and every {path} is constrained to end in a known extension
 * (.twig, plus .css/.js/.json for custom.css and the read-only theme files) — the
 * parser stays deterministic (…/entry/blog.twig/versions can never be swallowed as a
 * generic show, because "…/versions" ends in no allowed extension). The CONTROLLER
 * grammar stays the authorization gate: routes only parse.
 */
$router->group(
    ['prefix' => '/v1/admin/render', 'middleware' => ['auth']],
    function (Router $router): void {
        $router->get('/templates/{path}/versions/{uuid}', [TemplatesAdminController::class, 'showVersion'])
            ->where('path', '.+\.(?:twig|css|js|json)')
            ->where('uuid', '[A-Za-z0-9_-]{12}')
            ->middleware('content_permission:templates.manage');
        $router->post('/templates/{path}/versions/{uuid}/restore', [TemplatesAdminController::class, 'restore'])
            ->where('path', '.+\.(?:twig|css|js|json)')
            ->where('uuid', '[A-Za-z0-9_-]{12}')
            ->middleware('content_permission:templates.manage');
        $router->get('/templates/{path}/versions', [TemplatesAdminController::class, 'versions'])
            ->where('path', '.+\.(?:twig|css|js|json)')
            ->middleware('content_permission:templates.manage');

        // Selectable themes (theme-setting spec §4): consumed by the Settings ->
        // General Theme card, so it carries the SETTINGS permission
        // (content.manage — mirror of routes/admin.php's settings/general),
        // not templates.manage.
        $router->get('/themes', [TemplatesAdminController::class, 'themes'])
            ->middleware('content_permission:content.manage');

        // Clone-theme: scaffold themes/{name}/ from an existing theme. Same
        // operator trust tier as template editing.
        $router->post('/themes', [TemplatesAdminController::class, 'createTheme'])
            ->middleware('content_permission:templates.manage');

        $router->get('/templates', [TemplatesAdminController::class, 'index'])
            ->middleware('content_permission:templates.manage');
        $router->get('/templates/{path}', [TemplatesAdminController::class, 'show'])
            ->where('path', '.+\.(?:twig|css|js|json)')
            ->middleware('content_permission:templates.manage');
        $router->put('/templates/{path}', [TemplatesAdminController::class, 'save'])
            ->where('path', '.+\.(?:twig|css|js|json)')
            ->middleware('content_permission:templates.manage');
        $router->delete('/templates/{path}', [TemplatesAdminController::class, 'delete'])
            ->where('path', '.+\.(?:twig|css|js|json)')
            ->middleware('content_permission:templates.manage');
    },
);
