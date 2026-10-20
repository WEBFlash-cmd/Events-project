<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $order->user_id === $user->id || $user->hasPermission('orders.view-any');
    }

    public function cancel(User $user, Order $order): bool
    {
        return $order->user_id === $user->id || $user->hasPermission('orders.cancel-any');
    }
}
