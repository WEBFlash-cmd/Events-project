@extends('layouts.site')
@section('title', 'Категории — Events')
@section('content')
@include('admin.nav')
<div class="organizer-heading">
    <div class="account-heading"><p class="eyebrow">КАБИНЕТ АДМИНИСТРАТОРА</p><h1>Категории<span class="brand-dot">.</span></h1><p class="muted">Разделы афиши, которые организатор выбирает при создании мероприятия.</p></div>
    @can('categories.create')<a class="primary-button" href="{{ route('admin.categories.create') }}">Добавить категорию ↗</a>@endcan
</div>
@include('admin.feedback')
<section class="account-card"><form method="get" action="{{ route('admin.categories.index') }}">
    <label class="account-field">Название категории<input name="search" type="search" maxlength="150" value="{{ $filters['search'] ?? '' }}"></label>
    <div class="order-actions"><button class="page-button" type="submit">Найти категории</button><a class="inline-link" href="{{ route('admin.categories.index') }}">Сбросить</a></div>
</form></section>
<p class="muted">Найдено: {{ $categories->total() }}</p>
<div class="purchase-list">
@forelse($categories as $category)
    <article class="account-card catalog-entry">
        <div class="purchase-card"><div><h2>{{ $category->name }}</h2><p class="muted">{{ $category->description ?: 'Описание не добавлено.' }}</p><p class="field-hint">Мероприятий: {{ $category->events_count }}</p></div>
            @can('categories.update')<a class="page-button" href="{{ route('admin.categories.edit', $category) }}">Редактировать ↗</a>@endcan
        </div>
        @can('categories.delete')
            @if($category->events_count)
                <p class="field-hint">Удаление недоступно: категория используется в мероприятиях.</p>
            @else
                <details class="ticket-cancellation"><summary>Удалить категорию</summary>
                    <form method="post" action="{{ route('admin.categories.destroy', $category) }}">@csrf @method('DELETE')
                        <p class="muted">Категория «{{ $category->name }}» будет удалена из справочника.</p>
                        <label class="organizer-checkbox"><input type="checkbox" name="confirm_delete" value="1" required> Подтверждаю удаление категории</label>
                        <button class="page-button" type="submit">Удалить категорию</button>
                    </form>
                </details>
            @endif
        @endcan
    </article>
@empty
    <section class="account-card"><h2>Категории не найдены</h2><p class="muted">Измените запрос или добавьте категорию, если у вас есть право создания.</p></section>
@endforelse
</div>
@include('purchases.pagination', ['paginator' => $categories])
@endsection
