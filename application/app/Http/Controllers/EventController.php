<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Models\Event;
use App\Services\EventService;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\Event\IndexEventRequest;

class EventController extends Controller
{
    public function __construct(private EventService $eventService)
    {
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->eventService->createEvent($request->validated(), $request->user()),
        ], 201);
    }

    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        return response()->json([
            'data' => $this->eventService->updateEvent($event, $request->validated()),
        ]);
    }

    public function publish(Event $event): JsonResponse
    {
        return response()->json(['data' => $this->eventService->publishEvent($event)]);
    }

    public function index(IndexEventRequest $request): JsonResponse
    {
        return response()->json(
            $this->eventService->getPublishedEvents($request->validated())
        );
    }

    public function show(Event $event): JsonResponse
    {
        return response()->json(['data' => $this->eventService->showPublishedEvent($event)]);
    }
}
