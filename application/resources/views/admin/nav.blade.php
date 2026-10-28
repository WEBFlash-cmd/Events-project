<nav class="report-nav" aria-label="Разделы администратора">
    @can('users.view')<a class="page-button" href="{{ route('admin.users.index') }}" @if(request()->routeIs('admin.users.*')) aria-current="page" @endif>Пользователи</a>@endcan
    @can('categories.view')<a class="page-button" href="{{ route('admin.categories.index') }}" @if(request()->routeIs('admin.categories.*')) aria-current="page" @endif>Категории</a>@endcan
    @can('venues.view')<a class="page-button" href="{{ route('admin.venues.index') }}" @if(request()->routeIs('admin.venues.*')) aria-current="page" @endif>Площадки</a>@endcan
</nav>
