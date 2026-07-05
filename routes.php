<?php

declare(strict_types=1);

use Glueful\Extensions\EmailNotification\Http\SettingsController;
use Glueful\Extensions\EmailNotification\Http\TemplatesController;
use Glueful\Routing\Router;

/** @var \Glueful\Routing\Router $router Router instance injected by RouteManifest::load() */

$router->group(['prefix' => '/email', 'middleware' => ['auth']], function (Router $router): void {
    $gate = 'email_permission:email.templates.manage';

    $router->get('/templates', [TemplatesController::class, 'index'])->middleware([$gate]);
    $router->put('/templates/{key}', [TemplatesController::class, 'save'])
        ->where('key', '[a-z0-9][a-z0-9._-]*')
        ->middleware([$gate]);
    $router->delete('/templates/{key}', [TemplatesController::class, 'reset'])
        ->where('key', '[a-z0-9][a-z0-9._-]*')
        ->middleware([$gate]);
    $router->post('/templates/{key}/test', [TemplatesController::class, 'testSend'])
        ->where('key', '[a-z0-9][a-z0-9._-]*')
        ->middleware([$gate]);

    $router->get('/settings', [SettingsController::class, 'show'])->middleware([$gate]);
    $router->put('/settings', [SettingsController::class, 'save'])->middleware([$gate]);
    $router->post('/settings/test', [SettingsController::class, 'testSend'])->middleware([$gate]);
});
