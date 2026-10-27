<header class="site-header wrap">
    <a class="brand" href="{{ route('catalog') }}" aria-label="Events — главная"><span class="brand-mark">e</span>events<span class="brand-dot">.</span></a>
    <nav class="header-nav" aria-label="Основная навигация">
        <a class="nav-link" href="{{ route('catalog') }}#catalog">Афиша ↗</a>
        @auth('web')
            <a class="nav-link" href="{{ route('account') }}">Личный кабинет</a>
            <form method="post" action="/logout" data-account-form="logout">@csrf<button class="page-button" type="submit">Выйти</button><p class="form-feedback" role="status" hidden></p></form>
        @else
            <a class="nav-link" href="{{ route('login') }}">Войти</a>
            <a class="page-button" href="{{ route('register') }}">Регистрация</a>
        @endauth
    </nav>
</header>
