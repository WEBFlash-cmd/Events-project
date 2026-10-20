<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderReservationService;
use Illuminate\Console\Command;

class ExpireOrders extends Command
{
    protected $signature = 'orders:expire';

    protected $description = 'Expire unpaid orders and release their reserved tickets';

    public function handle(OrderReservationService $reservations): int
    {
        $expired = 0;
        Order::query()->where('status', 'pending')->where('expires_at', '<=', now())
            ->chunkById(200, function ($orders) use ($reservations, &$expired) {
                foreach ($orders as $order) {
                    if ($reservations->expire($order)) {
                        $expired++;
                    }
                }
            });

        $this->info("Expired orders: {$expired}");

        return self::SUCCESS;
    }
}
