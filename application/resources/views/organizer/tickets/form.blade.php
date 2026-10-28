@extends('layouts.site')
@section('title', ($ticketType->exists ? 'Редактирование билета' : 'Новый тип билета').' — Events')
@section('content')
<a class="inline-link back-link" href="{{ route('organizer.tickets.index', $event) }}">← Типы билетов</a>
<div class="account-heading"><p class="eyebrow">{{ $event->title }}</p><h1>{{ $ticketType->exists ? 'Редактирование билета' : 'Новый тип билета' }}</h1><p class="muted">Площадка: {{ $event->venue->name }} · {{ $event->venue->capacity }} мест.</p></div>
@if(session('ticket_success'))<p class="verification-banner" role="status">{{ session('ticket_success') }}</p>@endif
@if($errors->any())<div class="verification-banner is-error" role="alert"><strong>Не удалось сохранить билет.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<section class="account-card organizer-form"><h2>Название и цена</h2>
    <form method="post" action="{{ $ticketType->exists ? route('organizer.tickets.update', [$event, $ticketType]) : route('organizer.tickets.store', $event) }}">@csrf
        @if($ticketType->exists)@method('PATCH')@endif
        <label class="account-field">Название<input name="name" maxlength="255" required value="{{ old('name', $ticketType->name) }}"></label>
        <label class="account-field">Описание<textarea name="description" rows="4">{{ old('description', $ticketType->description) }}</textarea></label>
        <label class="account-field">Цена в UZS<input name="price" type="number" min="0" max="9999999999.99" step="0.01" required value="{{ old('price', $ticketType->price) }}"></label>
        <p class="field-hint">Новая цена применяется к будущим заказам. Для покупки через сайт укажите цену больше нуля.</p>
        @unless($ticketType->exists)<label class="account-field">Свободные места<input name="quantity" type="number" min="0" max="2147483647" step="1" required value="{{ old('quantity', 0) }}"></label>@endunless
        <button class="primary-button" type="submit">{{ $ticketType->exists ? 'Сохранить данные билета' : 'Создать тип билета' }} ↗</button>
    </form>
</section>
@if($ticketType->exists)
    <section class="account-card organizer-form ticket-stock"><h2>Свободные места</h2><p>Текущий остаток: <strong>{{ $ticketType->quantity }}</strong></p><p class="muted">Укажите новый остаток, а не количество добавляемых билетов. Проданные и зарезервированные места система учитывает при проверке вместимости. Новые места могут сразу перейти в лист ожидания.</p>
        <form method="post" action="{{ route('organizer.tickets.quantity', [$event, $ticketType]) }}">@csrf @method('PATCH')
            <input type="hidden" name="expected_quantity" value="{{ $ticketType->quantity }}">
            <label class="account-field">Новый остаток<input name="quantity" type="number" min="0" max="2147483647" step="1" required value="{{ old('quantity', $ticketType->quantity) }}"></label>
            <button class="primary-button" type="submit">Сохранить остаток ↗</button>
        </form>
    </section>
@endif
@endsection
