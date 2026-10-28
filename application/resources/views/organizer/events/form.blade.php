@extends('layouts.site')
@section('title', ($event->exists ? 'Редактирование мероприятия' : 'Новое мероприятие').' — Events')
@section('content')
<a class="inline-link back-link" href="{{ route('organizer.events.index') }}">← Мои мероприятия</a>
<div class="account-heading"><p class="eyebrow">КАБИНЕТ ОРГАНИЗАТОРА @if($event->exists) · @include('organizer.events.status') @endif</p><h1>{{ $event->exists ? 'Редактирование мероприятия' : 'Новое мероприятие' }}</h1><p class="muted">{{ $event->status === \App\Enums\EventStatus::PUBLISHED ? 'Изменения опубликованного мероприятия сразу видны в афише.' : 'Сохранение данных не публикует мероприятие в афише.' }}</p></div>
@if(session('event_success'))<p class="verification-banner" role="status">{{ session('event_success') }}</p>@endif
@if(session('event_error'))<p class="verification-banner is-error" role="alert">{{ session('event_error') }}</p>@endif
@if($event->exists)@include('organizer.events.actions')@endif
@if($errors->any())
    <div class="verification-banner is-error" role="alert"><strong>Не удалось сохранить мероприятие.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
@if($locked)<p class="verification-banner">Билеты уже продавались. Даты, площадка и условия отмены заблокированы, чтобы сохранить условия покупки.</p>@endif
<section class="account-card organizer-form">
    @if($categories->isEmpty() || $venues->isEmpty())<p class="verification-banner">Для сохранения нужны категория и площадка. Попросите администратора добавить их.</p>@endif
    <form method="post" action="{{ $event->exists ? route('organizer.events.update', $event) : route('organizer.events.store') }}">
        @csrf
        @if($event->exists)@method('PATCH')@endif
        <label class="account-field">Название<input name="title" maxlength="255" value="{{ old('title', $event->title) }}" required @error('title') aria-invalid="true" @enderror></label>
        <label class="account-field">Описание<textarea name="description" rows="7" required @error('description') aria-invalid="true" @enderror>{{ old('description', $event->description) }}</textarea></label>
        <label class="account-field">Категория<select name="category_id" required @error('category_id') aria-invalid="true" @enderror><option value="">Выберите категорию</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) old('category_id', $event->category_id) === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></label>
        <label class="account-field">Площадка<select name="venue_id" required @disabled($locked) @error('venue_id') aria-invalid="true" @enderror><option value="">Выберите площадку</option>@foreach($venues as $venue)<option value="{{ $venue->id }}" @selected((string) ($locked ? $event->venue_id : old('venue_id', $event->venue_id)) === (string) $venue->id)>{{ $venue->name }} · {{ $venue->address }} · {{ $venue->capacity }} мест</option>@endforeach</select></label>
        <p class="field-hint" id="event-timezone">Указывайте даты и время по Ташкенту (UTC+5). Пересчитывать время вручную не нужно.</p>
        <div class="organizer-dates">
            <label class="account-field">Начало (Ташкент)<input name="starts_at" type="datetime-local" step="1" required aria-describedby="event-timezone" value="{{ $locked || ($event->exists && $event->starts_at->isPast()) ? $event->starts_at->copy()->setTimezone('Asia/Tashkent')->format('Y-m-d\TH:i:s') : (is_string(old('starts_at')) ? old('starts_at') : $event->starts_at?->copy()->setTimezone('Asia/Tashkent')->format('Y-m-d\TH:i:s')) }}" @disabled($locked || ($event->exists && $event->starts_at->isPast())) @error('starts_at') aria-invalid="true" @enderror></label>
            <label class="account-field">Окончание (Ташкент)<input name="ends_at" type="datetime-local" step="1" required aria-describedby="event-timezone" value="{{ $locked ? $event->ends_at->copy()->setTimezone('Asia/Tashkent')->format('Y-m-d\TH:i:s') : (is_string(old('ends_at')) ? old('ends_at') : $event->ends_at?->copy()->setTimezone('Asia/Tashkent')->format('Y-m-d\TH:i:s')) }}" @disabled($locked) @error('ends_at') aria-invalid="true" @enderror></label>
        </div>
        @if($event->exists && $event->starts_at->isPast())<p class="field-hint">Начало уже наступило: изменить его нельзя. Название и описание можно сохранить.</p>@endif
        @unless($locked)<input type="hidden" name="allows_ticket_cancellation" value="0">@endunless
        <label class="organizer-checkbox"><input type="checkbox" name="allows_ticket_cancellation" value="1" @checked($locked ? $event->allows_ticket_cancellation : old('allows_ticket_cancellation', $event->allows_ticket_cancellation)) @disabled($locked)> Разрешить участникам отменять неиспользованные билеты до начала мероприятия</label>
        <div class="order-actions"><button class="primary-button" type="submit" @disabled($categories->isEmpty() || $venues->isEmpty())>{{ $event->exists ? 'Сохранить изменения' : 'Создать черновик' }} ↗</button><a class="inline-link" href="{{ route('organizer.events.index') }}">К списку мероприятий</a></div>
    </form>
</section>
@endsection
