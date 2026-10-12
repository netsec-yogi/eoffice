<?php

namespace App\Modules\Documents\Scanning;

/** No scanner configured: documents are recorded as *unscanned*, never as clean. */
class NullScanner implements MalwareScanner
{
    public function scan(string $absolutePath): string
    {
        return 'unscanned';
    }
}
