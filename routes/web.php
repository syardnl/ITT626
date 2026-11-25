<?php

use Illuminate\Support\Facades\Route;

// The React SPA owns every non-API path; the page load also sets the XSRF-TOKEN cookie.
Route::view('/{path?}', 'app')->where('path', '^(?!api/|up$|sanctum/).*$');
