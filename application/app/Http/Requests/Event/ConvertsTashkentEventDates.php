<?php

namespace App\Http\Requests\Event;

use DateTimeImmutable;
use DateTimeZone;

trait ConvertsTashkentEventDates
{
    public function validationData(): array
    {
        $data = parent::validationData();
        foreach (['starts_at', 'ends_at'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                continue;
            }

            $converted = null;
            $formats = in_array(strlen($data[$field]), [16, 19], true) && !str_contains($data[$field], "\0")
                ? ['Y-m-d\TH:i:s', 'Y-m-d\TH:i'] : [];
            foreach ($formats as $format) {
                $date = DateTimeImmutable::createFromFormat('!'.$format, $data[$field], new DateTimeZone('Asia/Tashkent'));
                if ($date !== false && $date->format($format) === $data[$field]) {
                    $converted = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                    break;
                }
            }
            $data[$field] = $converted ?? 'invalid-local-datetime';
        }

        // Only validation data changes, so flashed input keeps the user's local time.
        return $data;
    }
}
