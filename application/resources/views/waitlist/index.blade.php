@extends('layouts.site')
@section('title', 'Лист ожидания — Events')
@section('content')
<div class="account-heading"><p class="eyebrow">ВАШ ШАНС ПОПАСТЬ НА МЕРОПРИЯТИЕ</p><h1>Лист ожидания<span class="brand-dot">.</span></h1><p class="muted">Одна заявка — один билет. Предложения действуют до указанного срока. Обновите страницу, чтобы проверить новые места.</p></div>
@if(session('waitlist_success'))<p class="verification-banner" role="status">{{ session('waitlist_success') }}</p>@endif
@if(session('waitlist_error'))<p class="verification-banner is-error" role="alert">{{ session('waitlist_error') }}</p>@endif
@if($errors->any())<p class="verification-banner is-error" role="alert">Не удалось отправить форму. Обновите страницу и повторите попытку.</p>@endif
<div class="purchase-list">
@forelse($entries as $entry)
    @php
        $open = $entry->event->status === \App\Enums\EventStatus::PUBLISHED && $entry->event->starts_at->isFuture();
        $paid = $entry->order?->status === 'paid';
        $offer = $entry->status === 'offered' && $open && $entry->order?->status === 'pending' && $entry->order->expires_at?->isFuture();
        $expired = $entry->status === 'expired' || ($entry->status === 'offered' && ($entry->order?->status === 'expired' || $entry->order?->expires_at?->isPast()));
        $waiting = $entry->status === 'waiting' && $open;
    @endphp
    <article class="account-card purchase-card">
        <div><p class="eyebrow">{{ $entry->ticketType->name }} · ЗАЯВКА ОТ {{ $entry->queued_at->format('d.m.Y H:i') }}</p><h2>{{ $entry->event->title }}</h2>
            <p class="muted">{{ $entry->event->venue->name }} · <time data-date="{{ $entry->event->starts_at->toIso8601String() }}">{{ $entry->event->starts_at->format('d.m.Y H:i') }}</time></p>
            @if($paid)<p>Билет куплен</p>
            @elseif($offer)
                <p class="verification-banner">Для вас зарезервирован один билет</p>
                <p>Цена предложения: <strong>{{ number_format((float) $entry->order->total_amount, 2, ',', ' ') }} UZS</strong></p>
                <p>Оплатите до <time data-date="{{ $entry->order->expires_at->toIso8601String() }}">{{ $entry->order->expires_at->format('d.m.Y H:i') }}</time>. Принятие предложения не продлевает резерв.</p>
            @elseif($waiting)<p>Ожидаем свободное место. Сообщим о предложении на email.</p>
            @elseif($expired)<p>Срок предложения истёк. Чтобы ждать снова, запишитесь со страницы мероприятия.</p>
            @else<p>Заявка закрыта. Предложение больше недоступно.</p>@endif
            @if($entry->event->status === \App\Enums\EventStatus::PUBLISHED)<a class="inline-link" href="{{ route('events.show', $entry->event_id) }}">Страница мероприятия ↗</a>@endif
        </div>
        <div class="purchase-side">
            @if($offer)<form method="post" action="{{ route('waitlist.accept', [$entry->event_id, $entry->ticket_type_id]) }}">@csrf<button class="primary-button" type="submit">Принять и перейти к оплате ↗</button></form>@endif
            @if($paid)<a class="page-button" href="{{ route('purchases.tickets') }}">Мои билеты ↗</a>@endif
            @if($entry->order_id)<a class="inline-link" href="{{ route('checkout.order', $entry->order_id) }}">Заказ №{{ $entry->order_id }} ↗</a>@endif
            @if(!$paid && in_array($entry->status, ['waiting', 'offered'], true))
                <details class="waitlist-leave"><summary>Выйти из очереди</summary><p>Резерв неоплаченного билета будет снят.</p><form method="post" action="{{ route('waitlist.leave', [$entry->event_id, $entry->ticket_type_id]) }}">@csrf<button class="page-button" type="submit">Подтверждаю выход</button></form></details>
            @endif
        </div>
    </article>
@empty
    <section class="account-card"><h2>Вы пока не в очереди</h2><p class="muted">Если нужные билеты закончились, запись появится на странице мероприятия.</p><a class="inline-link" href="{{ route('catalog') }}#catalog">Перейти в афишу ↗</a></section>
@endforelse
</div>
@include('purchases.pagination', ['paginator' => $entries])
@endsection
