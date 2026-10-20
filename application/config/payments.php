<?php

return [
    'base_url' => env('TRAINING_PS_URL', 'http://host.docker.internal:8092'),
    'cashbox_id' => (int) env('TRAINING_PS_CASHBOX_ID', 0),
    'secret' => env('TRAINING_PS_SECRET', ''),
    'timeout' => (int) env('TRAINING_PS_TIMEOUT', 20),
];
