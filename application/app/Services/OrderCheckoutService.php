<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderCheckoutService
{
    public function __construct(private PaymentService $payments, private WaitlistService $waitlist)
    {
    }

    public function create(Event $event, User $user, array $items, ?string $checkoutReference = null): Order
    {
        // Release expired offers independently of whether this new purchase passes validation.
        $this->waitlist->process($event);
        return DB::transaction(function () use ($event, $user, $items, $checkoutReference) {
            $event = Event::query()->lockForUpdate()->findOrFail($event->id);

            if ($checkoutReference !== null) {
                $existing = Order::query()->where('checkout_reference', $checkoutReference)->first();
                if ($existing !== null) {
                    abort_unless($existing->user_id === $user->id, 403);
                    $previous = $existing->items()->get()->map(fn ($item) => [
                        'ticket_type_id' => (int) $item->ticket_type_id, 'quantity' => (int) $item->quantity,
                    ])->sortBy('ticket_type_id')->values()->all();
                    $requested = collect($items)->map(fn ($item) => [
                        'ticket_type_id' => (int) $item['ticket_type_id'], 'quantity' => (int) $item['quantity'],
                    ])->sortBy('ticket_type_id')->values()->all();
                    abort_unless($existing->event_id === $event->id && $previous === $requested,
                        409, 'Этот запрос уже использован для другого состава заказа. Обновите страницу.');

                    return $existing->load('items');
                }
            }

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

                // A release may have committed after process() above, before this event lock.
                if ($this->waitlist->hasPriority($event, $ticketType->id)) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => 'Available tickets must first be offered to the waitlist.',
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
                'user_id' => $user->id,
                'checkout_reference' => $checkoutReference,
                'event_id' => $event->id,
                'total_amount' => sprintf('%d.%02d', intdiv($totalCents, 100), $totalCents % 100),
                'status' => 'pending',
                'expires_at' => now()->addMinutes(config('orders.reservation_minutes'))->min($event->starts_at),
            ]);
            $order->items()->createMany($positions);
            $this->payments->prepare($order);

            return $order->load('items');
        });
    }
}
