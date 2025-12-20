<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AppVersionController extends Controller
{
    /**
     * Get the latest mobile app version.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLatestVersion()
    {
        return response()->json([
            'version' => env('MOBILE_APP_LATEST_VERSION', '1.0.0'), // Hardcoded for now
            'update_url' => env('MOBILE_APP_UPDATE_URL', 'https://example.com/latest-app.apk'), // Example URL
        ]);
    }
}
