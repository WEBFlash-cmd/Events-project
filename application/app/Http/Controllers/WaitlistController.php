<?php

namespace App\Http\Controllers;

use App\Http\Requests\Waitlist\WaitlistRequest;
use App\Http\Requests\Waitlist\IndexWaitlistRequest;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\WaitlistEntry;
use App\Services\PaymentService;
use App\Services\WaitlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class WaitlistController extends Controller
{
    public function __construct(private WaitlistService $waitlist)
    {
    }

    public function index(IndexWaitlistRequest $request): JsonResponse
    {
        $query = WaitlistEntry::query()->where('user_id', $request->user()->id)
            ->with(['event.venue', 'ticketType', 'order.items', 'order.payment']);
        if ($request->has('status')) {
            $query->where('status', $request->validated('status'));
        }

        return response()->json($query->orderByDesc('queued_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 15))->withQueryString());
    }

    public function store(WaitlistRequest $request, Event $event, TicketType $ticketType): JsonResponse
    {
        $entry = $this->waitlist->join($event, $ticketType, $request->user());

        return response()->json(['data' => $entry], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function show(WaitlistRequest $request, Event $event, TicketType $ticketType): JsonResponse
    {
        return response()->json(['data' => $this->waitlist->ownEntry($event, $ticketType, $request->user())]);
    }

    public function destroy(WaitlistRequest $request, Event $event, TicketType $ticketType): Response
    {
        $this->waitlist->leave($event, $ticketType, $request->user());

        return response()->noContent();
    }

    public function checkout(WaitlistRequest $request, Event $event, TicketType $ticketType, PaymentService $payments): JsonResponse
    {
        $order = $this->waitlist->accept($event, $ticketType, $request->user());
        $payments->initialize($order->payment);

        return response()->json(['data' => $order->fresh()->load(['items', 'payment', 'tickets'])]);
    }
}
