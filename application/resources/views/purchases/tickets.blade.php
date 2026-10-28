@extends('layouts.site')
@section('title', 'Мои билеты — Events')
@section('content')
<div class="account-heading"><p class="eyebrow">ВАШИ МЕРОПРИЯТИЯ</p><h1>Мои билеты<span class="brand-dot">.</span></h1><p class="muted">Билеты появляются после подтверждения оплаты. Сохраняем здесь и историю отмен.</p></div>
<div class="purchase-list">
@forelse($tickets as $ticket)
    <article class="account-card purchase-card"><div><p class="eyebrow">{{ $ticket->ticketType->name }}</p><h2>{{ $ticket->event->title }}</h2>
        <p class="muted">{{ $ticket->event->venue->name }} · <time data-date="{{ $ticket->event->starts_at->toIso8601String() }}">{{ $ticket->event->starts_at->format('d.m.Y H:i') }}</time></p>
        <p>@include('purchases.ticket-status')</p><p class="ticket-number">{{ $ticket->number }}</p></div>
        <a class="page-button" href="{{ route('purchases.ticket', $ticket) }}">Открыть билет ↗</a>
    </article>
@empty
    <section class="account-card"><h2>Билетов пока нет</h2><p class="muted">Если вы уже оформили заказ, проверьте его оплату.</p><a class="inline-link" href="{{ route('purchases.orders') }}">Мои заказы ↗</a></section>
@endforelse
</div>
@include('purchases.pagination', ['paginator' => $tickets])
@endsection
