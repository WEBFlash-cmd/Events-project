<?php

namespace App\Http\Requests\Event;

class UpdateEventPageRequest extends UpdateEventRequest
{
    use ConvertsTashkentEventDates;
}
