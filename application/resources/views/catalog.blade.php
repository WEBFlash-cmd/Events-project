<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Находите мероприятия, встречи и новые впечатления в каталоге Events.">
    <title>Events — найди своё событие</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <a class="skip-link" href="#catalog">Перейти к мероприятиям</a>
    @include('partials.header')
    <main>
        <section class="hero wrap" aria-labelledby="hero-title">
            <div class="hero-copy">
                <p class="eyebrow"><span class="live-dot"></span> ВСТРЕЧИ. ОТКРЫТИЯ. ВПЕЧАТЛЕНИЯ.</p>
                <h1 id="hero-title">Хорошие планы<br>начинаются <em>здесь.</em></h1>
                <p class="hero-description">Откройте для себя события, ради которых<br class="desktop-break"> хочется выйти из дома.</p>
                <a class="hero-link" href="#catalog">Найти своё событие ↓</a>
            </div>
            <div class="hero-art" aria-hidden="true">
                <div class="orbit orbit-one"></div><div class="orbit orbit-two"></div><span class="art-spark">✳</span>
                <div class="art-ticket"><span>БИЛЕТ В НОВЫЕ ВПЕЧАТЛЕНИЯ</span><strong>Будь<br>в моменте.</strong><div class="ticket-line"></div><small>EVENTS / ВСТРЕЧАЕМСЯ ВЖИВУЮ</small><div class="barcode"></div></div>
                <span class="art-note">Больше, чем просто планы ↗</span>
            </div>
        </section>
        <section class="catalog-section" id="catalog" aria-labelledby="catalog-title">
            <div class="wrap">
                <div class="section-heading"><div><p class="eyebrow">ВАШ СЛЕДУЮЩИЙ ВЫХОД</p><h2 id="catalog-title">Афиша мероприятий<span class="brand-dot">.</span></h2></div><span class="section-note">Найдите то, что вам близко</span></div>
                <form id="filters" class="filters" role="search" aria-label="Поиск мероприятий">
                    <div class="filter-top"><label class="search-field"><span class="sr-only">Название мероприятия</span><span aria-hidden="true">⌕</span><input type="search" name="search" maxlength="255" placeholder="Название мероприятия"></label><button class="primary-button" type="submit">Найти события ↗</button></div>
                    <div class="filter-grid">
                        <label>Категория<select name="category_id"><option value="">Все категории</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
                        <label>Площадка<select name="venue_id"><option value="">Все площадки</option>@foreach($venues as $venue)<option value="{{ $venue->id }}">{{ $venue->name }}</option>@endforeach</select></label>
                        <label>Дата от<input type="date" name="date_from"></label><label>Дата до<input type="date" name="date_to"></label>
                    </div>
                </form>
                <div class="results-toolbar"><p id="result-count" role="status">Загружаем мероприятия…</p><label class="sort-label">Порядок<select name="sort" form="filters" aria-label="Сортировка"><option value="date_asc">Сначала ближайшие</option><option value="date_desc">Сначала поздние</option><option value="price_asc">Сначала дешевле</option><option value="price_desc">Сначала дороже</option></select></label><button class="text-button" type="reset" form="filters">Сбросить</button></div>
                <div id="catalog-message" class="catalog-message" hidden><h3 id="message-title"></h3><p id="message-body"></p><button id="retry" class="primary-button" type="button" hidden>Повторить загрузку</button></div>
                <div id="event-grid" class="event-grid" aria-busy="true"></div>
                <nav class="pagination" aria-label="Страницы каталога" hidden><button id="previous" class="page-button" type="button">← Назад</button><span id="page-info"></span><button id="next" class="page-button" type="button">Далее →</button></nav>
                <noscript><p class="catalog-message">Для поиска и просмотра мероприятий включите JavaScript в браузере.</p></noscript>
            </div>
        </section>
    </main>
    <footer class="site-footer wrap"><a class="brand" href="{{ route('catalog') }}">events<span class="brand-dot">.</span></a><p>Меньше рутины. Больше впечатлений.</p><span>Встречаемся на событиях ↗</span></footer>
    <dialog id="event-dialog" aria-labelledby="detail-title"><div class="dialog-header"><span class="eyebrow">О МЕРОПРИЯТИИ</span><button id="close-dialog" class="page-button" type="button" aria-label="Закрыть описание">✕</button></div><h2 id="detail-title"></h2><p id="detail-meta"></p><p id="detail-description"></p><a id="detail-checkout" class="primary-button checkout-login">Выбрать билеты ↗</a></dialog>
</body>
</html>
