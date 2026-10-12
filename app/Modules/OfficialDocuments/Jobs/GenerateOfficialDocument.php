<?php

namespace App\Modules\OfficialDocuments\Jobs;

use App\Modules\OfficialDocuments\Models\OfficialDocument;
use App\Modules\OfficialDocuments\Services\OfficialDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Background generation (DOC-004 §6.2). Idempotent: the service claims the row before working. */
class GenerateOfficialDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $timeout = 180;

    public function __construct(public int $documentId)
    {
        $this->onQueue('integrations');
    }

    public function handle(OfficialDocumentService $service): void
    {
        // A transient error leaves the row "generating"; put it back so the retry may claim it.
        OfficialDocument::query()->whereKey($this->documentId)->where('status', 'generating')->where('updated_at', '<', now()->subSeconds($this->timeout))->update(['status' => 'queued']);
        try {
            $service->generate($this->documentId);
        } catch (Throwable $e) {
            OfficialDocument::query()->whereKey($this->documentId)->where('status', 'generating')->update(['status' => 'queued', 'updated_at' => now()]);
            throw $e;
        }
    }

    /** Retries exhausted on an infrastructure error: record it for administrators. */
    public function failed(Throwable $e): void
    {
        if ($doc = OfficialDocument::query()->find($this->documentId)) {
            app(OfficialDocumentService::class)->fail($doc, 'internal', get_class($e).': '.$e->getMessage());
        }
    }
}
