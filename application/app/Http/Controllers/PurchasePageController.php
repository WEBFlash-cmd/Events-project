<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ticket\CancelTicketRequest;
use App\Services\TicketCancellationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PurchasePageController extends Controller
{
    public function orders(Request $request)
    {
        $orders = $request->user()->orders()->with(['event.venue', 'payment'])
            ->withCount('tickets')->orderByDesc('created_at')->orderByDesc('id')->paginate(12);

        return response()->view('purchases.orders', compact('orders'))->header('Cache-Control', 'private, no-store');
    }

    public function tickets(Request $request)
    {
        $tickets = $request->user()->tickets()->with(['event.venue', 'ticketType', 'order.payment'])
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(12);

        return response()->view('purchases.tickets', compact('tickets'))->header('Cache-Control', 'private, no-store');
    }

    public function ticket(Request $request, int $ticket)
    {
        $ticket = $request->user()->tickets()->with(['event.venue', 'ticketType', 'order.payment'])->findOrFail($ticket);

        return response()->view('purchases.ticket', compact('ticket'))->header('Cache-Control', 'private, no-store');
    }

    public function cancel(CancelTicketRequest $request, int $ticket, TicketCancellationService $service)
    {
        $ticket = $request->user()->tickets()->findOrFail($ticket);
        try {
            $service->cancel($ticket, $request->user());
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return redirect()->route('purchases.ticket', $ticket)->with('purchase_error',
                'Отмена недоступна: состояние билета или условия мероприятия изменились.');
        }

        return redirect()->route('purchases.ticket', $ticket)->with('purchase_success',
            'Билет отменён. Если требуется возврат денег, он отмечен в заказе. По срокам возврата обратитесь к организатору.');
    }
}
