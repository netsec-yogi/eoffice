<?php

namespace App\Modules\EFile\Console;

use App\Modules\EFile\Models\EFile;
use App\Modules\EFile\Services\EFileService;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\NotificationDispatcher;
use Illuminate\Console\Command;

/** Marks closed files whose retention period has ended as due for disposition. Nothing is destroyed automatically. */
class ReviewRetention extends Command
{
    protected $signature = 'efiles:retention-review';

    protected $description = 'Flag closed files that have reached the end of their retention period';

    public function handle(EFileService $files, NotificationDispatcher $notifications): int
    {
        $due = EFile::query()->where('status', 'closed')->where('legal_hold', false)
            ->whereNull('disposition')->whereNotNull('retain_until')->where('retain_until', '<=', today())->get();

        foreach ($due as $file) {
            $file->forceFill(['disposition' => 'due'])->save();
            $files->event($file, 'disposition_due', null, 'Retention period ended; awaiting a records officer\'s decision');
        }
        if ($due->isNotEmpty()) {
            $notifications->send('efile.disposition_due', User::permission('efiles.records')->get(), ['count' => $due->count()], 'retention:'.today()->toDateString(), route('records.index', ['tab' => 'due'], false));
        }
        $this->info("Due for disposition: {$due->count()}");

        return self::SUCCESS;
    }
}
