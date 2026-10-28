@extends('layouts.site')
@section('title', 'Все билеты — Events')
@section('content')
@include('admin.nav')
<div class="account-heading"><p class="eyebrow">КАБИНЕТ АДМИНИСТРАТОРА</p><h1>Все билеты<span class="brand-dot">.</span></h1><p class="muted">Выданные билеты, история использования и отмен.</p></div>
@include('admin.feedback')
@include('admin.purchases.filters', ['isOrders' => false])
<p class="muted">Найдено: {{ $tickets->total() }}</p>
<div class="purchase-list">
@forelse($tickets as $ticket)
    <article class="account-card"><p class="eyebrow">БИЛЕТ №{{ $ticket->id }} · ЗАКАЗ №{{ $ticket->order_id }}</p><h2 class="report-email">{{ $ticket->event->title }}</h2>
        <p class="ticket-number">{{ $ticket->number }}</p><p>Билет: @include('purchases.ticket-status') · {{ $ticket->ticketType->name }}</p>
        <p class="muted">Покупатель №{{ $ticket->user_id }}: {{ $ticket->user->name ?: 'Имя не указано' }} · {{ $ticket->user->email }}</p>
        <p class="muted">Мероприятие №{{ $ticket->event_id }} · {{ $ticket->event->venue->name }} · <time data-date="{{ $ticket->event->starts_at->toIso8601String() }}">{{ $ticket->event->starts_at->format('d.m.Y H:i') }} UTC</time></p>
        <p class="muted">Создан: {{ $ticket->created_at->format('d.m.Y H:i') }} UTC · Заказ: @include('purchases.order-status', ['order' => $ticket->order])</p>
        @if($ticket->checked_in_at)<p class="muted">Вход зарегистрирован: {{ $ticket->checked_in_at->format('d.m.Y H:i') }} UTC</p>@endif
        @if($ticket->cancelled_at)<p class="muted">Отменён: {{ $ticket->cancelled_at->format('d.m.Y H:i') }} UTC</p>@endif
        @if($ticket->refund_required)<p class="verification-banner">По билету требуется возврат. Отметка не подтверждает перечисление денег покупателю.</p>@endif
        <div class="order-actions">
            @can('viewAny', \App\Models\Order::class)<a class="page-button" href="{{ route('admin.purchases.orders', ['order_id' => $ticket->order_id]) }}">Заказ ↗</a>@endcan
            @can('viewAny', \App\Models\Event::class)<a class="inline-link" href="{{ route('admin.events.show', $ticket->event_id) }}">Мероприятие ↗</a>@endcan
            @can('users.view')<a class="inline-link" href="{{ route('admin.users.show', $ticket->user_id) }}">Покупатель ↗</a>@endcan
        </div>
    </article>
@empty<section class="account-card"><h2>Билеты не найдены</h2><p class="muted">Измените поисковый запрос или сбросьте фильтры. Неоплаченный заказ может ещё не иметь билетов.</p></section>@endforelse
</div>
@include('purchases.pagination', ['paginator' => $tickets])
@endsection
