@extends('layouts.site')
@section('title', 'Вход — Events')
@section('content')
<section class="auth-layout">
    <div class="auth-intro"><p class="eyebrow">СНОВА ВМЕСТЕ</p><h1>Ваши планы<br>ждут <em>вас.</em></h1><p>Войдите в аккаунт, чтобы управлять своим профилем и готовиться к новым событиям.</p><a class="hero-link" href="{{ route('catalog') }}">Посмотреть афишу ↗</a></div>
    <div class="account-card"><h2>Вход в аккаунт</h2><p class="muted">Ещё нет аккаунта? <a class="inline-link" href="{{ route('register') }}">Зарегистрироваться</a></p>
        <form method="post" action="/login" data-account-form="login">@csrf
            <label class="account-field">Email<input name="email" type="email" autocomplete="username" maxlength="255" required></label>
            <label class="account-field">Пароль<input name="password" type="password" autocomplete="current-password" required></label>
            <p class="form-feedback" role="status" tabindex="-1" hidden></p>
            <button class="primary-button" type="submit">Войти ↗</button>
        </form>
        <a class="inline-link verification-link" href="{{ route('verification') }}">Ввести код подтверждения email</a>
    </div>
</section>
@endsection
