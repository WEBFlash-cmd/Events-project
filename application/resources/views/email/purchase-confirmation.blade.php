<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Подтверждение покупки</title>
</head>
<body>
    <h1>Покупка билетов подтверждена</h1>
    <p>Вы успешно приобрели билеты на мероприятие «{{ $order->event->title }}».</p>
    <p>Заказ №{{ $order->id }}</p>
    <p>Дата: {{ $order->event->starts_at->format('d.m.Y H:i') }} ({{ $order->event->starts_at->timezoneName }})</p>
    <p>Место: {{ $order->event->venue->name }}, {{ $order->event->venue->address }}</p>
    <p>Оплачено: {{ $order->total_amount }} UZS</p>
    <h2>Ваши билеты</h2>
    <ul>
        @foreach ($order->tickets as $ticket)
            <li>{{ $ticket->ticketType->name }} — {{ $ticket->number }}</li>
        @endforeach
    </ul>
    <p>Сохраните номера билетов для посещения мероприятия.</p>
</body>
</html>
