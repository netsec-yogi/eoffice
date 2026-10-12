<?php

return [
    'disk' => env('DOCUMENTS_DISK', 'private'),

    'max_kb' => (int) env('DOCUMENTS_MAX_KB', 20480),

    // Allowed by *content* (finfo), with the extensions each may carry.
    'allowed' => [
        'application/pdf' => ['pdf'],
        'image/png' => ['png'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'text/plain' => ['txt'],
        'text/csv' => ['csv'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.oasis.opendocument.text' => ['odt'],
        'application/vnd.oasis.opendocument.spreadsheet' => ['ods'],
    ],

    // Types that may be shown inline (preview); everything else downloads.
    'inline' => ['application/pdf', 'image/png', 'image/jpeg'],

    'classifications' => ['public', 'internal', 'confidential', 'restricted'],

    // clamav | none. "none" marks documents "unscanned" (docs/SECURITY.md R-2).
    'scanner' => env('DOCUMENTS_SCANNER', 'none'),
    'clamav' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
    ],
];
