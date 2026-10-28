<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Events — ваш аккаунт')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="skip-link" href="#main">Перейти к содержимому</a>
    @include('partials.header')
    <main id="main" class="wrap account-main"><noscript><p class="verification-banner">Для работы с аккаунтом включите JavaScript в браузере.</p></noscript>@yield('content')</main>
    <footer class="site-footer wrap">@include('partials.brand')<p>Меньше рутины. Больше впечатлений.</p></footer>
</body>
</html>
