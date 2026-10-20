<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AccessController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\MetaActionController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\MetaDataController;
use App\Http\Controllers\Api\V1\MetaTypeController;
use App\Http\Controllers\Api\V1\MetaParamController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WorkflowController;
use App\Models\User;

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
    Route::put('/metas/{meta}/permissions', [PermissionController::class, 'saveMeta']);
    Route::get('/workflows/{meta}/permissions', [PermissionController::class, 'workflow']);
    Route::put('/workflows/{meta}/permissions', [PermissionController::class, 'saveWorkflow']);

    Route::apiResource('metas', MetaController::class);
    Route::apiResource('metas.params', MetaParamController::class)->shallow();

    // A datagrid's toolbar buttons and the forms they open.
    Route::get('/metas/{meta}/actions', [MetaActionController::class, 'index']);
    Route::post('/metas/{meta}/actions', [MetaActionController::class, 'store']);
    Route::put('/actions/{action}', [MetaActionController::class, 'update']);
    Route::delete('/actions/{action}', [MetaActionController::class, 'destroy']);

    // Workflows: the designer's statuses and transitions, and moving records through them.
    Route::get('/workflows/{meta}', [WorkflowController::class, 'show']);
    Route::put('/workflows/{meta}/layout', [WorkflowController::class, 'layout']);
    Route::post('/workflows/{meta}/statuses', [WorkflowController::class, 'storeStatus']);
    Route::put('/wfm-statuses/{status}', [WorkflowController::class, 'updateStatus']);
    Route::delete('/wfm-statuses/{status}', [WorkflowController::class, 'destroyStatus']);
    Route::post('/workflows/{meta}/transitions', [WorkflowController::class, 'storeTransition']);
    Route::put('/wfm-transitions/{transition}', [WorkflowController::class, 'updateTransition']);
    Route::delete('/wfm-transitions/{transition}', [WorkflowController::class, 'destroyTransition']);
    Route::get('/workflows/{meta}/records/{id}', [WorkflowController::class, 'record'])->whereNumber('id');
    Route::post('/workflows/{meta}/records/{id}/move', [WorkflowController::class, 'move'])->whereNumber('id');

    // The values a form opens with, from a get meta.
    Route::get('/metas/{meta}/fill', [MetaDataController::class, 'fill']);

    // Records behind a datagrid or form meta.
    Route::get('/metas/{meta}/data', [MetaDataController::class, 'index']);
    Route::post('/metas/{meta}/data', [MetaDataController::class, 'store']);
    Route::get('/metas/{meta}/data/{id}', [MetaDataController::class, 'show'])->whereNumber('id');
    Route::match(['put', 'patch'], '/metas/{meta}/data/{id}', [MetaDataController::class, 'update'])->whereNumber('id');
    Route::delete('/metas/{meta}/data/{id}', [MetaDataController::class, 'destroy'])->whereNumber('id');
});