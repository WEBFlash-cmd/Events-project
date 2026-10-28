@extends('layouts.site')
@section('title', 'Мой билет — Events')
@section('content')
<a class="inline-link back-link" href="{{ route('purchases.tickets') }}">← Мои билеты</a>
<div class="account-heading"><p class="eyebrow">{{ $ticket->ticketType->name }}</p><h1>{{ $ticket->event->title }}</h1></div>
@if(session('purchase_success'))<p class="verification-banner" role="status">{{ session('purchase_success') }}</p>@endif
@if(session('purchase_error'))<p class="verification-banner is-error" role="alert">{{ session('purchase_error') }}</p>@endif
<section class="account-card ticket-detail">
    <h2>@include('purchases.ticket-status')</h2>
    <p class="ticket-number">{{ $ticket->number }}</p>
    <p>Начало: <time data-date="{{ $ticket->event->starts_at->toIso8601String() }}">{{ $ticket->event->starts_at->format('d.m.Y H:i') }}</time></p>
    <p>{{ $ticket->event->venue->name }} · {{ $ticket->event->venue->address }}</p>
    @if($ticket->checked_in_at)<p>Вход зарегистрирован: {{ $ticket->checked_in_at->format('d.m.Y H:i') }}</p>@endif
    @if($ticket->refund_required)<p class="verification-banner">По билету требуется возврат. Отметка не означает, что деньги уже возвращены. По срокам обратитесь к организатору.</p>@endif
    <a class="inline-link" href="{{ route('checkout.order', $ticket->order_id) }}">Заказ №{{ $ticket->order_id }} ↗</a>
    @if($ticket->status === 'active' && !$ticket->cancelled_at && !$ticket->checked_in_at && !$ticket->refund_required && $ticket->order->status === 'paid' && in_array($ticket->order->payment?->status, ['paid', 'refund_required'], true) && $ticket->event->status === \App\Enums\EventStatus::PUBLISHED && $ticket->event->allows_ticket_cancellation && $ticket->event->starts_at->isFuture())
        <details class="ticket-cancellation"><summary>Отменить билет</summary><p>После отмены билет станет недействительным, а место вернётся в продажу. Если требуется возврат, его сумму зафиксируем в заказе.</p>
            <form method="post" action="{{ route('purchases.ticket.cancel', $ticket) }}">@csrf<button class="page-button" type="submit">Подтверждаю отмену билета</button></form>
        </details>
    @else<p class="field-hint">Отмена этого билета недоступна.</p>@endif
</section>
@endsection
