<?php

namespace App\Modules\Identity\Console;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Directory\DirectoryService;
use App\Modules\Identity\Directory\DirectoryUnavailable;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\UserProvisioner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Refresh every directory user. Accounts that vanished from the directory
 * are marked directory_disabled (never deleted). A safety threshold stops
 * a partial or empty directory response from locking everyone out.
 */
class SyncDirectory extends Command
{
    protected $signature = 'directory:sync {--max-disable=10 : Abort if more than this percentage of directory users would be disabled}';

    protected $description = 'Synchronise local users with the institutional directory';

    public function handle(DirectoryService $directory, UserProvisioner $provisioner, AuditLogger $audit): int
    {
        try {
            $entries = iterator_to_array($directory->all(), false);
        } catch (DirectoryUnavailable $e) {
            $this->error('Directory unavailable: '.$e->getMessage());
            $audit->record('identity.directory_sync', context: ['error' => 'directory_unavailable'], outcome: 'failure');

            return self::FAILURE;
        }

        $seen = [];
        $failed = 0;
        foreach ($entries as $entry) {
            try {
                $provisioner->sync($entry);
                $seen[] = $entry->username;
            } catch (Throwable $e) {
                $failed++;
                $this->warn("Skipped {$entry->username}: {$e->getMessage()}");
            }
        }

        $missing = User::query()->where('auth_source', 'ldap')->where('directory_disabled', false)
            ->whereNotIn('username', $seen)->pluck('id');
        $known = User::query()->where('auth_source', 'ldap')->count();
        $limit = (float) $this->option('max-disable');

        if ($known > 0 && $missing->count() / $known * 100 > $limit) {
            $this->error("Refusing to disable {$missing->count()} of {$known} users (> {$limit}%). Check the directory.");
            $audit->record('identity.directory_sync', context: ['error' => 'disable_threshold', 'would_disable' => $missing->count()], outcome: 'failure');

            return self::FAILURE;
        }

        User::query()->whereKey($missing)->update(['directory_disabled' => true, 'last_synced_at' => now()]);

        $summary = ['synced' => count($seen), 'failed' => $failed, 'disabled_missing' => $missing->count()];
        $audit->record('identity.directory_sync', context: $summary, outcome: $failed ? 'failure' : 'success');
        $this->info(json_encode($summary));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
