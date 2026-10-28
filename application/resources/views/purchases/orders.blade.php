@extends('layouts.site')
@section('title', 'Мои заказы — Events')
@section('content')
<div class="account-heading"><p class="eyebrow">ВАШИ ПОКУПКИ</p><h1>Мои заказы<span class="brand-dot">.</span></h1><p class="muted">Все заказы, включая ожидающие оплаты и отменённые.</p></div>
<div class="purchase-list">
@forelse($orders as $order)
    <article class="account-card purchase-card">
        <div><p class="eyebrow">ЗАКАЗ №{{ $order->id }} · {{ $order->created_at->format('d.m.Y H:i') }}</p><h2>{{ $order->event->title }}</h2>
            <p class="muted">{{ $order->event->venue->name }} · <time data-date="{{ $order->event->starts_at->toIso8601String() }}">{{ $order->event->starts_at->format('d.m.Y H:i') }}</time></p>
            <p>@include('purchases.order-status') · Билетов: {{ $order->tickets_count }}</p>
            @if($order->payment?->refund_required)<p class="verification-banner">Требуется возврат: {{ number_format($order->payment->refund_amount / 100, 2, ',', ' ') }} UZS. Обратитесь к организатору.</p>@endif
        </div>
        <div class="purchase-side"><strong>{{ number_format((float) $order->total_amount, 2, ',', ' ') }} UZS</strong><a class="page-button" href="{{ route('checkout.order', $order) }}">Открыть заказ ↗</a></div>
    </article>
@empty
    <section class="account-card"><h2>Заказов пока нет</h2><p class="muted">Выберите мероприятие и оформите первую покупку.</p><a class="inline-link" href="{{ route('catalog') }}#catalog">Перейти в афишу ↗</a></section>
@endforelse
</div>
@include('purchases.pagination', ['paginator' => $orders])
@endsection
