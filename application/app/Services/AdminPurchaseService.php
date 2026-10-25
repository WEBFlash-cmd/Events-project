<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class AdminPurchaseService
{
    public function orders(array $filters): LengthAwarePaginator
    {
        $query = Order::query()->with(['user:id,name,email', 'event.venue', 'items',
            'payment:id,order_id,status,amount,refund_required,refund_amount,paid_at']);
        $this->filter($query, $filters);

        if (array_key_exists('refund_required', $filters)) {
            $hasRefund = fn ($query) => $query->where('refund_required', true);
            if ((bool) $filters['refund_required']) {
                $query->whereHas('payment', $hasRefund);
            } else {
                $query->whereDoesntHave('payment', $hasRefund);
            }
        }

        return $this->paginate($query, $filters);
    }

    public function tickets(array $filters): LengthAwarePaginator
    {
        $query = Ticket::query()->with(['user:id,name,email', 'event.venue', 'ticketType', 'order:id,status']);
        $this->filter($query, $filters);
        if (array_key_exists('refund_required', $filters)) {
            $query->where('refund_required', (bool) $filters['refund_required']);
        }

        return $this->paginate($query, $filters);
    }

    private function filter(Builder $query, array $filters): void
    {
        foreach (['user_id', 'event_id', 'status'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where($field, $filters[$field]);
            }
        }
    }

    private function paginate(Builder $query, array $filters): LengthAwarePaginator
    {
        return $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15)->withQueryString();
    }
}
