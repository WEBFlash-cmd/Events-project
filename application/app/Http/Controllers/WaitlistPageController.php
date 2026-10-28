<?php

namespace App\Http\Controllers;

use App\Http\Requests\Waitlist\WaitlistRequest;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\WaitlistEntry;
use App\Services\PaymentService;
use App\Services\WaitlistService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WaitlistPageController extends Controller
{
    public function index(Request $request)
    {
        $entries = WaitlistEntry::query()->where('user_id', $request->user()->id)
            ->with(['event.venue', 'ticketType', 'order.payment'])
            ->orderByDesc('queued_at')->orderByDesc('id')->paginate(12);

        return response()->view('waitlist.index', compact('entries'))->header('Cache-Control', 'private, no-store');
    }

    public function join(WaitlistRequest $request, Event $event, TicketType $ticketType, WaitlistService $waitlist)
    {
        try {
            $waitlist->join($event, $ticketType, $request->user());
        } catch (HttpException $exception) {
            return $this->unavailable($exception);
        }

        return redirect()->route('waitlist.index')->with('waitlist_success',
            'Заявка сохранена. Если освободится место, предложение появится здесь и придёт на email.');
    }

    public function leave(WaitlistRequest $request, Event $event, TicketType $ticketType, WaitlistService $waitlist)
    {
        $waitlist->leave($event, $ticketType, $request->user());

        return redirect()->route('waitlist.index')->with('waitlist_success',
            'Заявка закрыта. Если билет уже оплачен, он сохранён в разделе «Мои билеты».');
    }

    public function accept(WaitlistRequest $request, Event $event, TicketType $ticketType, WaitlistService $waitlist, PaymentService $payments)
    {
        try {
            $order = $waitlist->accept($event, $ticketType, $request->user());
            $request->session()->put('checkout_order_id', $order->id);
            $payments->initialize($order->payment);
        } catch (HttpException $exception) {
            return $this->unavailable($exception);
        }

        return redirect()->route('checkout.order', $order);
    }

    private function unavailable(HttpException $exception)
    {
        if (!in_array($exception->getStatusCode(), [409, 503], true)) {
            throw $exception;
        }

        return redirect()->route('waitlist.index')->with('waitlist_error', $exception->getStatusCode() === 503
            ? 'Оплата временно недоступна. Попробуйте принять предложение позже, пока действует резерв.'
            : 'Условия изменились: билеты могли появиться в продаже, предложение истекло или мероприятие закрыто. Проверьте заявку и афишу.');
    }
}
