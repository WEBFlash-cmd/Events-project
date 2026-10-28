<?php

namespace App\Http\Controllers;

use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Purchase\IndexOwnPurchasesRequest;
use App\Models\Event;
use App\Models\Order;
use App\Services\OrderReservationService;
use App\Services\PaymentService;
use App\Services\OrderCheckoutService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function index(IndexOwnPurchasesRequest $request): JsonResponse
    {
        $orders = $request->user()->orders()
            ->with(['event.venue', 'items', 'payment'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return response()->json($orders);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json(['data' => $order->load(['items', 'payment', 'tickets'])]);
    }

    public function cancel(Order $order, OrderReservationService $reservations): JsonResponse
    {
        return response()->json(['data' => $reservations->cancel($order)]);
    }

    public function store(StoreOrderRequest $request, Event $event, PaymentService $payments, OrderCheckoutService $checkout): JsonResponse
    {
        $order = $checkout->create($event, $request->user(), $request->validated('items'));

        $payments->initialize($order->payment);

        return response()->json([
            'data' => $order->fresh()->load(['items', 'payment', 'tickets']),
        ], 201);
    }
}
