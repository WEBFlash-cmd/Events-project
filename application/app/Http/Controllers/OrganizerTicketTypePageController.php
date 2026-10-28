<?php

namespace App\Http\Controllers;

use App\Http\Requests\TicketType\StoreTicketTypeRequest;
use App\Http\Requests\TicketType\UpdateTicketTypeRequest;
use App\Http\Requests\TicketType\UpdateTicketQuantityRequest;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\TicketTypeService;

class OrganizerTicketTypePageController extends Controller
{
    public function index(Event $event)
    {
        $types = $event->ticketTypes()->orderBy('id')->paginate(12);

        return response()->view('organizer.tickets.index', compact('event', 'types'))->header('Cache-Control', 'private, no-store');
    }

    public function create(Event $event)
    {
        return $this->form($event, new TicketType());
    }

    public function store(StoreTicketTypeRequest $request, Event $event, TicketTypeService $tickets)
    {
        $type = $tickets->createTicketType($event, $request->validated());

        return redirect()->route('organizer.tickets.edit', [$event, $type])->with('ticket_success', 'Тип билета создан.');
    }

    public function edit(Event $event, TicketType $ticketType)
    {
        return $this->form($event, $ticketType);
    }

    public function update(UpdateTicketTypeRequest $request, Event $event, TicketType $ticketType, TicketTypeService $tickets)
    {
        // Stock has its own form so editing the price cannot overwrite a live reservation.
        $tickets->updateTicketType($event, $ticketType, $request->safe()->only(['name', 'description', 'price']));

        return redirect()->route('organizer.tickets.edit', [$event, $ticketType])->with('ticket_success', 'Данные типа билета сохранены.');
    }

    public function quantity(UpdateTicketQuantityRequest $request, Event $event, TicketType $ticketType, TicketTypeService $tickets)
    {
        $tickets->updateTicketType($event, $ticketType, ['quantity' => $request->integer('quantity')], $request->integer('expected_quantity'));

        return redirect()->route('organizer.tickets.edit', [$event, $ticketType])->with('ticket_success',
            'Остаток сохранён. Часть мест может сразу перейти участникам листа ожидания.');
    }

    private function form(Event $event, TicketType $ticketType)
    {
        return response()->view('organizer.tickets.form', compact('event', 'ticketType'))->header('Cache-Control', 'private, no-store');
    }
}
