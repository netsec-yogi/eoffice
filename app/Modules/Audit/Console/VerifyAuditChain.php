<?php

namespace App\Modules\Audit\Console;

use App\Modules\Audit\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recompute every hash in the audit chain and report the first break.
 * Exit code 1 when the chain does not verify (scheduled daily; see SECURITY.md).
 */
class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify';

    protected $description = 'Verify the integrity of the hash-chained audit log';

    public function handle(): int
    {
        $prev = null;
        $count = 0;
        $broken = null;

        DB::table('audit_events')->orderBy('id')->chunk(1000, function ($rows) use (&$prev, &$count, &$broken) {
            foreach ($rows as $row) {
                $row = (array) $row;
                if ($row['prev_hash'] !== $prev || AuditLogger::hash($row, $prev) !== $row['hash']) {
                    $broken = $row['id'];

                    return false;
                }
                $prev = $row['hash'];
                $count++;
            }

            return true;
        });

        if ($broken !== null) {
            $this->error("Audit chain broken at event #{$broken} (after {$count} verified events).");
            logger()->critical('Audit chain verification failed', ['event_id' => $broken]);

            return self::FAILURE;
        }

        $this->info("Audit chain intact: {$count} events verified.");

        return self::SUCCESS;
    }
}
