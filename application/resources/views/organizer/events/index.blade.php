@extends('layouts.site')
@section('title', 'Мои мероприятия — Events')
@section('content')
<div class="organizer-heading"><div class="account-heading"><p class="eyebrow">КАБИНЕТ ОРГАНИЗАТОРА</p><h1>Мои мероприятия<span class="brand-dot">.</span></h1><p class="muted">Ваши черновики, опубликованные, отменённые и завершённые мероприятия.</p></div><a class="primary-button" href="{{ route('organizer.events.create') }}">Создать мероприятие ↗</a></div>
<div class="purchase-list">
@forelse($events as $event)
    <article class="account-card purchase-card"><div><p class="eyebrow">@include('organizer.events.status') · {{ $event->category->name }}</p><h2>{{ $event->title }}</h2>
        <p class="muted">{{ $event->venue->name }} · <time data-date="{{ $event->starts_at->toIso8601String() }}">{{ $event->starts_at->format('d.m.Y H:i') }} UTC</time></p>
        <p>Типов билетов: {{ $event->ticket_types_count }}</p></div>
        <div class="purchase-side">@can('update', $event)<a class="page-button" href="{{ route('organizer.events.edit', $event) }}">Редактировать ↗</a>@endcan
            @can('update', $event)<a class="inline-link" href="{{ route('organizer.tickets.index', $event) }}">Типы билетов ↗</a>@endcan
            @if($event->status === \App\Enums\EventStatus::PUBLISHED)<a class="inline-link" href="{{ route('events.show', $event) }}">Открыть в афише ↗</a>@endif
        </div>
    </article>
@empty
    <section class="account-card"><h2>Мероприятий пока нет</h2><p class="muted">Создайте первый черновик: укажите название, описание, даты, категорию и площадку.</p></section>
@endforelse
</div>
@include('purchases.pagination', ['paginator' => $events])
@endsection
