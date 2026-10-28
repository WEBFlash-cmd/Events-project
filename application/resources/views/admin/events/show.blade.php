@extends('layouts.site')
@section('title', 'Просмотр мероприятия — Events')
@section('content')
@include('admin.nav')
<a class="inline-link back-link" href="{{ route('admin.events.index') }}">← Все мероприятия</a>
<div class="account-heading"><p class="eyebrow">МЕРОПРИЯТИЕ №{{ $event->id }} · @include('organizer.events.status')</p><h1 class="report-email">{{ $event->title }}</h1></div>
@include('admin.feedback')
<section class="account-card">
    <h2>Информация о мероприятии</h2>
    <p class="muted">Категория: {{ $event->category->name }}</p>
    <p class="muted">Площадка: {{ $event->venue->name }} · {{ $event->venue->address }}</p>
    <p class="muted">Вместимость площадки: {{ number_format($event->venue->capacity, 0, '.', ' ') }}</p>
    <p class="muted">Начало: <time data-date="{{ $event->starts_at->toIso8601String() }}">{{ $event->starts_at->format('d.m.Y H:i') }} UTC</time></p>
    <p class="muted">Окончание: <time data-date="{{ $event->ends_at->toIso8601String() }}">{{ $event->ends_at->format('d.m.Y H:i') }} UTC</time></p>
    <p class="muted">Организатор №{{ $event->organizer_id }}: {{ $event->organizer->name ?: 'Имя не указано' }} · {{ $event->organizer->email }}</p>
    @can('users.view')<a class="inline-link" href="{{ route('admin.users.show', $event->organizer_id) }}">Аккаунт организатора ↗</a>@endcan
    <p class="muted">{{ $event->allows_ticket_cancellation ? 'Отмена билетов участниками разрешена до начала мероприятия.' : 'Отмена билетов участниками не предусмотрена.' }}</p>
    <div class="event-description">{{ $event->description }}</div>
    @if($event->status === \App\Enums\EventStatus::PUBLISHED)<div class="order-actions"><a class="page-button" href="{{ route('events.show', $event) }}">Открыть в афише ↗</a></div>@endif
</section>
<section class="account-card report-section"><h2>Типы билетов</h2><p class="muted">Остаток — количество доступных билетов. Выдано билетов всего: {{ $event->tickets_count }} (включая использованные и отменённые).</p>
    @forelse($event->ticketTypes as $type)
        <div class="summary-item"><span>{{ $type->name }}<small>{{ $type->description }}</small></span><span>{{ $type->price }} UZS · Остаток: {{ $type->quantity }}</span></div>
    @empty<p class="muted">Типы билетов ещё не добавлены.</p>@endforelse
</section>
@can('delete', $event)
    <section class="account-card report-section"><h2>Удаление мероприятия</h2>
        @if($hasOrders || $event->tickets_count > 0)
            <p class="muted">Удаление недоступно: у мероприятия есть заказы или билеты. Даже отменённые и просроченные заказы должны сохраниться в истории.</p>
        @else
            <p class="muted">Удаление необратимо. Вместе с мероприятием будут удалены его типы билетов. Площадка, категория и аккаунт организатора сохранятся.</p>
            <form method="post" action="{{ route('admin.events.destroy', $event) }}">@csrf @method('DELETE')
                <label class="organizer-checkbox"><input type="checkbox" name="confirm_delete" value="1" required> Подтверждаю удаление мероприятия «{{ $event->title }}»</label>
                <button class="primary-button" type="submit">Удалить мероприятие</button>
            </form>
        @endif
    </section>
@endcan
@endsection
