<nav class="report-nav" aria-label="Разделы администратора">
    @can('viewAny', \App\Models\Order::class)<a class="page-button" href="{{ route('admin.purchases.orders') }}" @if(request()->routeIs('admin.purchases.orders')) aria-current="page" @endif>Заказы</a>@endcan
    @can('viewAny', \App\Models\Ticket::class)<a class="page-button" href="{{ route('admin.purchases.tickets') }}" @if(request()->routeIs('admin.purchases.tickets')) aria-current="page" @endif>Билеты</a>@endcan
    @can('viewAny', \App\Models\Event::class)<a class="page-button" href="{{ route('admin.events.index') }}" @if(request()->routeIs('admin.events.*')) aria-current="page" @endif>Мероприятия</a>@endcan
    @can('users.view')<a class="page-button" href="{{ route('admin.users.index') }}" @if(request()->routeIs('admin.users.*')) aria-current="page" @endif>Пользователи</a>@endcan
    @can('categories.view')<a class="page-button" href="{{ route('admin.categories.index') }}" @if(request()->routeIs('admin.categories.*')) aria-current="page" @endif>Категории</a>@endcan
    @can('venues.view')<a class="page-button" href="{{ route('admin.venues.index') }}" @if(request()->routeIs('admin.venues.*')) aria-current="page" @endif>Площадки</a>@endcan
</nav>
