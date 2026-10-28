@extends('layouts.site')
@section('title', 'Типы билетов — Events')
@section('content')
<a class="inline-link back-link" href="{{ route('organizer.events.edit', $event) }}">← К мероприятию</a>
<div class="organizer-heading"><div class="account-heading"><p class="eyebrow">{{ $event->title }}</p><h1>Типы билетов<span class="brand-dot">.</span></h1><p class="muted">Вместимость площадки: {{ $event->venue->capacity }} мест. Остаток означает свободные места; проданные и зарезервированные учитываются отдельно.</p></div>
@can('create', [\App\Models\TicketType::class, $event])<a class="primary-button" href="{{ route('organizer.tickets.create', $event) }}">Добавить тип билета ↗</a>@endcan</div>
<div class="purchase-list">
@forelse($types as $type)
    <article class="account-card purchase-card"><div><h2>{{ $type->name }}</h2><p class="event-description">{{ $type->description }}</p><p>Цена: <strong>{{ number_format((float) $type->price, 2, ',', ' ') }} UZS</strong></p><p>Свободных мест: {{ $type->quantity }}</p></div>
        @can('update', [$type, $event])<a class="page-button" href="{{ route('organizer.tickets.edit', [$event, $type]) }}">Редактировать билет ↗</a>@endcan
    </article>
@empty
    <section class="account-card"><h2>Типов билетов пока нет</h2><p class="muted">Добавьте название, цену и начальное количество свободных мест.</p></section>
@endforelse
</div>
@include('purchases.pagination', ['paginator' => $types])
@endsection
