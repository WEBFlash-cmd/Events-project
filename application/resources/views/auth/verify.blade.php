@extends('layouts.site')
@section('title', 'Подтверждение email — Events')
@section('content')
<section class="auth-layout">
    <div class="auth-intro"><p class="eyebrow">ЕЩЁ ОДИН ШАГ</p><h1>Проверьте<br>свою <em>почту.</em></h1><p>Введите код из письма Events. Он действует один час. Если письма нет во входящих, проверьте папку «Спам».</p></div>
    <div class="account-card"><h2>Подтвердите email</h2>
        @auth('web')<p class="muted">Вы вошли как <strong>{{ auth('web')->user()->email }}</strong>.</p>@endauth
        <form method="post" action="/verify-email" data-account-form="verify">@csrf
            <label class="account-field">Код из письма<input name="code" type="text" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" required aria-describedby="code-hint"></label>
            <p class="field-hint" id="code-hint">Введите код с учётом регистра букв.</p>
            <p class="form-feedback" role="status" tabindex="-1" hidden></p>
            <button class="primary-button" type="submit">Подтвердить email ↗</button>
        </form>
        <a class="inline-link verification-link" href="{{ auth('web')->check() ? route('account') : route('login') }}">{{ auth('web')->check() ? 'Перейти в кабинет' : 'Перейти ко входу' }}</a>
    </div>
</section>
@endsection
