@if($order->status === 'paid')Оплачен
@elseif($order->status === 'cancelled')Отменён
@elseif($order->status === 'expired' || ($order->status === 'pending' && $order->expires_at?->isPast()))Срок резерва истёк
@else Ожидает оплаты
@endif
