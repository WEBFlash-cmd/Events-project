<header class="site-header wrap">
    <a class="brand" href="{{ route('catalog') }}" aria-label="Events — главная"><span class="brand-mark">e</span>events<span class="brand-dot">.</span></a>
    <nav class="header-nav" aria-label="Основная навигация">
        <a class="nav-link" href="{{ route('catalog') }}#catalog">Афиша ↗</a>
        @auth('web')
            <a class="nav-link" href="{{ route('purchases.orders') }}">Мои заказы</a>
            <a class="nav-link" href="{{ route('purchases.tickets') }}">Мои билеты</a>
            <a class="nav-link" href="{{ route('waitlist.index') }}">Лист ожидания</a>
            <a class="nav-link" href="{{ route('account') }}">Личный кабинет</a>
            @can('events.create')<a class="nav-link" href="{{ route('organizer.events.index') }}">Организатор</a>@endcan
            @canany(['users.view', 'categories.view', 'venues.create', 'venues.update', 'venues.delete'])
                <a class="nav-link" href="{{ auth()->user()->can('users.view') ? route('admin.users.index') : (auth()->user()->can('categories.view') ? route('admin.categories.index') : route('admin.venues.index')) }}">Администратор</a>
            @endcanany
            <form method="post" action="/logout" data-account-form="logout">@csrf<button class="page-button" type="submit">Выйти</button><p class="form-feedback" role="status" hidden></p></form>
        @else
            <a class="nav-link" href="{{ route('login') }}">Войти</a>
            <a class="page-button" href="{{ route('register') }}">Регистрация</a>
        @endauth
    </nav>
</header>
