<?php

namespace App\Modules\Documents\Http;

use App\Http\Controllers\Controller;
use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\DocumentStore;
use App\Modules\Documents\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /** Stream a document after authorising this request (never a public URL). */
    public function show(Request $request, Document $document, AuditLogger $audit): StreamedResponse
    {
        $this->authorize('view', $document);

        if (in_array($document->classification, ['confidential', 'restricted'], true)) {
            $audit->record('document.accessed', $document, ['name' => $document->original_name]);
        }

        $inline = $request->boolean('inline') && in_array($document->mime_type, config('documents.inline'), true);

        return Storage::disk($document->disk)->response(
            $document->path,
            $document->original_name,
            [
                'Content-Type' => $document->mime_type,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
                'Cache-Control' => 'private, no-store',
            ],
            $inline ? 'inline' : 'attachment',
        );
    }

    /**
     * Upload before the owning record exists (form attachments). The
     * document stays private to the uploader until it is attached.
     */
    public function store(Request $request, DocumentStore $store): JsonResponse
    {
        $request->validate(['file' => ['required', 'file'], 'category' => ['nullable', 'string', 'max:64']]);
        $document = $store->store($request->file('file'), null, $request->user(), $request->input('category', 'attachment'));

        return response()->json($document->toSummary(), 201);
    }
}
