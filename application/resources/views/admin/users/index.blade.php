@extends('layouts.site')
@section('title', 'Пользователи — Events')
@section('content')
@include('admin.nav')
<div class="account-heading"><p class="eyebrow">КАБИНЕТ АДМИНИСТРАТОРА</p><h1>Пользователи<span class="brand-dot">.</span></h1><p class="muted">Поиск аккаунтов, проверка роли и состояния доступа.</p></div>
@if($errors->any())<div class="verification-banner is-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<section class="account-card admin-filters"><form method="get" action="{{ route('admin.users.index') }}">
    <label class="account-field">Имя или email<input name="search" maxlength="150" value="{{ $filters['search'] ?? '' }}" type="search"></label>
    <label class="account-field">Роль<select name="role"><option value="">Все роли</option>@foreach(['admin' => 'Администратор', 'organizer' => 'Организатор', 'participant' => 'Участник'] as $value => $label)<option value="{{ $value }}" @selected(($filters['role'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
    <label class="account-field">Состояние<select name="state"><option value="">Все аккаунты</option><option value="active" @selected(($filters['state'] ?? '') === 'active')>Доступ разрешён</option><option value="blocked" @selected(($filters['state'] ?? '') === 'blocked')>Заблокирован</option></select></label>
    <div class="order-actions"><button class="page-button" type="submit">Найти пользователей</button><a class="inline-link" href="{{ route('admin.users.index') }}">Сбросить</a></div>
</form></section>
<p class="muted">Найдено: {{ $users->total() }}</p>
<div class="purchase-list">
@forelse($users as $listedUser)
    <article class="account-card purchase-card"><div><p class="eyebrow">АККАУНТ №{{ $listedUser->id }} · {{ $listedUser->created_at->format('d.m.Y') }}</p><h2 class="report-email">{{ $listedUser->name ?: 'Имя не указано' }}</h2><p class="report-email">{{ $listedUser->email }}</p><p>@include('admin.users.role-name', ['roleName' => $listedUser->rbacRole?->name]) · {{ $listedUser->is_blocked ? 'Заблокирован' : 'Доступ разрешён' }}</p><p class="field-hint">{{ $listedUser->email_verified_at ? 'Email подтверждён' : 'Email не подтверждён' }}</p></div><a class="page-button" href="{{ route('admin.users.show', $listedUser) }}">Управлять аккаунтом ↗</a></article>
@empty<section class="account-card"><h2>Пользователи не найдены</h2><p class="muted">Измените поисковый запрос или сбросьте фильтры.</p></section>
@endforelse
</div>
@include('purchases.pagination', ['paginator' => $users])
@endsection
