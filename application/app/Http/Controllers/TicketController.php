<?php

namespace App\Http\Controllers;

use App\Http\Requests\Purchase\IndexOwnPurchasesRequest;
use App\Http\Requests\Ticket\CheckInTicketRequest;
use App\Http\Requests\Ticket\CancelTicketRequest;
use App\Models\Event;
use App\Models\Ticket;
use App\Services\TicketCheckInService;
use App\Services\TicketCancellationService;
use Illuminate\Http\JsonResponse;

class TicketController extends Controller
{
    public function cancel(CancelTicketRequest $request, Ticket $ticket, TicketCancellationService $service): JsonResponse
    {
        return response()->json(['data' => $service->cancel($ticket, $request->user())]);
    }

    public function checkIn(CheckInTicketRequest $request, Event $event, Ticket $ticket, TicketCheckInService $service): JsonResponse
    {
        return response()->json(['data' => $service->checkIn($event, $ticket, $request->user())]);
    }

    public function index(IndexOwnPurchasesRequest $request): JsonResponse
    {
        $tickets = $request->user()->tickets()
            ->with(['event.venue', 'ticketType', 'order:id,status'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return response()->json($tickets);
    }

    public function show(Ticket $ticket): JsonResponse
    {
        return response()->json([
            'data' => $ticket->load(['event.venue', 'ticketType', 'order:id,status']),
        ]);
    }
}
