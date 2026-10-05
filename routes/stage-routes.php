<?php

declare(strict_types=1);

use Glueful\Routing\Router;
use Thallo\Render\Http\Controllers\RenderAdminController;

/** @var Router $router */

/*
 * What the admin's open stages ask the renderer (block typeface plan Task 11). Loaded whenever
 * thallo.render is on — NOT gated by render.db_templates like admin-routes.php: switching template
 * editing off must not turn a stage's freshness check into a 404. Same admin group: auth, the
 * operator's selected workspace bound, and any of the three stage editors' permissions.
 */
$router->group(
    [
        'prefix' => '/v1/admin/render',
        'middleware' => ['auth', 'tenant_profile:admin', 'tenant_bootstrap', 'admin_tenant_binding'],
    ],
    function (Router $router): void {
        $router->get('/appearance-fingerprint', [RenderAdminController::class, 'appearanceFingerprint'])
            ->middleware('content_permission:content.edit,content.manage,templates.manage');
    },
);
