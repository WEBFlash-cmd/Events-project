@extends('layouts.site')
@section('title', 'Регистрация — Events')
@section('content')
<section class="auth-layout">
    <div class="auth-intro"><p class="eyebrow">БОЛЬШЕ ВПЕЧАТЛЕНИЙ ВПЕРЕДИ</p><h1>Начните<br>с <em>знакомства.</em></h1><p>Создайте аккаунт. После регистрации мы отправим код подтверждения на вашу почту.</p></div>
    <div class="account-card"><h2>Регистрация</h2><p class="muted">Уже с нами? <a class="inline-link" href="{{ route('login') }}">Войти</a></p>
        <form method="post" action="/register" data-account-form="register">@csrf
            <label class="account-field">Email<input name="email" type="email" autocomplete="username" maxlength="255" required></label>
            <label class="account-field">Пароль<input name="password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required aria-describedby="password-hint"></label>
            <p class="field-hint" id="password-hint">От 8 символов, не более 72 байт.</p>
            <label class="account-field">Повторите пароль<input name="password_confirmation" type="password" autocomplete="new-password" minlength="8" maxlength="72" required></label>
            <p class="form-feedback" role="status" tabindex="-1" hidden></p>
            <button class="primary-button" type="submit">Создать аккаунт ↗</button>
        </form>
    </div>
</section>
@endsection
