@extends('layouts.site')
@section('title', 'Все заказы — Events')
@section('content')
@include('admin.nav')
<div class="account-heading"><p class="eyebrow">КАБИНЕТ АДМИНИСТРАТОРА</p><h1>Все заказы<span class="brand-dot">.</span></h1><p class="muted">Покупки всех участников. Состояние заказа и оплаты показаны отдельно.</p></div>
@include('admin.feedback')
@include('admin.purchases.filters', ['isOrders' => true])
<p class="muted">Найдено: {{ $orders->total() }}</p>
<div class="purchase-list">
@forelse($orders as $order)
    <article class="account-card"><p class="eyebrow">ЗАКАЗ №{{ $order->id }} · {{ $order->created_at->format('d.m.Y H:i') }} UTC</p><h2 class="report-email">{{ $order->event->title }}</h2>
        <p class="muted">Покупатель №{{ $order->user_id }}: {{ $order->user->name ?: 'Имя не указано' }} · {{ $order->user->email }}</p>
        <p class="muted">Мероприятие №{{ $order->event_id }} · {{ $order->event->venue->name }} · <time data-date="{{ $order->event->starts_at->toIso8601String() }}">{{ $order->event->starts_at->format('d.m.Y H:i') }} UTC</time></p>
        <p>Заказ: @include('purchases.order-status') · Сумма: {{ $order->total_amount }} UZS</p>
        @if($order->payment)
            <p>Оплата: @include('admin.purchases.payment-status', ['paymentStatus' => $order->payment->status]) · Сумма платежа: {{ number_format($order->payment->amount / 100, 2, ',', ' ') }} UZS</p>
            @if($order->payment->paid_at)<p class="muted">Оплачен: <time data-date="{{ $order->payment->paid_at->toIso8601String() }}">{{ $order->payment->paid_at->format('d.m.Y H:i') }} UTC</time></p>@endif
            @if($order->payment->refund_required)<p class="verification-banner">Требуется возврат: {{ number_format($order->payment->refund_amount / 100, 2, ',', ' ') }} UZS. Отметка о необходимости возврата не подтверждает перечисление денег покупателю.</p>@endif
        @else<p class="muted">Платёж ещё не создан.</p>@endif
        <details class="ticket-cancellation"><summary>Состав заказа</summary>@foreach($order->items as $item)<p class="muted">Тип билета №{{ $item->ticket_type_id }} · {{ $item->quantity }} шт. × {{ $item->unit_price }} UZS</p>@endforeach</details>
        <div class="order-actions">
            @can('viewAny', \App\Models\Event::class)<a class="inline-link" href="{{ route('admin.events.show', $order->event_id) }}">Мероприятие ↗</a>@endcan
            @can('users.view')<a class="inline-link" href="{{ route('admin.users.show', $order->user_id) }}">Покупатель ↗</a>@endcan
            @can('viewAny', \App\Models\Ticket::class)<a class="page-button" href="{{ route('admin.purchases.tickets', ['order_id' => $order->id]) }}">Билеты заказа ↗</a>@endcan
        </div>
    </article>
@empty<section class="account-card"><h2>Заказы не найдены</h2><p class="muted">Измените поисковый запрос или сбросьте фильтры.</p></section>@endforelse
</div>
@include('purchases.pagination', ['paginator' => $orders])
@endsection
