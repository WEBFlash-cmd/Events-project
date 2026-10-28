@extends('layouts.site')
@section('title', 'Проверка входа — Events')
@section('content')
@include('organizer.reports.nav')
<h2>Проверка входа</h2><p class="muted">Введите полный номер билета. Поиск не регистрирует вход — для этого нужна отдельная кнопка.</p>
@if(session('check_in_success'))<p class="verification-banner" role="status">{{ session('check_in_success') }}</p>@endif
@if(session('check_in_error'))<p class="verification-banner is-error" role="alert">{{ session('check_in_error') }}</p>@endif
@if($errors->any())<div class="verification-banner is-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<section class="account-card organizer-form"><form method="get" action="{{ route('organizer.reports.check-in', $event) }}"><label class="account-field">Номер билета<input name="number" required maxlength="255" autocomplete="off" value="{{ is_string(old('number')) ? old('number') : $number }}"></label><button class="primary-button" type="submit">Найти билет</button></form></section>
@if($number !== null)
    @if($ticket === null)<p class="verification-banner" role="status">Билет с таким номером для этого мероприятия не найден.</p>
    @else
        <section class="account-card organizer-form report-section"><h2>@include('organizer.reports.ticket-status')</h2><p class="ticket-number">{{ $ticket->number }}</p><p class="report-email">Покупатель: {{ $ticket->user->email }}</p><p>Тип: {{ $ticket->ticketType->name }}</p>
            @if($ticket->checked_in_at)<p>Вход зарегистрирован: <time data-date="{{ $ticket->checked_in_at->toIso8601String() }}">{{ $ticket->checked_in_at->format('d.m.Y H:i') }} UTC</time></p>@endif
            @if($event->status === \App\Enums\EventStatus::PUBLISHED && $event->ends_at->isFuture() && $ticket->status === 'active' && !$ticket->checked_in_at && !$ticket->refund_required && $ticket->order->status === 'paid' && $ticket->order->event_id === $event->id && $ticket->order->user_id === $ticket->user_id)
                @can('checkIn', [$ticket, $event])<form method="post" action="{{ route('organizer.reports.check-in.store', [$event, $ticket]) }}">@csrf<button class="primary-button" type="submit">Зарегистрировать вход</button></form>@else<p class="verification-banner">У вас нет права регистрировать вход.</p>@endcan
            @else<p class="verification-banner">Вход по этому билету недоступен: проверьте статус билета, оплату и состояние мероприятия.</p>@endif
        </section>
    @endif
@endif
@endsection
