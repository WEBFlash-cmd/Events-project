@extends('layouts.site')
@section('title', ($category->exists ? 'Редактирование категории' : 'Новая категория').' — Events')
@section('content')
@include('admin.nav')
<a class="inline-link back-link" href="{{ route('admin.categories.index') }}">← Категории</a>
<div class="account-heading"><p class="eyebrow">КАБИНЕТ АДМИНИСТРАТОРА</p><h1>{{ $category->exists ? 'Редактирование категории' : 'Новая категория' }}</h1><p class="muted">Название и описание будут видны при выборе категории мероприятия.</p></div>
@include('admin.feedback')
<section class="account-card organizer-form"><form method="post" action="{{ $category->exists ? route('admin.categories.update', $category) : route('admin.categories.store') }}">
    @csrf
    @if($category->exists)@method('PATCH')@endif
    <label class="account-field">Название<input name="name" maxlength="255" required value="{{ is_scalar(old('name', $category->name)) ? old('name', $category->name) : '' }}" @error('name') aria-invalid="true" @enderror></label>
    <label class="account-field">Описание <span class="field-hint">Необязательно</span><textarea name="description" rows="5" @error('description') aria-invalid="true" @enderror>{{ is_scalar(old('description', $category->description)) ? old('description', $category->description) : '' }}</textarea></label>
    <div class="order-actions"><button class="primary-button" type="submit">{{ $category->exists ? 'Сохранить изменения' : 'Создать категорию' }}</button><a class="inline-link" href="{{ route('admin.categories.index') }}">К списку категорий</a></div>
</form></section>
@endsection
