<?php

return [
    'tools' => [
        'qpdf' => env('QPDF_BINARY', 'qpdf'),
        'pdftoppm' => env('PDFTOPPM_BINARY', 'pdftoppm'),
    ],

    'max_pages' => (int) env('OFFICIAL_PDF_MAX_PAGES', 40),

    // Where generated documents are rendered before DocumentStore takes them.
    'temp_dir' => storage_path('app/private/tmp/official'),

    // Values a mapping can print as institute.*. Placeholders: the
    // administration sets them (docs/ASSUMPTIONS_AND_DECISIONS.md A-22).
    'institute' => [
        'name' => env('INSTITUTE_NAME', env('APP_NAME', '')),
        'short_name' => env('INSTITUTE_SHORT_NAME', ''),
        'address' => env('INSTITUTE_ADDRESS', ''),
        'city' => env('INSTITUTE_CITY', ''),
    ],

    // Printed where a form asks for an approval and only a recorded portal
    // approval exists. It must never read as a signature (DOC-006).
    'approval_statement' => 'Approved in the portal by :name (:designation) on :date. This is a record of approval, not a signature.',

    'date_format' => 'd/m/Y',
    'datetime_format' => 'd/m/Y H:i',

    // GNU FreeFont (bundled with mPDF) shapes Devanagari through OpenType
    // layout; DejaVu covers Latin, ₹ and the check mark.
    'fonts' => ['sans' => 'dejavusans', 'serif' => 'dejavuserif', 'mono' => 'dejavusansmono', 'devanagari' => 'freeserif'],
];
