@extends('layouts.site')
@section('title', $event->title.' — Events')
@section('content')
<a class="inline-link back-link" href="{{ route('catalog') }}#catalog">← Все мероприятия</a>
<section class="checkout-layout">
    <div class="event-details">
        <p class="eyebrow">{{ $event->category->name }}</p>
        <h1>{{ $event->title }}</h1>
        <div class="event-facts"><p>Когда<br><strong><time data-date="{{ $event->starts_at->toIso8601String() }}">{{ $event->starts_at->format('d.m.Y H:i') }}</time></strong></p><p>Где<br><strong>{{ $event->venue->name }}</strong><br>{{ $event->venue->address }}</p></div>
        <div class="event-description">{{ $event->description ?: 'Организатор пока не добавил описание.' }}</div>
        <div class="checkout-note"><span aria-hidden="true">↗</span><p>Покупка в несколько шагов: выберите билеты, создайте заказ и перейдите к оплате. Время резерва указано на странице заказа.</p></div>
    </div>
    <aside class="account-card booking-card" aria-labelledby="booking-title">
        <p class="eyebrow">ВАШ СЛЕДУЮЩИЙ ВЫХОД</p><h2 id="booking-title">Выберите билеты</h2>
        @if(!$event->starts_at->isFuture())<p class="verification-banner">Продажа закрыта: мероприятие уже началось.</p>@endif
        <form action="{{ url('/events/'.$event->id.'/checkout') }}" method="post" @auth('web') data-checkout @endauth>
            @csrf
            <input type="hidden" name="checkout_reference" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <div class="ticket-options">
                @forelse($event->ticketTypes->sortBy('id') as $type)
                    <div class="ticket-option">
                        <div><h3>{{ $type->name }}</h3><p class="muted">{{ $type->description }}</p><strong>{{ number_format((float) $type->price, 2, ',', ' ') }} UZS</strong><p class="field-hint">{{ $type->quantity > 0 ? 'Доступно: '.$type->quantity : 'Билеты закончились' }}@if((float) $type->price <= 0) · Бесплатное оформление пока недоступно@endif</p></div>
                        <label class="quantity-field"><span class="sr-only">Количество: {{ $type->name }}</span><input type="number" value="0" min="0" max="{{ max(0, $type->quantity) }}" step="1" data-ticket-id="{{ $type->id }}" data-price="{{ $type->price }}" @disabled(!auth('web')->check() || !$event->starts_at->isFuture() || $type->quantity < 1 || (float) $type->price <= 0)></label>
                    </div>
                @empty
                    <p class="muted">Организатор ещё не открыл продажу билетов.</p>
                @endforelse
            </div>
            @auth('web')
                <div class="checkout-total"><span>Итого</span><strong id="checkout-total" role="status">0,00 UZS</strong></div>
                <p class="field-hint">Цена и наличие будут повторно проверены при создании заказа.</p>
                <p class="form-feedback" role="status" tabindex="-1" hidden></p>
                <button class="primary-button" type="submit" disabled>Создать заказ ↗</button>
            @else
                <p class="muted">Для покупки войдите в аккаунт. После входа вернём вас к этому мероприятию.</p><a class="primary-button checkout-login" href="{{ route('login') }}">Войти и выбрать билеты ↗</a>
            @endauth
        </form>
    </aside>
</section>
@endsection
