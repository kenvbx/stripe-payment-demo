<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function metrics(): JsonResponse
    {
        $timezone = config('app.timezone', 'Asia/Ho_Chi_Minh');
        $today = Carbon::now($timezone);
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();
        $totalBookings = Appointment::query()->count();
        $completedBookings = Appointment::query()->where('status', 'completed')->count();

        return response()->json([
            'data' => [
                'total_bookings' => $totalBookings,
                'upcoming_today' => Appointment::query()
                    ->whereBetween('starts_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])
                    ->whereNotIn('status', ['cancelled', 'completed'])
                    ->count(),
                'completion_rate' => $totalBookings > 0 ? round(($completedBookings / $totalBookings) * 100, 1) : 0,
                'new_customers' => Customer::query()->whereBetween('created_at', [$monthStart, $monthEnd])->count(),
                'timezone' => $timezone,
                'generated_at' => $today->toIso8601String(),
            ],
        ]);
    }
}
