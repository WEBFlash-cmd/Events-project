<?php

namespace App\Http\Controllers;

use App\Http\Requests\TicketType\StoreTicketTypeRequest;
use App\Http\Requests\TicketType\UpdateTicketTypeRequest;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\TicketTypeService;
use Illuminate\Http\JsonResponse;

class TicketTypeController extends Controller
{
    public function __construct(private TicketTypeService $ticketTypeService)
    {
    }

    public function index(Event $event): JsonResponse
    {
        return response()->json([
            'data' => $this->ticketTypeService->getTicketTypes($event),
        ]);
    }

    public function store(StoreTicketTypeRequest $request, Event $event): JsonResponse
    {
        return response()->json([
            'data' => $this->ticketTypeService->createTicketType($event, $request->validated()),
        ], 201);
    }

    public function update(UpdateTicketTypeRequest $request, Event $event, TicketType $ticketType): JsonResponse
    {
        return response()->json([
            'data' => $this->ticketTypeService->updateTicketType($event, $ticketType, $request->validated()),
        ]);
    }
}
