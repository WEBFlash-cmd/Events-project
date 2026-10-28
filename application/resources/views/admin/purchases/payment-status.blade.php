@switch($paymentStatus)
    @case('creating') Создаётся @break
    @case('created') Создана @break
    @case('pending') Ожидается подтверждение @break
    @case('paid') Подтверждена @break
    @case('failed') Ошибка оплаты @break
    @case('creation_failed') Не удалось создать платёж @break
    @case('unknown') Результат создания уточняется @break
    @case('cancelled') Отменена @break
    @case('refund_required') Требуется возврат @break
    @default {{ $paymentStatus }}
@endswitch
