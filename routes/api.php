<?php

use App\Http\Controllers\Api\V1\AccessController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ChartController;
use App\Http\Controllers\Api\V1\ExcelImportController;
use App\Http\Controllers\Api\V1\MatrixController;
use App\Http\Controllers\Api\V1\MetaActionController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\MetaDataController;
use App\Http\Controllers\Api\V1\MetaParamController;
use App\Http\Controllers\Api\V1\MetaTypeController;
use App\Http\Controllers\Api\V1\MetaVersionController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationRuleController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\ProcessFlowController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\SessionValueController;
use App\Http\Controllers\Api\V1\StageQueryController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WidgetController;
use App\Http\Controllers\Api\V1\WorkflowController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/meta-types', [MetaTypeController::class, 'index']);
    Route::get('/metas/options', [MetaController::class, 'options']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // The signed-in user's own profile.
    Route::get('/user', [ProfileController::class, 'show']);
    Route::put('/user', [ProfileController::class, 'update']);
    Route::put('/user/password', [ProfileController::class, 'password']);
    Route::get('/user/access', [AccessController::class, 'show']);
    Route::get('/user/session', [SessionValueController::class, 'mine']);
    Route::post('/user/session/refresh', [SessionValueController::class, 'refresh']);
    Route::post('/session-values/test', [SessionValueController::class, 'test']);
    Route::apiResource('session-values', SessionValueController::class)->except('show');

    // The signed-in user's notifications.
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

    // Admins only: roles, who has them, and what each role may do.
    Route::apiResource('roles', RoleController::class)->except('show');
    Route::get('/users', [UserController::class, 'index']);
    Route::put('/users/{user}/roles', [UserController::class, 'roles']);
    Route::get('/metas/{meta}/permissions', [PermissionController::class, 'meta']);
    Route::put('/metas/{meta}/permissions', [PermissionController::class, 'saveMeta'])->middleware('meta.version');
    Route::get('/workflows/{meta}/permissions', [PermissionController::class, 'workflow']);
    Route::put('/workflows/{meta}/permissions', [PermissionController::class, 'saveWorkflow']);

    // meta.version: a change to a meta or its parts keeps how it was as a version (SavesMetaVersion).
    Route::apiResource('metas', MetaController::class)->middlewareFor('update', 'meta.version');
    // Tags saying what metas are for: anyone adds one, admins rename and remove them.
    Route::apiResource('tags', TagController::class)->except('show');
    Route::apiResource('metas.params', MetaParamController::class)->shallow()->middlewareFor(['store', 'update', 'destroy'], 'meta.version');

    // A datagrid's toolbar buttons and the forms they open.
    Route::get('/metas/{meta}/actions', [MetaActionController::class, 'index']);
    Route::middleware('meta.version')->group(function () {
        Route::post('/metas/{meta}/actions', [MetaActionController::class, 'store']);
        Route::put('/actions/{action}', [MetaActionController::class, 'update']);
        Route::delete('/actions/{action}', [MetaActionController::class, 'destroy']);
    });

    // Workflows: the designer's statuses and transitions, and moving records through them.
    Route::get('/workflows/{meta}', [WorkflowController::class, 'show']);
    Route::middleware('meta.version')->group(function () {
        Route::put('/workflows/{meta}/layout', [WorkflowController::class, 'layout']);
        Route::post('/workflows/{meta}/statuses', [WorkflowController::class, 'storeStatus']);
        Route::put('/wfm-statuses/{status}', [WorkflowController::class, 'updateStatus']);
        Route::delete('/wfm-statuses/{status}', [WorkflowController::class, 'destroyStatus']);
        Route::post('/workflows/{meta}/transitions', [WorkflowController::class, 'storeTransition']);
        Route::put('/wfm-transitions/{transition}', [WorkflowController::class, 'updateTransition']);
        Route::delete('/wfm-transitions/{transition}', [WorkflowController::class, 'destroyTransition']);
    });
    Route::get('/workflows/{meta}/records/{id}', [WorkflowController::class, 'record'])->whereNumber('id');
    Route::post('/workflows/{meta}/records/{id}/move', [WorkflowController::class, 'move'])->whereNumber('id');

    // The values a form opens with, from a get meta.
    Route::get('/metas/{meta}/fill', [MetaDataController::class, 'fill']);

    // Stage queries: SQL that changes data, run with named parameters.
    Route::post('/metas/{meta}/run', [StageQueryController::class, 'run']);

    // Charts: their numbers, and for the builder a preview and the columns to pick from.
    Route::get('/metas/{meta}/chart', [ChartController::class, 'show']);
    Route::post('/metas/{meta}/chart/preview', [ChartController::class, 'preview']);
    Route::get('/metas/{meta}/columns', [ChartController::class, 'columns']);

    // Records behind a datagrid or form meta.
    Route::get('/metas/{meta}/data', [MetaDataController::class, 'index']);
    Route::get('/metas/{meta}/statement', [MetaDataController::class, 'statement']);
    Route::get('/metas/{meta}/facets', [MetaDataController::class, 'facets']);
    Route::post('/metas/{meta}/data', [MetaDataController::class, 'store']);
    Route::get('/metas/{meta}/autonumber', [MetaDataController::class, 'autonumber']);
    Route::get('/metas/{meta}/data/{id}', [MetaDataController::class, 'show'])->where('id', '[A-Za-z0-9_.:-]+');
    Route::match(['put', 'patch'], '/metas/{meta}/data/{id}', [MetaDataController::class, 'update'])->where('id', '[A-Za-z0-9_.:-]+');
    Route::delete('/metas/{meta}/data/{id}', [MetaDataController::class, 'destroy'])->where('id', '[A-Za-z0-9_.:-]+');

    // Excel: any data meta's records as a file, and excel_import metas loading files into a table.
    Route::get('/metas/{meta}/export', [MetaDataController::class, 'export']);
    Route::get('/metas/{meta}/import/template', [ExcelImportController::class, 'template']);
    Route::post('/metas/{meta}/import', [ExcelImportController::class, 'store']);

    // The page builder: the widget library, and pages laid out from widget metas.
    Route::apiResource('widgets', WidgetController::class)->except('show');
    Route::get('/metas/{meta}/page', [PageController::class, 'show']);
    Route::put('/metas/{meta}/page', [PageController::class, 'save'])->middleware('meta.version');

    // The notification builder: what a notification meta would send, sending it, and its log.
    Route::get('/metas/{meta}/notification/preview', [NotificationRuleController::class, 'preview']);
    Route::post('/metas/{meta}/notification/send', [NotificationRuleController::class, 'send']);
    Route::get('/metas/{meta}/notification/log', [NotificationRuleController::class, 'log']);

    // Matrices: a value for each pair of records from two grids (e.g. bidder × item prices).
    Route::get('/metas/{meta}/matrix', [MatrixController::class, 'show']);
    Route::put('/metas/{meta}/matrix', [MatrixController::class, 'save']);

    // Version control: saved versions of a meta, comparing them and restoring one.
    Route::get('/meta-versions/deleted', [MetaVersionController::class, 'deleted']);
    Route::get('/metas/{meta}/versions', [MetaVersionController::class, 'index']);
    Route::post('/metas/{meta}/versions', [MetaVersionController::class, 'store']);
    Route::get('/meta-versions/{version}/diff', [MetaVersionController::class, 'diff']);
    Route::post('/meta-versions/{version}/restore', [MetaVersionController::class, 'restore']);

    // Process flows: the forms a form's new record runs next, and the values they take.
    Route::get('/metas/{meta}/process-flow', [ProcessFlowController::class, 'show']);
    Route::put('/metas/{meta}/process-flow', [ProcessFlowController::class, 'save'])->middleware('meta.version');

    // Scheduled forms: when each schedule runs, and which form it runs over which grid's rows.
    Route::apiResource('schedules', ScheduleController::class)->except('show');
    Route::post('/schedules/{schedule}/forms', [ScheduleController::class, 'addForm']);
    Route::delete('/meta-schedules/{metaSchedule}', [ScheduleController::class, 'removeForm']);
    Route::post('/schedules/{schedule}/run', [ScheduleController::class, 'run']);
});
