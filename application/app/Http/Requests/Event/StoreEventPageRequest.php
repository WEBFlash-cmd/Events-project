<?php

namespace App\Http\Requests\Event;

class StoreEventPageRequest extends StoreEventRequest
{
    use ConvertsTashkentEventDates;
}
