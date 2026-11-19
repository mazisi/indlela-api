<?php

use Illuminate\Support\Facades\Route;

// API only: the SPA (indlela) is a separate app. Horizon's dashboard is at /horizon.
Route::get('/', fn () => response()->json(['name' => config('app.name').' API', 'docs' => 'docs/API.md']));
