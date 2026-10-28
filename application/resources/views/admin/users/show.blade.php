@extends('layouts.site')
@section('title', 'Управление аккаунтом — Events')
@section('content')
<a class="inline-link back-link" href="{{ route('admin.users.index') }}">← Пользователи</a>
<div class="account-heading"><p class="eyebrow">КАБИНЕТ АДМИНИСТРАТОРА · АККАУНТ №{{ $managedUser->id }}</p><h1 class="report-email">{{ $managedUser->name ?: 'Имя не указано' }}</h1><p class="muted report-email">{{ $managedUser->email }}</p></div>
@if(session('admin_success'))<p class="verification-banner" role="status">{{ session('admin_success') }}</p>@endif
@if($errors->any())<div class="verification-banner is-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<section class="account-card organizer-form"><h2>Состояние аккаунта</h2><p>{{ $managedUser->is_blocked ? 'Заблокирован' : 'Доступ разрешён' }}</p><p>Роль: @include('admin.users.role-name', ['roleName' => $managedUser->rbacRole?->name])</p><p>{{ $managedUser->email_verified_at ? 'Email подтверждён' : 'Email не подтверждён' }}</p><p class="field-hint">Дата регистрации: {{ $managedUser->created_at->format('d.m.Y H:i') }} UTC</p></section>
@if($managedUser->id === auth()->id())
    <p class="verification-banner">Это ваш аккаунт. Для смены собственной роли или блокировки обратитесь к другому администратору.</p>
@else
    <div class="account-columns admin-user-actions">
        @can('users.update-role')
            <section class="account-card"><h2>Смена роли</h2><p class="muted">Роль определяет доступ к кабинету организатора и административным действиям. Новые права начнут действовать при следующих запросах.</p>
                <form method="post" action="{{ route('admin.users.role', $managedUser) }}">@csrf @method('PATCH')
                    <label class="account-field">Новая роль<select name="role" required><option value="">Выберите роль</option>@foreach($roles as $role)<option value="{{ $role->name }}" @selected((is_string(old('role')) ? old('role') : $managedUser->rbacRole?->name) === $role->name)>@include('admin.users.role-name', ['roleName' => $role->name])</option>@endforeach</select></label>
                    <label class="organizer-checkbox"><input type="checkbox" name="confirm_role" value="1" required> Подтверждаю изменение прав пользователя</label>
                    <button class="primary-button" type="submit" @disabled($roles->isEmpty())>Изменить роль</button>
                </form>
            </section>
        @endcan
        <section class="account-card"><h2>{{ $managedUser->is_blocked ? 'Разблокировка' : 'Блокировка' }}</h2>
            @if($managedUser->is_blocked)
                @can('users.unblock')<p class="muted">Пользователь снова сможет войти и выполнять действия своей роли. Отозванные API-токены не восстанавливаются.</p><form method="post" action="{{ route('admin.users.unblock', $managedUser) }}">@csrf @method('PATCH')<label class="organizer-checkbox"><input type="checkbox" name="confirm_unblock" value="1" required> Подтверждаю разблокировку</label><button class="primary-button" type="submit">Разблокировать пользователя</button></form>@else<p class="muted">У вас нет права разблокировать пользователей.</p>@endcan
            @else
                @can('users.block')<p class="muted">Пользователь потеряет доступ к защищённым действиям. Его API-токены будут отозваны. Заказы и билеты сохранятся.</p><form method="post" action="{{ route('admin.users.block', $managedUser) }}">@csrf @method('PATCH')<label class="organizer-checkbox"><input type="checkbox" name="confirm_block" value="1" required> Подтверждаю блокировку</label><button class="primary-button" type="submit">Заблокировать пользователя</button></form>@else<p class="muted">У вас нет права блокировать пользователей.</p>@endcan
            @endif
        </section>
    </div>
@endif
@endsection
