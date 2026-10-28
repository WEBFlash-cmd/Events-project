@if($event->starts_at->isFuture())
    @foreach($event->ticketTypes->sortBy('id') as $type)
        @if($waitlistEntries->has($type->id) || ($type->quantity === 0 && (float) $type->price > 0))
            <section class="waitlist-option" aria-label="Лист ожидания: {{ $type->name }}">
                <h3>{{ $type->name }} · лист ожидания</h3>
                @if($waitlistEntries->has($type->id))
                    <p class="muted">У вас уже есть заявка на этот тип билета.</p><a class="inline-link" href="{{ route('waitlist.index') }}">Посмотреть заявку ↗</a>
                @else
                    <p class="muted">Билеты закончились. Можно встать в очередь на один билет. Когда освободится место, отправим предложение на email.</p>
                    @auth('web')
                        <form method="post" action="{{ route('waitlist.join', [$event, $type]) }}">@csrf<button class="page-button" type="submit">Встать в очередь</button></form>
                    @else
                        <a class="inline-link" href="{{ route('login') }}">Войти для записи в очередь ↗</a>
                    @endauth
                @endif
            </section>
        @endif
    @endforeach
@endif
