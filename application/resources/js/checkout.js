const csrf = () => document.querySelector('meta[name="csrf-token"]').content;
const money = (cents) => `${new Intl.NumberFormat('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(Number(cents) / 100)} UZS`;
for (const node of document.querySelectorAll('[data-date]')) {
    const date = new Date(node.dataset.date);
    if (!Number.isNaN(date.getTime())) node.textContent = new Intl.DateTimeFormat('ru-RU', {dateStyle: 'long', timeStyle: 'short'}).format(date);
}

async function request(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin', ...options,
        headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf()},
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        if (response.status === 401) { location.reload(); throw new Error('Войдите в аккаунт.'); }
        const messages = {
            403: 'Доступ к заказу запрещён. Возможно, аккаунт заблокирован.',
            404: 'Заказ или мероприятие не найдены.',
            409: payload.message || 'Состояние заказа изменилось. Проверьте его ещё раз.',
            419: 'Сеанс истёк. Обновите страницу и войдите заново.',
            422: 'Проверьте количество билетов: остатки или условия продажи могли измениться.',
            429: 'Слишком много запросов. Подождите и повторите попытку.',
            503: 'Платёжная система временно недоступна. Повторите попытку позже.',
        };
        throw new Error(messages[response.status] ?? 'Не удалось выполнить запрос. Повторите попытку.');
    }
    return payload;
}

const checkout = document.querySelector('[data-checkout]');
if (checkout) {
    const inputs = [...checkout.querySelectorAll('[data-ticket-id]')];
    const button = checkout.querySelector('[type="submit"]');
    const feedback = checkout.querySelector('.form-feedback');
    let busy = false;
    function update() {
        let total = 0n;
        for (const input of inputs) {
            if (input.disabled || !input.validity.valid || !Number.isSafeInteger(Number(input.value))) continue;
            total += BigInt(input.dataset.price.replace('.', '')) * BigInt(input.value || 0);
        }
        document.querySelector('#checkout-total').textContent = money(total);
        button.disabled = busy || total === 0n;
    }
    inputs.forEach((input) => input.addEventListener('input', update));
    checkout.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy || !checkout.reportValidity()) return;
        const items = inputs.filter((input) => !input.disabled && Number(input.value) > 0)
            .map((input) => ({ticket_type_id: Number(input.dataset.ticketId), quantity: Number(input.value)}));
        if (!items.length) return;
        busy = true;
        button.disabled = true;
        button.textContent = 'Создаём заказ…';
        feedback.hidden = true;
        // Keep the same reference and selection for a safe retry if the response is lost.
        inputs.forEach((input) => { input.readOnly = true; });
        try {
            const data = await request(checkout.action, {
                method: 'POST', body: JSON.stringify({items, checkout_reference: checkout.elements.checkout_reference.value}),
            });
            location.assign(data.redirect);
        } catch (error) {
            feedback.textContent = error instanceof TypeError
                ? 'Соединение прервалось. Повторите отправку на этой странице: второй заказ не создастся.' : error.message;
            feedback.classList.add('is-error');
            feedback.hidden = false;
            feedback.focus();
        } finally {
            busy = false;
            inputs.forEach((input) => { input.readOnly = false; });
            button.textContent = 'Создать заказ ↗';
            update();
        }
    });
    update();
}

