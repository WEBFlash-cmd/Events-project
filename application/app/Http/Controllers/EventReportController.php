<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\IndexEventParticipantsRequest;
use App\Models\Event;
use App\Services\EventReportService;
use Illuminate\Http\JsonResponse;

class EventReportController extends Controller
{
    public function __construct(private EventReportService $reports)
    {
    }

    public function participants(IndexEventParticipantsRequest $request, Event $event): JsonResponse
    {
        return response()->json($this->reports->participants($event, $request->validated()));
    }

    public function sales(Event $event): JsonResponse
    {
        return response()->json(['data' => $this->reports->sales($event)]);
    }
}
