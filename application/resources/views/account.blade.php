@extends('layouts.site')
@section('title', 'Личный кабинет — Events')
@section('content')
<div class="account-heading"><p class="eyebrow">ВАШ АККАУНТ</p><h1>Личный кабинет<span class="brand-dot">.</span></h1><p class="muted">Здесь можно обновить данные и изменить пароль.</p></div>
<div class="verification-banner" id="verification-status" role="status">
    @if(auth()->user()->email_verified_at)Email подтверждён. @else Email ещё не подтверждён. <a class="inline-link" href="{{ route('verification') }}">Ввести код из письма ↗</a>@endif
</div>
<div class="account-columns">
    <section class="account-card"><h2>Личные данные</h2><p class="muted">Для смены email потребуется текущий пароль. На новый адрес придёт код подтверждения.</p>
        <form method="post" action="/account/profile" data-account-form="profile" data-method="PATCH">@csrf
            <label class="account-field">Имя<input name="name" type="text" autocomplete="name" maxlength="255" value="{{ auth()->user()->name }}"></label>
            <label class="account-field">Email<input name="email" type="email" autocomplete="email" maxlength="255" value="{{ auth()->user()->email }}" required data-original-email="{{ auth()->user()->email }}"></label>
            <label class="account-field">Текущий пароль для смены email<input name="current_password" type="password" autocomplete="current-password"></label>
            <p class="form-feedback" role="status" tabindex="-1" hidden></p>
            <button class="primary-button" type="submit">Сохранить данные ↗</button>
        </form>
    </section>
    <section class="account-card"><h2>Безопасность</h2><p class="muted">После смены пароля нужно будет войти заново. Остальные сеансы также завершатся.</p>
        <form method="post" action="/account/password" data-account-form="password" data-method="PATCH">@csrf
            <label class="account-field">Текущий пароль<input name="current_password" type="password" autocomplete="current-password" required></label>
            <label class="account-field">Новый пароль<input name="password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required aria-describedby="new-password-hint"></label>
            <p class="field-hint" id="new-password-hint">От 8 символов, не более 72 байт. Должен отличаться от текущего.</p>
            <label class="account-field">Повторите новый пароль<input name="password_confirmation" type="password" autocomplete="new-password" minlength="8" maxlength="72" required></label>
            <p class="form-feedback" role="status" tabindex="-1" hidden></p>
            <button class="primary-button" type="submit">Изменить пароль ↗</button>
        </form>
    </section>
</div>
@endsection
