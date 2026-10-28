import './account.js';
import './checkout.js';

const form = document.querySelector('#filters');

if (form) {
    const grid = document.querySelector('#event-grid');
    const count = document.querySelector('#result-count');
    const message = document.querySelector('#catalog-message');
    const pagination = document.querySelector('.pagination');
    const retry = document.querySelector('#retry');
    const dialog = document.querySelector('#event-dialog');
    let controller;
    let currentPage = 1;
    let detailController;

    const date = (value) => {
        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? 'Дата уточняется' : new Intl.DateTimeFormat('ru-RU', {
            day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit',
        }).format(parsed);
    };

    function element(tag, className, text) {
        const node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function showMessage(title, body, canRetry = false) {
        message.hidden = false;
        document.querySelector('#message-title').textContent = title;
        document.querySelector('#message-body').textContent = body;
        retry.hidden = !canRetry;
    }

    async function showEvent(event) {
        detailController?.abort();
        const request = new AbortController();
        detailController = request;
        document.querySelector('#detail-title').textContent = event.title;
        document.querySelector('#detail-checkout').href = `/events/${event.id}`;
        document.querySelector('#detail-meta').textContent = `${date(event.starts_at)} · ${event.venue?.name ?? 'Площадка уточняется'}`;
        const description = document.querySelector('#detail-description');
        description.textContent = 'Загружаем описание…';
        dialog.showModal();
        try {
            const response = await fetch(`/api/events/${event.id}`, {
                headers: { Accept: 'application/json' }, signal: request.signal,
            });
            if (!response.ok) throw new Error();
            const { data } = await response.json();
            description.textContent = data.description || 'Организатор пока не добавил описание.';
        } catch (error) {
            if (error.name !== 'AbortError') description.textContent = 'Не удалось загрузить описание. Закройте окно и попробуйте ещё раз.';
        }
    }

    function renderEvent(event, index) {
        const card = element('article', 'event-card');
        const art = element('div', `card-art palette-${index % 4}`);
        art.setAttribute('aria-hidden', 'true');
        art.append(element('span', 'card-art-symbol', ['✳', '◎', '↗', '✦'][index % 4]));
        art.append(element('span', 'card-art-label', 'EVENTS / ВПЕЧАТЛЕНИЯ ВЖИВУЮ'));
        const body = element('div', 'card-body');
        body.append(element('span', 'category-tag', event.category?.name ?? 'Мероприятие'));
        body.append(element('p', 'event-date', date(event.starts_at)));
        body.append(element('h3', '', event.title));
        body.append(element('p', 'event-venue', event.venue?.name ?? 'Площадка уточняется'));
        const button = element('button', 'card-button', 'О мероприятии ↗');
        button.type = 'button';
        button.setAttribute('aria-label', `О мероприятии: ${event.title}`);
        button.addEventListener('click', () => showEvent(event));
        body.append(button);
        const checkout = element('a', 'event-checkout-link', 'Выбрать билеты ↗');
        checkout.href = `/events/${event.id}`;
        body.append(checkout);
        card.append(art, body);
        return card;
    }

    async function loadEvents(page = 1, updateUrl = true) {
        controller?.abort();
        const request = new AbortController();
        controller = request;
        currentPage = page;
        const params = new URLSearchParams();
        for (const [key, value] of new FormData(form)) {
            if (String(value).trim()) params.set(key, String(value).trim());
        }
        if (page > 1) params.set('page', page);
        if (updateUrl) history.replaceState(null, '', `${location.pathname}?${params}`);
        grid.replaceChildren();
        grid.setAttribute('aria-busy', 'true');
        message.hidden = true;
        pagination.hidden = true;
        count.textContent = 'Загружаем мероприятия…';
        for (let i = 0; i < 3; i++) grid.append(element('div', 'skeleton'));
        try {
            const response = await fetch(`/api/events?${params}`, {
                headers: { Accept: 'application/json' }, signal: request.signal,
            });
            const payload = await response.json();
            if (!response.ok) {
                const error = new Error(Object.values(payload.errors ?? {}).flat().join(' '));
                error.validation = response.status === 422;
                throw error;
            }
            grid.replaceChildren(...payload.data.map(renderEvent));
            count.textContent = `Найдено мероприятий: ${payload.total}`;
            currentPage = payload.current_page;
            if (!payload.data.length) showMessage('Пока ничего не нашлось', 'Попробуйте изменить запрос или сбросить фильтры.');
            pagination.hidden = payload.last_page <= 1;
            document.querySelector('#page-info').textContent = `${currentPage} из ${payload.last_page}`;
            document.querySelector('#previous').disabled = currentPage <= 1;
            document.querySelector('#next').disabled = currentPage >= payload.last_page;
        } catch (error) {
            if (error.name === 'AbortError') return;
            grid.replaceChildren();
            count.textContent = 'Не удалось получить результаты';
            showMessage(error.validation ? 'Проверьте фильтры' : 'Не удалось загрузить мероприятия',
                error.validation ? error.message : 'Проверьте соединение и попробуйте ещё раз.', !error.validation);
        } finally {
            if (!request.signal.aborted) grid.setAttribute('aria-busy', 'false');
        }
    }

    function restoreFilters() {
        const params = new URLSearchParams(location.search);
        for (const input of form.elements) {
            if (input.name) input.value = params.get(input.name) ?? (input.name === 'sort' ? 'date_asc' : '');
        }
        const page = Number(params.get('page'));
        return Number.isSafeInteger(page) && page > 0 ? page : 1;
    }

    form.addEventListener('submit', (event) => { event.preventDefault(); loadEvents(); });
    const sort = document.querySelector('[name="sort"]');
    sort.addEventListener('change', () => { if (form.reportValidity()) loadEvents(); });
    form.addEventListener('change', (event) => {
        if (event.target.name === 'date_from') form.elements.date_to.min = form.elements.date_from.value;
        if (form.reportValidity()) loadEvents();
    });
    form.addEventListener('reset', () => {
        // Defaults are restored after the browser finishes dispatching reset.
        setTimeout(() => { form.elements.date_to.min = ''; loadEvents(); }, 0);
    });
    retry.addEventListener('click', () => loadEvents(currentPage));
    document.querySelector('#previous').addEventListener('click', () => loadEvents(currentPage - 1));
    document.querySelector('#next').addEventListener('click', () => loadEvents(currentPage + 1));
    document.querySelector('#close-dialog').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => detailController?.abort());
    window.addEventListener('popstate', () => loadEvents(restoreFilters(), false));
    const initialPage = restoreFilters();
    form.elements.date_to.min = form.elements.date_from.value;
    loadEvents(initialPage, false);
}
