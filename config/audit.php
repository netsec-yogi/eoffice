<?php

return [
    // Context keys whose values are replaced with "[redacted]" before an
    // audit event is written (matched case-insensitively, as substrings).
    'redact' => [
        'password', 'passwd', 'secret', 'token', 'otp', 'private_key', 'credential',
        'authorization', 'cookie', 'session', 'aadhaar', 'api_key',
    ],
];
