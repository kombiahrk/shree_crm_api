<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Customer;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics for the authenticated user's organization.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function stats(Request $request)
    {
        $user = Auth::user();
        $organization = $user->organization;

        if (!$organization) {
            return response()->json(['message' => 'Organization not found for the authenticated user.'], 404);
        }

        // Total Customers
        $totalCustomers = $organization->customers()->count();

        // New Customers this Week
        $lastWeek = Carbon::now()->subWeek();
        $newCustomersThisWeek = $organization->customers()
                                           ->where('created_at', '>=', $lastWeek)
                                           ->count();

        // Recent Customers (e.g., last 5)
        $recentCustomers = $organization->customers()
                                        ->orderBy('created_at', 'desc')
                                        ->take(5)
                                        ->get(['id', 'name', 'email', 'created_at']);

        return response()->json([
            'totalCustomers' => $totalCustomers,
            'newCustomersThisWeek' => $newCustomersThisWeek,
            'recentCustomers' => $recentCustomers,
        ]);
    }
}
