@if($ticket->refund_required)Недействителен · требуется возврат
@elseif($ticket->checked_in_at || $ticket->status === 'used')Вход зарегистрирован
@elseif($ticket->status === 'cancelled')Билет отменён
@elseif($ticket->status === 'active')Активный билет
@else Билет недействителен
@endif
