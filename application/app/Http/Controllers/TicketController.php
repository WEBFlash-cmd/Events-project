<?php

namespace App\Http\Controllers;

use App\Http\Requests\Purchase\IndexOwnPurchasesRequest;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;

class TicketController extends Controller
{
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
