<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Banks
    |--------------------------------------------------------------------------
    |
    | Every bank the bot can talk to, keyed by the value of App\Enums\Bank.
    | The "driver" decides which App\Services\Banks\Drivers class handles the
    | bank's API; banks that share a banking platform share a driver.
    |
    | Optional per bank: "proxy" (e.g. socks5h://127.0.0.1:1080) routes that
    | bank's API traffic through a proxy, for banks that only accept Libyan
    | IPs; "timeout" is the request timeout in seconds.
    |
    */

    'banks' => [

        'andalus' => [
            'name' => 'Andalus Bank',
            'driver' => 'neptune',
            'base_url' => env('ANDALUS_BASE_URL', 'https://eb.anda.ly/api/v1'),
            'telegram_token' => env('ANDALUS_TELEGRAM_TOKEN'),
            'firebase_project' => env('ANDALUS_FIREBASE_PROJECT', 'andalus-neptune'),
            'firebase_api_key' => env('ANDALUS_FIREBASE_API_KEY', 'AIzaSyBs2XJGaKNOtrhnoHYnJGC-Th_75uE3GII'),
            'firebase_app_id' => env('ANDALUS_FIREBASE_APP_ID', '1:347355877973:android:36daefa0fe356193c8badc'),
            'build_number' => env('ANDALUS_BUILD_NUMBER', '300000'),
            'app_version' => env('ANDALUS_APP_VERSION', '1000.0.0'),
            'proxy' => env('ANDALUS_PROXY'),
        ],

        'nuran' => [
            'name' => 'Nuran Bank',
            'driver' => 'neptune',
            'base_url' => env('NURAN_BASE_URL', 'https://mobile-prod.nub.ly/api/v1'),
            'telegram_token' => env('NURAN_TELEGRAM_TOKEN'),
            'firebase_project' => env('NURAN_FIREBASE_PROJECT', 'nuran-prod'),
            'firebase_api_key' => env('NURAN_FIREBASE_API_KEY', 'AIzaSyCpfkJOx099Qk9yg9hb-tQPQM-ATnohBfU'),
            'firebase_app_id' => env('NURAN_FIREBASE_APP_ID', '1:900819939534:android:3ef1ece59785a256aca09f'),
            'build_number' => env('NURAN_BUILD_NUMBER', '300000'),
            'app_version' => env('NURAN_APP_VERSION', '1000.0.0'),
            'proxy' => env('NURAN_PROXY'),
        ],

        'jumhouria' => [
            'name' => 'Jumhouria Bank',
            'driver' => 'jumhouria',
            'cbl_code' => '002',
            'base_url' => env('JUMHOURIA_BASE_URL', 'https://musrefy-plus.mitflink.ly:20888/JUMMobileChannel/api'),
            'telegram_token' => env('JUMHOURIA_TELEGRAM_TOKEN'),
            'firebase_api_key' => env('JUMHOURIA_FIREBASE_API_KEY', 'AIzaSyBFN1lRxvFekMj9u0yJgAjlPGxfRWoLla8'),
            'app_version' => env('JUMHOURIA_APP_VERSION', '6.2.1'),
            'product_type' => env('JUMHOURIA_PRODUCT_TYPE', '100'),
            'user_agent' => env('JUMHOURIA_USER_AGENT', 'Dart/3.11 (dart:io)'),
            'verify_ssl' => env('JUMHOURIA_VERIFY_SSL', false),
            'proxy' => env('JUMHOURIA_PROXY'),
        ],

        'nab' => [
            'name' => 'North Africa Bank',
            'driver' => 'nab',
            'cbl_code' => '007',
            'base_url' => env('NAB_BASE_URL', 'https://nabmobile.nab.ly'),
            'telegram_token' => env('NAB_TELEGRAM_TOKEN'),
            'user_agent' => env('NAB_USER_AGENT', 'Dart/3.10 (dart:io)'),
            'statement_days' => env('NAB_STATEMENT_DAYS', 7),
            'proxy' => env('NAB_PROXY'),
        ],

        'atib' => [
            'name' => 'ATIB',
            'driver' => 'atib',
            'cbl_code' => '020',
            'base_url' => env('ATIB_BASE_URL', 'https://atib-connect.atib.ly:8443'),
            'telegram_token' => env('ATIB_TELEGRAM_TOKEN'),
            'auth_service_id' => env('ATIB_AUTH_SERVICE_ID', '100000002'),
            'app_key' => env('ATIB_APP_KEY', '32f139849b9d44f9e7625fb0f6d151eb'),
            'app_secret' => env('ATIB_APP_SECRET', '9166e51bb880ccbbb5f25e6015f7bf4d'),
            'mfa_service_name' => env('ATIB_MFA_SERVICE_NAME', 'SERVICE_ID_67'),
            'user_agent' => env('ATIB_USER_AGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36'),
            'transactions_limit' => env('ATIB_TRANSACTIONS_LIMIT', 50),
            'proxy' => env('ATIB_PROXY'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Institutions
    |--------------------------------------------------------------------------
    |
    | Central Bank of Libya institution codes (the 3 digits following the IBAN
    | check digits) mapped to the bank's display name. Used to resolve the
    | destination bank of an IBAN when a driver needs it.
    |
    */

    'institutions' => [
        '002' => 'مصرف الجمهورية',
        '005' => 'مصرف الوحدة',
        '007' => 'مصرف شمال أفريقيا',
        '020' => 'مصرف السراي (ATIB)',
        '024' => 'مصرف النوران',
        '027' => 'مصرف الأندلس',
    ],

];
