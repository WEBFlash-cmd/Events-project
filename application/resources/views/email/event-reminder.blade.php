<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"><title>Напоминание о мероприятии</title></head>
<body>
<h1>Скоро начнётся «{{ $order->event->title }}»</h1>
<p>Начало: {{ $order->event->starts_at->format('d.m.Y H:i') }} ({{ $order->event->starts_at->timezoneName }}).</p>
<p>Место: {{ $order->event->venue->name }}, {{ $order->event->venue->address }}.</p>
<p>Заказ №{{ $order->id }}. Покажите билет при входе.</p>
<ul>
@foreach ($order->tickets as $ticket)
<li>{{ $ticket->ticketType->name }} — {{ $ticket->number }}</li>
@endforeach
</ul>
</body>
</html>
