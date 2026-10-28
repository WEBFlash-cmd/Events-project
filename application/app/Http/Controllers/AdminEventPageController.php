<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\IndexAdminEventPagesRequest;
use App\Models\Category;
use App\Models\Event;
use App\Models\Order;
use App\Models\Venue;
use App\Services\AdminEventService;
use App\Http\Requests\ConfirmWebActionRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminEventPageController extends Controller
{
    public function index(IndexAdminEventPagesRequest $request, AdminEventService $events)
    {
        $filters = array_filter($request->validated(), fn ($value) => $value !== null && $value !== '');

        return response()->view('admin.events.index', [
            'events' => $events->index($filters),
            'filters' => $filters,
            'categories' => Category::query()->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'venues' => Venue::query()->orderBy('name')->orderBy('id')->get(['id', 'name']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Event $event, AdminEventService $events)
    {
        $event = $events->show($event)->loadCount('tickets');
        $hasOrders = Order::query()->where('event_id', $event->id)->exists();

        return response()->view('admin.events.show', compact('event', 'hasOrders'))->header('Cache-Control', 'private, no-store');
    }

    public function destroy(ConfirmWebActionRequest $request, Event $event, AdminEventService $events)
    {
        try {
            $events->delete($event, $request->user());
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return redirect()->route('admin.events.show', $event)->with('admin_error',
                'Мероприятие нельзя удалить: у него есть заказы или билеты. История покупок должна сохраниться.');
        }

        return redirect()->route('admin.events.index')->with('admin_success', 'Мероприятие и его типы билетов удалены.');
    }
}
