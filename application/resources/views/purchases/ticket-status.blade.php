@if($ticket->cancelled_at || $ticket->status === 'cancelled')Билет отменён
@elseif($ticket->refund_required)Билет недействителен · требуется возврат
@elseif($ticket->checked_in_at || $ticket->status === 'used')Вход зарегистрирован
@elseif($ticket->event->status === \App\Enums\EventStatus::CANCELLED)Мероприятие отменено
@elseif($ticket->status !== 'active' || $ticket->order->status !== 'paid')Билет недействителен
@elseif($ticket->event->ends_at->isPast())Мероприятие завершено
@else Действующий билет
@endif
