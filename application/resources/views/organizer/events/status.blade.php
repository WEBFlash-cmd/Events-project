@switch($event->status)
    @case(\App\Enums\EventStatus::DRAFT)Черновик @break
    @case(\App\Enums\EventStatus::PUBLISHED)Опубликовано @break
    @case(\App\Enums\EventStatus::CANCELLED)Отменено @break
    @case(\App\Enums\EventStatus::FINISHED)Завершено @break
@endswitch
