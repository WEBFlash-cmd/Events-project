<header class="site-header wrap">
    @include('partials.brand')
    <nav class="header-nav" aria-label="Основная навигация">
        <a class="nav-link" href="{{ route('catalog') }}#catalog">Афиша ↗</a>
        @auth('web')
            <a class="nav-link" href="{{ route('purchases.orders') }}">Мои заказы</a>
            <a class="nav-link" href="{{ route('purchases.tickets') }}">Мои билеты</a>
            <a class="nav-link" href="{{ route('waitlist.index') }}">Лист ожидания</a>
            <a class="nav-link" href="{{ route('account') }}">Личный кабинет</a>
            @can('events.create')<a class="nav-link" href="{{ route('organizer.events.index') }}">Организатор</a>@endcan
            @php
                $adminRoute = match (true) {
                    auth()->user()->can('users.view') => 'admin.users.index',
                    auth()->user()->can('categories.view') => 'admin.categories.index',
                    auth()->user()->canAny(['venues.create', 'venues.update', 'venues.delete']) => 'admin.venues.index',
                    auth()->user()->can('viewAny', \App\Models\Event::class) => 'admin.events.index',
                    auth()->user()->can('viewAny', \App\Models\Order::class) => 'admin.purchases.orders',
                    auth()->user()->can('viewAny', \App\Models\Ticket::class) => 'admin.purchases.tickets',
                    default => null,
                };
            @endphp
            @if($adminRoute)<a class="nav-link" href="{{ route($adminRoute) }}">Администратор</a>@endif
            <form method="post" action="/logout" data-account-form="logout">@csrf<button class="page-button" type="submit">Выйти</button><p class="form-feedback" role="status" hidden></p></form>
        @else
            <a class="nav-link" href="{{ route('login') }}">Войти</a>
            <a class="page-button" href="{{ route('register') }}">Регистрация</a>
        @endauth
    </nav>
</header>
