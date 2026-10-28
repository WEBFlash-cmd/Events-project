<section class="account-card organizer-form event-management" aria-label="Управление мероприятием">
    <h2>Билеты и публикация</h2>
    <a class="page-button" href="{{ route('organizer.tickets.index', $event) }}">Управление типами билетов ↗</a>
    @if($event->status === \App\Enums\EventStatus::DRAFT && $event->starts_at->isFuture())
        @can('publish', $event)
            <details><summary>Опубликовать мероприятие</summary><p>Мероприятие станет доступно в афише. Перед публикацией проверьте даты, площадку и добавьте типы билетов.</p>
                <form method="post" action="{{ route('organizer.events.publish', $event) }}">@csrf<button class="primary-button" type="submit">Опубликовать в афише</button></form>
            </details>
        @endcan
    @endif
    @if($event->status === \App\Enums\EventStatus::PUBLISHED)
        <p><a class="inline-link" href="{{ route('events.show', $event) }}">Посмотреть в афише ↗</a></p>
        @if($event->ends_at->isFuture())@can('cancel', $event)
            <details><summary>Отменить мероприятие</summary><p>Мероприятие исчезнет из афиши. Неоплаченные резервы будут сняты, билеты станут недействительными. По оплаченным заказам будет отмечена необходимость возврата и запланированы письма покупателям.</p><p>Эта отметка не означает, что деньги уже возвращены.</p>
                <form method="post" action="{{ route('organizer.events.cancel', $event) }}">@csrf<label class="organizer-checkbox"><input type="checkbox" name="confirm_cancel" value="1" required> Подтверждаю отмену мероприятия</label><button class="page-button" type="submit">Отменить мероприятие</button></form>
            </details>
        @endcan @endif
    @endif
</section>
