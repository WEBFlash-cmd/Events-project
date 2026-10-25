<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Ticket;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EventReportService
{
    public function participants(Event $event, array $filters): LengthAwarePaginator
    {
        $query = $event->tickets()->whereHas('order', fn ($query) => $query
            ->where('event_id', $event->id)->where('status', 'paid'))
            ->select(['id', 'number', 'user_id', 'ticket_type_id', 'order_id', 'status',
                'refund_required', 'checked_in_at', 'created_at'])
            ->with(['user:id,email', 'ticketType:id,name']);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15)->withQueryString();
    }

    public function sales(Event $event): array
    {
        // Shared event lock keeps the summary coherent with payment, cancellation and check-in.
        return DB::transaction(function () use ($event) {
            $event = Event::query()->sharedLock()->findOrFail($event->id);
            $orders = Order::query()->where('event_id', $event->id);
            $orderCounts = (clone $orders)->selectRaw('status, COUNT(*) AS total')
                ->groupBy('status')->pluck('total', 'status');
            $paidOrders = (clone $orders)->where('status', 'paid')->select('id');

            $tickets = Ticket::query()->where('event_id', $event->id)->whereIn('order_id', $paidOrders);
            $ticketCounts = (clone $tickets)->selectRaw('ticket_type_id, COUNT(*) AS sold,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN checked_in_at IS NOT NULL THEN 1 ELSE 0 END) AS checked_in,
                SUM(CASE WHEN refund_required THEN 1 ELSE 0 END) AS refund_count', ['active'])
                ->groupBy('ticket_type_id')->get()->keyBy('ticket_type_id');

            $types = $event->ticketTypes()->orderBy('id')->get()->map(function ($type) use ($ticketCounts) {
                $counts = $ticketCounts->get($type->id);
                return [
                    'id' => $type->id, 'name' => $type->name, 'price' => $type->price,
                    'available' => $type->quantity,
                    'sold' => (int) ($counts?->sold ?? 0),
                    'active' => (int) ($counts?->active ?? 0),
                    'checked_in' => (int) ($counts?->checked_in ?? 0),
                    'refund_required' => (int) ($counts?->refund_count ?? 0),
                ];
            });

            // Sum each payment once, including late payments with no issued tickets.
            $money = Payment::query()->whereIn('order_id', (clone $orders)->select('id'))
                ->whereIn('status', ['paid', 'refund_required'])
                ->selectRaw('COALESCE(SUM(amount), 0) AS gross,
                    COALESCE(SUM(refund_amount), 0) AS refunds')->first();
            $gross = (int) $money->gross;
            $refunds = (int) $money->refunds;

            return [
                'event_id' => $event->id, 'event_status' => $event->status->value,
                'orders' => collect(['pending', 'paid', 'cancelled', 'expired'])
                    ->mapWithKeys(fn ($status) => [$status => (int) ($orderCounts[$status] ?? 0)])->all(),
                'tickets' => [
                    'sold' => $types->sum('sold'), 'active' => $types->sum('active'),
                    'checked_in' => $types->sum('checked_in'),
                    'refund_required' => $types->sum('refund_required'),
                    'available' => $types->sum('available'),
                ],
                'buyers' => (clone $tickets)->distinct()->count('user_id'),
                'money' => [
                    'currency' => 'UZS',
                    'gross_received' => $this->formatMoney($gross),
                    'refund_required' => $this->formatMoney($refunds),
                    'excluding_required_refunds' => $this->formatMoney($gross - $refunds),
                ],
                'ticket_types' => $types->all(),
            ];
        });
    }

    private function formatMoney(int $amount): string
    {
        return sprintf('%d.%02d', intdiv($amount, 100), $amount % 100);
    }
}
