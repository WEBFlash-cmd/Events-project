@if($paginator->hasPages())
<nav class="purchase-pagination" aria-label="Страницы списка">
    @if($paginator->previousPageUrl())<a class="page-button" href="{{ $paginator->previousPageUrl() }}">← Назад</a>@endif
    <span>Страница {{ $paginator->currentPage() }} из {{ $paginator->lastPage() }}</span>
    @if($paginator->nextPageUrl())<a class="page-button" href="{{ $paginator->nextPageUrl() }}">Далее →</a>@endif
</nav>
@endif
