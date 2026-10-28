<?php

namespace App\Http\Controllers;

use App\Http\Requests\Order\StoreCheckoutRequest;
use App\Models\Event;
use App\Models\Order;
use App\Models\WaitlistEntry;
use App\Services\EventService;
use App\Services\OrderCheckoutService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CheckoutController extends Controller
{
    public function event(Request $request, Event $event, EventService $events)
    {
        $events->showPublishedEvent($event);
        if (!$request->user('web')) {
            $request->session()->put('url.intended', route('events.show', $event));
        }

        $waitlistEntries = $request->user('web')
            ? WaitlistEntry::query()->where('event_id', $event->id)->where('user_id', $request->user('web')->id)
                ->whereIn('status', ['waiting', 'offered'])->get()->keyBy('ticket_type_id')
            : collect();

        return response()->view('checkout.event', ['event' => $event->load('ticketTypes'), 'waitlistEntries' => $waitlistEntries])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreCheckoutRequest $request, Event $event, OrderCheckoutService $checkout, PaymentService $payments)
    {
        $order = $checkout->create($event, $request->user(), $request->validated('items'), $request->validated('checkout_reference'));
        $request->session()->put('checkout_order_id', $order->id);
        $payments->initialize($order->payment);

        return response()->json(['redirect' => route('checkout.order', $order)]);
    }

    public function order(Order $order)
    {
        Gate::authorize('view', $order);

        return response()->view('checkout.order', ['order' => $order->load(['event.venue', 'items.ticketType'])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function result(Request $request)
    {
        $order = $request->user()->orders()->findOrFail($request->session()->get('checkout_order_id'));

        // Query parameters supplied by the gateway never determine the payment status.
        return redirect()->route('checkout.order', $order);
    }
}
