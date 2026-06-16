<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Service\MapArchiveStats;
use Illuminate\Contracts\View\View;

/**
 * The map-archive admin dashboard. Auth-gated (the app is admin-only, so any authenticated user is an
 * admin). Thin: it just hands MapArchiveStats output to the view.
 */
class DashboardController extends Controller
{
    public function index(MapArchiveStats $stats): View
    {
        $days = 30;

        return view('admin.dashboard', [
            'overview' => $stats->overview(),
            'downloadsPerDay' => $stats->downloadsPerDay($days),
            'recentDownloads' => $stats->recentDownloads(),
            'recentFailures' => $stats->recentFailures(),
            'failingServers' => $stats->failingServers($days),
            'sourceBreakdown' => $stats->sourceBreakdown($days),
            'statusBreakdown' => $stats->statusBreakdown(),
            'days' => $days,
        ]);
    }
}
