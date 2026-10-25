<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Event\IndexAdminEventsRequest;
use App\Models\Event;
use App\Services\AdminEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EventController extends Controller
{
    public function destroy(Request $request, Event $event): Response
    {
        $this->events->delete($event, $request->user());
        return response()->noContent();
    }

    public function __construct(private AdminEventService $events)
    {
    }

    public function index(IndexAdminEventsRequest $request): JsonResponse
    {
        return response()->json($this->events->index($request->validated()));
    }

    public function show(Event $event): JsonResponse
    {
        return response()->json(['data' => $this->events->show($event)]);
    }
}
