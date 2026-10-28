@extends('layouts.site')
@section('title', 'Все мероприятия — Events')
@section('content')
@include('admin.nav')
<div class="account-heading"><p class="eyebrow">КАБИНЕТ АДМИНИСТРАТОРА</p><h1>Все мероприятия<span class="brand-dot">.</span></h1><p class="muted">Черновики, опубликованные, отменённые и завершённые мероприятия всех организаторов.</p></div>
@include('admin.feedback')
<section class="account-card admin-filters"><form method="get" action="{{ route('admin.events.index') }}">
    <label class="account-field">Название мероприятия<input type="search" name="search" maxlength="255" value="{{ $filters['search'] ?? '' }}"></label>
    <label class="account-field">Статус<select name="status"><option value="">Все статусы</option>@foreach(['draft' => 'Черновик', 'published' => 'Опубликовано', 'cancelled' => 'Отменено', 'finished' => 'Завершено'] as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
    <label class="account-field">ID организатора<input type="number" name="organizer_id" min="1" step="1" value="{{ $filters['organizer_id'] ?? '' }}"></label>
    <label class="account-field">Категория<select name="category_id"><option value="">Все категории</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) ($filters['category_id'] ?? '') === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></label>
    <label class="account-field">Площадка<select name="venue_id"><option value="">Все площадки</option>@foreach($venues as $venue)<option value="{{ $venue->id }}" @selected((string) ($filters['venue_id'] ?? '') === (string) $venue->id)>{{ $venue->name }}</option>@endforeach</select></label>
    <div class="order-actions"><button class="page-button" type="submit">Найти мероприятия</button><a class="inline-link" href="{{ route('admin.events.index') }}">Сбросить</a></div>
</form></section>
<p class="muted">Найдено: {{ $events->total() }}</p>
<div class="purchase-list">
@forelse($events as $event)
    <article class="account-card purchase-card"><div><p class="eyebrow">№{{ $event->id }} · @include('organizer.events.status')</p><h2>{{ $event->title }}</h2>
        <p class="muted">{{ $event->category->name }} · {{ $event->venue->name }}</p>
        <p class="muted">Начало: <time data-date="{{ $event->starts_at->toIso8601String() }}">{{ $event->starts_at->format('d.m.Y H:i') }} UTC</time></p>
        <p class="muted">Организатор №{{ $event->organizer_id }}: {{ $event->organizer->name ?: 'Имя не указано' }} · {{ $event->organizer->email }}</p>
    </div><a class="page-button" href="{{ route('admin.events.show', $event) }}">Подробнее ↗</a></article>
@empty
    <section class="account-card"><h2>Мероприятия не найдены</h2><p class="muted">Измените запрос или сбросьте фильтры.</p></section>
@endforelse
</div>
@include('purchases.pagination', ['paginator' => $events])
@endsection
