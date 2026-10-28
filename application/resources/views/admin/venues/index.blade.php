@extends('layouts.site')
@section('title', 'Площадки — Events')
@section('content')
@include('admin.nav')
<div class="organizer-heading">
    <div class="account-heading"><p class="eyebrow">СПРАВОЧНИК МЕСТ ПРОВЕДЕНИЯ</p><h1>Площадки<span class="brand-dot">.</span></h1><p class="muted">Адреса и вместимость залов для мероприятий.</p></div>
    @can('venues.create')<a class="primary-button" href="{{ route('admin.venues.create') }}">Добавить площадку ↗</a>@endcan
</div>
@include('admin.feedback')
<section class="account-card"><form method="get" action="{{ route('admin.venues.index') }}">
    <label class="account-field">Название или адрес<input name="search" type="search" maxlength="150" value="{{ $filters['search'] ?? '' }}"></label>
    <div class="order-actions"><button class="page-button" type="submit">Найти площадки</button><a class="inline-link" href="{{ route('admin.venues.index') }}">Сбросить</a></div>
</form></section>
<p class="muted">Найдено: {{ $venues->total() }}</p>
<div class="purchase-list">
@forelse($venues as $venue)
    <article class="account-card catalog-entry">
        <div class="purchase-card"><div><h2>{{ $venue->name }}</h2><p class="muted">{{ $venue->address }}</p><p class="muted">{{ $venue->description ?: 'Описание не добавлено.' }}</p><p>Вместимость: {{ number_format($venue->capacity, 0, '.', ' ') }} мест</p><p class="field-hint">Мероприятий: {{ $venue->events_count }}</p></div>
            @can('venues.update')<a class="page-button" href="{{ route('admin.venues.edit', $venue) }}">Редактировать ↗</a>@endcan
        </div>
        @can('venues.delete')
            @if($venue->events_count)
                <p class="field-hint">Удаление недоступно: площадка используется в мероприятиях.</p>
            @else
                <details class="ticket-cancellation"><summary>Удалить площадку</summary>
                    <form method="post" action="{{ route('admin.venues.destroy', $venue) }}">@csrf @method('DELETE')
                        <p class="muted">Площадка «{{ $venue->name }}» будет удалена из справочника.</p>
                        <label class="organizer-checkbox"><input type="checkbox" name="confirm_delete" value="1" required> Подтверждаю удаление площадки</label>
                        <button class="page-button" type="submit">Удалить площадку</button>
                    </form>
                </details>
            @endif
        @endcan
    </article>
@empty
    <section class="account-card"><h2>Площадки не найдены</h2><p class="muted">Измените запрос или добавьте площадку, если у вас есть право создания.</p></section>
@endforelse
</div>
@include('purchases.pagination', ['paginator' => $venues])
@endsection
