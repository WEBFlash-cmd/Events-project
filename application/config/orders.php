<?php

return [
    'reservation_minutes' => max(1, (int) env('ORDER_RESERVATION_MINUTES', 15)),
];
