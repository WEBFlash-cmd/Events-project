<a class="inline-link back-link" href="{{ route('organizer.events.index') }}">← Мои мероприятия</a>
<div class="account-heading"><p class="eyebrow">КАБИНЕТ ОРГАНИЗАТОРА · @include('organizer.events.status')</p><h1>{{ $event->title }}</h1><p class="muted">{{ $event->venue->name }} · <time data-date="{{ $event->starts_at->toIso8601String() }}">{{ $event->starts_at->format('d.m.Y H:i') }} UTC</time></p></div>
<nav class="report-nav" aria-label="Отчёты мероприятия">
    <a class="page-button" href="{{ route('organizer.reports.sales', $event) }}" @if(request()->routeIs('organizer.reports.sales')) aria-current="page" @endif>Продажи</a>
    <a class="page-button" href="{{ route('organizer.reports.participants', $event) }}" @if(request()->routeIs('organizer.reports.participants')) aria-current="page" @endif>Участники</a>
    <a class="page-button" href="{{ route('organizer.reports.check-in', $event) }}" @if(request()->routeIs('organizer.reports.check-in')) aria-current="page" @endif>Проверка входа</a>
</nav>
