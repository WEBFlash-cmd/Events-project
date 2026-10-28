<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\StoreEventRequest;
use App\Http\Requests\Event\UpdateEventRequest;
use App\Http\Requests\ConfirmWebActionRequest;
use App\Models\Category;
use App\Models\Event;
use App\Models\Order;
use App\Models\Venue;
use App\Services\EventService;
use App\Services\EventCancellationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrganizerEventPageController extends Controller
{
    public function index(Request $request)
    {
        $events = Event::query()->where('organizer_id', $request->user()->id)
            ->with(['category', 'venue'])->withCount('ticketTypes')
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(12);

        return response()->view('organizer.events.index', compact('events'))->header('Cache-Control', 'private, no-store');
    }

    public function create()
    {
        return $this->form(new Event());
    }

    public function store(StoreEventRequest $request, EventService $events)
    {
        $event = $events->createEvent($request->validated(), $request->user());

        return redirect()->route('organizer.events.edit', $event)->with('event_success', 'Черновик мероприятия создан.');
    }

    public function edit(Event $event)
    {
        return $this->form($event);
    }

    public function update(UpdateEventRequest $request, Event $event, EventService $events)
    {
        $events->updateEvent($event, $request->validated());

        return redirect()->route('organizer.events.edit', $event)->with('event_success', 'Изменения сохранены.');
    }

    public function publish(Event $event, EventService $events)
    {
        $events->publishEvent($event);

        return redirect()->route('organizer.events.edit', $event)->with('event_success', 'Мероприятие опубликовано в афише.');
    }

    public function cancel(ConfirmWebActionRequest $request, Event $event, EventCancellationService $events)
    {
        try {
            $events->cancel($event, $request->user());
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return redirect()->route('organizer.events.edit', $event)->with('event_error',
                'Отмена недоступна. Можно отменить только опубликованное мероприятие, которое ещё не закончилось.');
        }

        return redirect()->route('organizer.events.edit', $event)->with('event_success',
            'Мероприятие отменено. Резервы сняты, по оплаченным заказам отмечены возвраты и запланированы уведомления.');
    }

    private function form(Event $event)
    {
        $locked = $event->exists && (Order::query()->where('event_id', $event->id)->where('status', 'paid')->exists()
            || $event->tickets()->exists());

        return response()->view('organizer.events.form', [
            'event' => $event,
            'locked' => $locked,
            'categories' => Category::query()->orderBy('name')->orderBy('id')->get(),
            'venues' => Venue::query()->orderBy('name')->orderBy('id')->get(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
