<?php

return [
    'andalus' => [
        'name' => 'Andalus Bank',
        'base_url' => env('ANDALUS_BASE_URL', 'https://eb.anda.ly/api/v1'),
        'telegram_token' => env('ANDALUS_TELEGRAM_TOKEN'),
        'firebase_project' => env('ANDALUS_FIREBASE_PROJECT', 'andalus-neptune'),
        'firebase_api_key' => env('ANDALUS_FIREBASE_API_KEY', 'AIzaSyBs2XJGaKNOtrhnoHYnJGC-Th_75uE3GII'),
        'firebase_app_id' => env('ANDALUS_FIREBASE_APP_ID', '1:347355877973:android:36daefa0fe356193c8badc'),
    ],
    'nuran' => [
        'name' => 'Nuran Bank',
        'base_url' => env('NURAN_BASE_URL'),
        'telegram_token' => env('NURAN_TELEGRAM_TOKEN'),
        'firebase_project' => env('NURAN_FIREBASE_PROJECT', 'nuran-prod'),
        'firebase_api_key' => env('NURAN_FIREBASE_API_KEY', 'AIzaSyCpfkJOx099Qk9yg9hb-tQPQM-ATnohBfU'),
        'firebase_app_id' => env('NURAN_FIREBASE_APP_ID', '1:900819939534:android:3ef1ece59785a256aca09f'),
    ],
];
