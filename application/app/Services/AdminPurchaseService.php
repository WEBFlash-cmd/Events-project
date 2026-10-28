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
        if (isset($filters['order_id'])) {
            $query->whereKey($filters['order_id']);
        }

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
        $query = Ticket::query()->with(['user:id,name,email', 'event.venue', 'ticketType', 'order:id,status,expires_at']);
        $this->filter($query, $filters);
        if (isset($filters['order_id'])) {
            $query->where('order_id', $filters['order_id']);
        }
        if (isset($filters['number'])) {
            $query->where('number', $filters['number']);
        }
        if (array_key_exists('refund_required', $filters)) {
            $query->where('refund_required', (bool) $filters['refund_required']);
        }

        return $this->paginate($query, $filters);
    }

    private function filter(Builder $query, array $filters): void
    {
        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = '%'.$filters['search'].'%';
            $query->where(fn ($query) => $query
                ->whereHas('user', fn ($user) => $user->where(fn ($user) =>
                    $user->whereLike('name', $search)->orWhereLike('email', $search)))
                ->orWhereHas('event', fn ($event) => $event->whereLike('title', $search)));
        }
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
