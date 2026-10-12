<?php

// Only what differs from the package defaults: our pages live in
// resources/js/Pages (capital P), which matters on case-sensitive file
// systems for `assertInertia(fn ($p) => $p->component(...))`.
return [
    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [resource_path('js/Pages')],
        'extensions' => ['vue'],
    ],
];
