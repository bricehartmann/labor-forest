<?php

use App\Http\Controllers\CliToolsController;

// lf add-project
Route::get('/add-project', [CliToolsController::class, 'addProject']);

// lf run <workflow>
Route::get('/run-workflow', [CliToolsController::class, 'runWorkflow']);