const order = document.querySelector('[data-order-status]');
if (order) {
    const title = document.querySelector('#payment-title');
    const description = document.querySelector('#payment-description');
    const link = document.querySelector('#payment-link');
    const refresh = document.querySelector('#refresh-order');
    const cancel = document.querySelector('#cancel-order');
    const feedback = document.querySelector('#order-feedback');
    const reservation = document.querySelector('#reservation-time');
    const tickets = document.querySelector('#order-tickets');
    let timer;
    let polling = false;
    let cancelling = false;
    let attempts = 0;
    let latest;

    function countdown() {
        if (!latest || latest.status !== 'pending' || !latest.expires_at) { reservation.hidden = true; return; }
        const seconds = Math.max(0, Math.ceil((new Date(latest.expires_at).getTime() - Date.now()) / 1000));
        reservation.hidden = false;
        reservation.textContent = seconds > 0
            ? `Резерв действует ещё ${Math.floor(seconds / 60)} мин ${String(seconds % 60).padStart(2, '0')} сек`
            : 'Срок резерва истёк. Проверяем окончательный статус заказа.';
        if (seconds === 0) link.hidden = true;
    }

    function render(data) {
        latest = data;
        let heading = 'Ожидаем подтверждение оплаты';
        let body = 'Результат обновится после уведомления от платёжной системы.';
        if (data.payment?.refund_required || data.payment?.status === 'refund_required') {
            heading = 'Зафиксирована необходимость возврата';
            body = 'По заказу отмечен возврат. Действующие билеты, если они есть, показаны ниже. Обратитесь к организатору по вопросу возврата.';
        } else if (data.status === 'paid' && data.payment?.status === 'paid') {
            heading = 'Покупка подтверждена';
            body = 'Оплата подтверждена сервером. Билеты доступны ниже.';
        } else if (data.status === 'cancelled' || data.status === 'expired') {
            heading = data.status === 'expired' ? 'Время резерва истекло' : 'Заказ отменён';
            body = 'Если хотите купить билеты, выберите их заново в афише. Поздняя оплата будет отмечена для возврата.';
        } else if (data.payment?.status === 'creation_failed' || data.payment?.status === 'failed') {
            heading = 'Не удалось завершить оплату';
            body = 'Можно отменить этот заказ и оформить новый. Если деньги уже списаны, дождитесь подтверждения сервера.';
        } else if (['created', 'creating'].includes(data.payment?.status)) {
            heading = 'Готовим ссылку на оплату';
            body = 'Заказ сохранён. Ссылка появится после ответа платёжной системы.';
        } else if (data.payment?.status === 'unknown') {
            body = 'Ответ платёжной системы не получен. Заказ сохранён; не создавайте повторный, пока проверяется результат.';
        }
        title.textContent = heading;
        description.textContent = body;
        link.hidden = true;
        link.removeAttribute('href');
        if (data.status === 'pending' && data.payment?.status === 'pending' && data.payment.payment_url) {
            try {
                const url = new URL(data.payment.payment_url);
                if (['http:', 'https:'].includes(url.protocol)) { link.href = url.href; link.hidden = false; }
            } catch { /* A malformed URL must never become a payment link. */ }
        }
        cancel.hidden = data.status !== 'pending';
        cancel.disabled = cancelling;
        const active = (data.tickets ?? []).filter((ticket) => ticket.status === 'active' && !ticket.refund_required);
        tickets.hidden = data.status !== 'paid' || !['paid', 'refund_required'].includes(data.payment?.status) || !active.length;
        const list = tickets.querySelector('ul');
        list.replaceChildren(...active.map((ticket) => {
            const li = document.createElement('li');
            const ticketLink = document.createElement('a');
            ticketLink.className = 'inline-link';
            ticketLink.href = `/tickets/${encodeURIComponent(ticket.id)}`;
            ticketLink.textContent = ticket.number;
            li.append(ticketLink);
            return li;
        }));
        countdown();
    }

    async function poll() {
        if (polling || cancelling || document.hidden) return;
        clearTimeout(timer);
        polling = true;
        refresh.disabled = true;
        cancel.disabled = true;
        feedback.hidden = true;
        try {
            const {data} = await request(order.dataset.statusUrl);
            render(data);
            attempts++;
            if (data.status === 'pending' && attempts < 24) timer = setTimeout(poll, 5000);
            else if (data.status === 'pending') {
                feedback.textContent = 'Подтверждение ещё не получено. Проверьте статус позже этой кнопкой.';
                feedback.classList.remove('is-error');
                feedback.hidden = false;
            }
        } catch (error) {
            link.hidden = true;
            feedback.textContent = error instanceof TypeError ? 'Не удалось связаться с сервером. Нажмите «Проверить статус».' : error.message;
            feedback.classList.add('is-error');
            feedback.hidden = false;
        } finally {
            polling = false;
            refresh.disabled = false;
            cancel.disabled = cancelling;
        }
    }
    refresh.addEventListener('click', () => { attempts = 0; poll(); });
    cancel.addEventListener('click', async () => {
        if (cancelling || polling) return;
        cancelling = true;
        cancel.disabled = true;
        clearTimeout(timer);
        link.hidden = true;
        let failure;
        try {
            await request(order.dataset.cancelUrl, {method: 'POST', body: '{}'});
        } catch (error) {
            failure = error.message;
        } finally {
            cancelling = false;
            cancel.disabled = false;
            await poll();
            if (failure) {
                feedback.textContent = failure;
                feedback.classList.add('is-error');
                feedback.hidden = false;
            }
        }
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) clearTimeout(timer);
        else if (attempts < 24) poll();
    });
    const ticker = setInterval(countdown, 1000);
    window.addEventListener('pagehide', () => { clearTimeout(timer); clearInterval(ticker); });
    poll();
}
