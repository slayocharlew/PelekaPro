<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\MapUsageService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MapUsageController extends Controller
{
    public function index(Request $request, MapUsageService $usage): Response
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m', 'regex:/^[0-9]{4}-[0-9]{2}$/']]);
        $month = isset($data['month'])
            ? CarbonImmutable::createFromFormat('!Y-m', $data['month'], MapUsageService::TIMEZONE)
            : CarbonImmutable::now(MapUsageService::TIMEZONE)->startOfMonth();

        return response()->view('portal.map-usage.index', $usage->dashboard($month))
            ->header('Cache-Control', 'no-store, private');
    }
}
