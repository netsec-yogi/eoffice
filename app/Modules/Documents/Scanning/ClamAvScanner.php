<?php

namespace App\Modules\Documents\Scanning;

/** clamd over TCP using the INSTREAM protocol. */
class ClamAvScanner implements MalwareScanner
{
    public function __construct(private string $host, private int $port, private int $timeout) {}

    public function scan(string $absolutePath): string
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        $file = @fopen($absolutePath, 'rb');
        if (! $socket || ! $file) {
            logger()->error('ClamAV scan could not start', ['error' => $errstr ?? 'file unreadable']);

            return 'failed';
        }

        try {
            stream_set_timeout($socket, $this->timeout);
            fwrite($socket, "zINSTREAM\0");
            while (! feof($file)) {
                $chunk = fread($file, 8192);
                if ($chunk === '' || $chunk === false) {
                    break;
                }
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fwrite($socket, pack('N', 0));
            $reply = trim((string) fgets($socket), "\0\r\n ");
        } finally {
            fclose($file);
            fclose($socket);
        }

        return match (true) {
            str_ends_with($reply, 'OK') => 'clean',
            str_ends_with($reply, 'FOUND') => 'infected',
            default => 'failed',
        };
    }
}
