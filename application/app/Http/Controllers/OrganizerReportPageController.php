<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\IndexEventParticipantsRequest;
use App\Http\Requests\Ticket\CheckInTicketRequest;
use App\Http\Requests\Ticket\FindOrganizerTicketRequest;
use App\Models\Event;
use App\Models\Ticket;
use App\Services\EventReportService;
use App\Services\TicketCheckInService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrganizerReportPageController extends Controller
{
    public function sales(Event $event, EventReportService $reports)
    {
        return response()->view('organizer.reports.sales', ['event' => $event, 'report' => $reports->sales($event)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function participants(IndexEventParticipantsRequest $request, Event $event, EventReportService $reports)
    {
        return response()->view('organizer.reports.participants', [
            'event' => $event, 'participants' => $reports->participants($event, $request->validated()),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function checkIn(FindOrganizerTicketRequest $request, Event $event)
    {
        $number = $request->validated('number');
        $ticket = $number === null ? null : $event->tickets()->where('number', $number)
            ->with(['user:id,email', 'ticketType:id,name', 'order:id,status,event_id,user_id'])->first();

        return response()->view('organizer.reports.check-in', compact('event', 'number', 'ticket'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function storeCheckIn(CheckInTicketRequest $request, Event $event, Ticket $ticket, TicketCheckInService $checkIn)
    {
        try {
            $checkIn->checkIn($event, $ticket, $request->user());
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return redirect()->route('organizer.reports.check-in', ['event' => $event, 'number' => $ticket->number])
                ->with('check_in_error', 'Вход не зарегистрирован: билет уже использован, недействителен, заказ не оплачен или мероприятие закрыто.');
        }

        return redirect()->route('organizer.reports.check-in', ['event' => $event, 'number' => $ticket->number])
            ->with('check_in_success', 'Вход зарегистрирован. Повторный проход по этому билету недоступен.');
    }
}
