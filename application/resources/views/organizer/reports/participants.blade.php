@extends('layouts.site')
@section('title', 'Участники мероприятия — Events')
@section('content')
@include('organizer.reports.nav')
<h2>Участники</h2><p class="muted">Каждая строка — один билет из оплаченного заказа. Один покупатель может иметь несколько билетов.</p>
<nav class="report-nav" aria-label="Фильтр билетов участников">
    @foreach(['' => 'Все билеты', 'active' => 'Активные', 'used' => 'Вошедшие', 'cancelled' => 'Отменённые'] as $status => $label)<a class="page-button" href="{{ route('organizer.reports.participants', ['event' => $event] + ($status === '' ? [] : ['status' => $status])) }}" @if(request('status', '') === $status) aria-current="page" @endif>{{ $label }}</a>@endforeach
</nav>
<p class="muted">Билетов в списке: {{ $participants->total() }}</p>
<div class="purchase-list">
@forelse($participants as $ticket)
    <article class="account-card purchase-card"><div><p class="eyebrow">{{ $ticket->ticketType->name }}</p><h3 class="report-email">{{ $ticket->user->email }}</h3><p>@include('organizer.reports.ticket-status')</p><p class="ticket-number">{{ $ticket->number }}</p>
        @if($ticket->checked_in_at)<p>Вход: <time data-date="{{ $ticket->checked_in_at->toIso8601String() }}">{{ $ticket->checked_in_at->format('d.m.Y H:i') }} UTC</time></p>@endif
    </div><a class="page-button" href="{{ route('organizer.reports.check-in', ['event' => $event, 'number' => $ticket->number]) }}">Проверить билет ↗</a></article>
@empty
    <section class="account-card"><h3>Билеты не найдены</h3><p class="muted">В выбранном списке пока нет билетов из оплаченных заказов.</p></section>
@endforelse
</div>
@include('purchases.pagination', ['paginator' => $participants])
@endsection
