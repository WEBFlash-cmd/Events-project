@extends('layouts.site')
@section('title', 'Заказ №'.$order->id.' — Events')
@section('content')
<a class="inline-link back-link" href="{{ route('purchases.orders') }}">← Мои заказы</a>
<div data-order-status data-status-url="{{ url('/orders/'.$order->id.'/status') }}" data-cancel-url="{{ url('/orders/'.$order->id.'/cancel') }}">
    <div class="account-heading"><p class="eyebrow">ЗАКАЗ №{{ $order->id }}</p><h1>{{ $order->event->title }}</h1><p class="muted">{{ $order->event->venue->name }} · <time data-date="{{ $order->event->starts_at->toIso8601String() }}">{{ $order->event->starts_at->format('d.m.Y H:i') }}</time></p></div>
    <div class="checkout-layout order-layout">
        <section class="account-card payment-card" aria-labelledby="payment-title">
            <div class="payment-symbol" aria-hidden="true">◎</div>
            <div id="payment-state" role="status"><h2 id="payment-title">Проверяем состояние заказа…</h2><p id="payment-description" class="muted">Получаем подтверждённые данные с сервера.</p></div>
            <p id="reservation-time" class="verification-banner" hidden></p>
            <a id="payment-link" class="primary-button checkout-login" hidden rel="noreferrer">Перейти к оплате ↗</a>
            <p class="field-hint">После оплаты вернитесь на эту страницу. Переход из платёжки сам по себе не подтверждает покупку — ждём подтверждения сервера.</p>
            <div class="order-actions"><button id="refresh-order" class="page-button" type="button">Проверить статус</button><button id="cancel-order" class="text-button" type="button" hidden>Отменить заказ</button></div>
            <p id="order-feedback" class="form-feedback" role="status" hidden></p>
            <div id="order-tickets" class="order-tickets" hidden><h3>Ваши билеты</h3><ul></ul></div>
        </section>
        <aside class="account-card order-summary"><p class="eyebrow">ВАШ ВЫБОР</p><h2>Состав заказа</h2>
            @foreach($order->items as $item)<div class="summary-item"><span>{{ $item->ticketType->name }}<small>{{ $item->quantity }} × {{ number_format((float) $item->unit_price, 2, ',', ' ') }} UZS</small></span><strong>{{ number_format((float) $item->unit_price * $item->quantity, 2, ',', ' ') }} UZS</strong></div>@endforeach
            <div class="checkout-total"><span>Итого</span><strong>{{ number_format((float) $order->total_amount, 2, ',', ' ') }} UZS</strong></div>
            <p class="field-hint">Сумма зафиксирована при создании заказа.</p>
        </aside>
    </div>
</div>
@endsection
