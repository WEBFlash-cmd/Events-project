@extends('layouts.site')
@section('title', 'Отчёт по продажам — Events')
@section('content')
@include('organizer.reports.nav')
<h2>Продажи и возвраты</h2><p class="muted">Данные на момент открытия страницы. Обновите её, чтобы получить новые результаты.</p>
<div class="report-metrics">
    <section class="account-card"><p>Получено по подтверждённым платежам</p><strong>{{ number_format((float) $report['money']['gross_received'], 2, ',', ' ') }} UZS</strong></section>
    <section class="account-card"><p>Необходимо вернуть</p><strong>{{ number_format((float) $report['money']['refund_required'], 2, ',', ' ') }} UZS</strong></section>
    <section class="account-card"><p>Получено за вычетом необходимых возвратов</p><strong>{{ number_format((float) $report['money']['excluding_required_refunds'], 2, ',', ' ') }} UZS</strong></section>
</div>
<p class="field-hint">Необходимый возврат — это зафиксированное обязательство, а не подтверждение перечисления денег покупателю. Суммы включают поздние оплаты, даже если билеты по ним не выдавались.</p>
<section class="account-card report-section"><h2>Заказы</h2><dl class="report-counts"><div><dt>Ожидают оплаты</dt><dd>{{ $report['orders']['pending'] }}</dd></div><div><dt>Оплачены</dt><dd>{{ $report['orders']['paid'] }}</dd></div><div><dt>Отменены</dt><dd>{{ $report['orders']['cancelled'] }}</dd></div><div><dt>Срок резерва истёк</dt><dd>{{ $report['orders']['expired'] }}</dd></div></dl></section>
<section class="account-card report-section"><h2>Билеты и участники</h2><dl class="report-counts"><div><dt>Выдано билетов, включая отменённые</dt><dd>{{ $report['tickets']['sold'] }}</dd></div><div><dt>Активных билетов</dt><dd>{{ $report['tickets']['active'] }}</dd></div><div><dt>Входов зарегистрировано</dt><dd>{{ $report['tickets']['checked_in'] }}</dd></div><div><dt>Билетов с необходимым возвратом</dt><dd>{{ $report['tickets']['refund_required'] }}</dd></div><div><dt>Остаток свободных мест</dt><dd>{{ $report['tickets']['available'] }}</dd></div><div><dt>Уникальных покупателей с билетами</dt><dd>{{ $report['buyers'] }}</dd></div></dl></section>
<section class="account-card report-section"><h2>По типам билетов</h2>
    @if(count($report['ticket_types']))<div class="report-table-scroll" role="region" aria-label="Продажи по типам билетов" tabindex="0"><table class="report-table"><thead><tr><th scope="col">Тип билета</th><th scope="col">Текущая цена, UZS</th><th scope="col">Свободно</th><th scope="col">Выдано</th><th scope="col">Активно</th><th scope="col">Входов</th><th scope="col">Нужен возврат</th></tr></thead><tbody>
        @foreach($report['ticket_types'] as $type)<tr><th scope="row">{{ $type['name'] }}</th><td>{{ number_format((float) $type['price'], 2, ',', ' ') }}</td><td>{{ $type['available'] }}</td><td>{{ $type['sold'] }}</td><td>{{ $type['active'] }}</td><td>{{ $type['checked_in'] }}</td><td>{{ $type['refund_required'] }}</td></tr>@endforeach
    </tbody></table></div>@else<p class="muted">Типов билетов пока нет.</p>@endif
    <p class="field-hint">Текущая цена может отличаться от цены уже оплаченных заказов. Остаток не означает, что продажи открыты: это зависит от статуса и дат мероприятия.</p>
</section>
@endsection
