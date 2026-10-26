<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title>Для вас освободился билет</title></head>
<body>
<h1>Освободился билет на «{{ $entry->event->title }}»</h1>
<p>За вами закреплён один билет «{{ $entry->ticketType->name }}» стоимостью {{ $entry->order->total_amount }} UZS.</p>
<p>Бронь действует до {{ $entry->order->expires_at->format('d.m.Y H:i:s') }} ({{ $entry->order->expires_at->timezoneName }}).</p>
<p>Заказ №{{ $entry->order->id }} доступен в вашем листе ожидания. Подтвердите покупку и оплатите его до окончания брони.</p>
<p>Если вы не успеете, место будет предложено следующему участнику очереди.</p>
</body>
</html>
