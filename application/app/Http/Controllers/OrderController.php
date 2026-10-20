<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Models\Event;
use App\Models\Order;
use App\Services\OrderReservationService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function show(Order $order): JsonResponse
    {
        return response()->json(['data' => $order->load(['items', 'payment', 'tickets'])]);
    }

    public function cancel(Order $order, OrderReservationService $reservations): JsonResponse
    {
        return response()->json(['data' => $reservations->cancel($order)]);
    }

    public function store(StoreOrderRequest $request, Event $event, PaymentService $payments): JsonResponse
    {
        $order = DB::transaction(function () use ($request, $event, $payments) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);

            if ($event->status !== EventStatus::PUBLISHED) {
                throw ValidationException::withMessages([
                    'event' => 'Orders can only be created for published events.',
                ]);
            }

            if ($event->starts_at->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages([
                    'event' => 'Orders cannot be created after the event has started.',
                ]);
            }

            $items = $request->validated('items');
            $ticketTypes = $event->ticketTypes()
                ->whereIn('id', array_column($items, 'ticket_type_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $totalCents = 0;
            $positions = [];

            foreach ($items as $index => $item) {
                $ticketType = $ticketTypes->get($item['ticket_type_id']);

                if ($ticketType === null) {
                    throw ValidationException::withMessages([
                        "items.$index.ticket_type_id" => 'The ticket type does not belong to this event.',
                    ]);
                }

                if ($item['quantity'] > $ticketType->quantity) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => 'Not enough tickets available.',
                    ]);
                }

                // Calculate in minor currency units to avoid floating-point rounding.
                $priceCents = (int) str_replace('.', '', $ticketType->price);
                $remainingCents = 999999999999 - $totalCents;

                if ($priceCents > 0 && $item['quantity'] > intdiv($remainingCents, $priceCents)) {
                    throw ValidationException::withMessages([
                        'items' => 'The order total exceeds the supported amount.',
                    ]);
                }

                $totalCents += $priceCents * $item['quantity'];
                $positions[] = [
                    'ticket_type_id' => $ticketType->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $ticketType->price,
                ];

                $ticketType->decrement('quantity', $item['quantity']);
            }

            if ($totalCents === 0) {
                throw ValidationException::withMessages([
                    'items' => 'This payment system requires a positive order total. Free checkout is not supported yet.',
                ]);
            }

            $order = Order::create([
                'user_id' => $request->user()->id,
                'event_id' => $event->id,
                'total_amount' => sprintf('%d.%02d', intdiv($totalCents, 100), $totalCents % 100),
                'status' => 'pending',
                'expires_at' => now()->addMinutes(config('orders.reservation_minutes'))->min($event->starts_at),
            ]);
            $order->items()->createMany($positions);
            $payments->prepare($order);

            return $order->load('items');
        });

        $payments->initialize($order->payment);

        return response()->json([
            'data' => $order->fresh()->load(['items', 'payment', 'tickets']),
        ], 201);
    }
}
