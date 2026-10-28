@extends('layouts.site')
@section('title', ($venue->exists ? 'Редактирование площадки' : 'Новая площадка').' — Events')
@section('content')
@include('admin.nav')
<a class="inline-link back-link" href="{{ route('admin.venues.index') }}">← Площадки</a>
<div class="account-heading"><p class="eyebrow">КАБИНЕТ АДМИНИСТРАТОРА</p><h1>{{ $venue->exists ? 'Редактирование площадки' : 'Новая площадка' }}</h1><p class="muted">Вместимость ограничивает количество билетов на каждое мероприятие.</p></div>
@include('admin.feedback')
@if($locked)<p class="verification-banner">Площадка используется в мероприятиях. Адрес и вместимость менять нельзя; для другого адреса или количества мест создайте новую площадку. Название и описание можно редактировать.</p>@endif
<section class="account-card organizer-form"><form method="post" action="{{ $venue->exists ? route('admin.venues.update', $venue) : route('admin.venues.store') }}">
    @csrf
    @if($venue->exists)@method('PATCH')@endif
    <label class="account-field">Название<input name="name" maxlength="255" required value="{{ is_scalar(old('name', $venue->name)) ? old('name', $venue->name) : '' }}" @error('name') aria-invalid="true" @enderror></label>
    <label class="account-field">Адрес<input name="address" maxlength="255" required value="{{ $locked ? $venue->address : (is_scalar(old('address', $venue->address)) ? old('address', $venue->address) : '') }}" @disabled($locked) @error('address') aria-invalid="true" @enderror></label>
    <label class="account-field">Вместимость<input name="capacity" type="number" min="1" max="2147483647" step="1" required value="{{ $locked ? $venue->capacity : (is_scalar(old('capacity', $venue->capacity)) ? old('capacity', $venue->capacity) : '') }}" @disabled($locked) @error('capacity') aria-invalid="true" @enderror></label>
    <label class="account-field">Описание <span class="field-hint">Необязательно</span><textarea name="description" rows="5" @error('description') aria-invalid="true" @enderror>{{ is_scalar(old('description', $venue->description)) ? old('description', $venue->description) : '' }}</textarea></label>
    <div class="order-actions"><button class="primary-button" type="submit">{{ $venue->exists ? 'Сохранить изменения' : 'Создать площадку' }}</button><a class="inline-link" href="{{ route('admin.venues.index') }}">К списку площадок</a></div>
</form></section>
@endsection
