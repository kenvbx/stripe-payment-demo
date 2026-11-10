<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $events = WebhookEvent::query()
            ->with('payment:id,product_name,customer_name,customer_email')
            ->latest('received_at')
            ->paginate(min($request->integer('per_page', 50), 100));

        return response()->json($events);
    }
}
