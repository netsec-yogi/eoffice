<?php

namespace Tests\Feature\Platform;

use App\Modules\Documents\DocumentStore;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Scanning\MalwareScanner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** SEC-004 */
class DocumentStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function pdf(string $name = 'bill.pdf'): UploadedFile
    {
        return $this->upload($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    /** A real temporary file: Laravel's fake uploads report a MIME type guessed from the name. */
    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_uploads_are_stored_privately_with_checksum_and_scan_status(): void
    {
        $user = $this->userWithRole('employee');

        $response = $this->actingAs($user)->post('/documents', ['file' => $this->pdf('My Bill <script>.pdf')])->assertCreated();

        $document = Document::query()->where('uuid', $response->json('id'))->sole();
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame('unscanned', $document->scan_status, 'no scanner configured: never claims "clean"');
        $this->assertSame(64, strlen($document->sha256));
        $this->assertStringNotContainsString('<', $document->original_name);
        $this->assertStringNotContainsString('Bill', $document->path, 'storage names are random');
        Storage::disk('private')->assertExists($document->path);
        $this->assertArrayNotHasKey('path', $response->json());
    }

    public function test_content_type_is_checked_not_the_extension(): void
    {
        $user = $this->userWithRole('employee');
        $fake = $this->upload('invoice.pdf', '<?php system($_GET["c"]); ?>');

        $this->actingAs($user)->postJson('/documents', ['file' => $fake])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame(0, Document::query()->count());
    }

    public function test_extension_must_match_the_content(): void
    {
        $user = $this->userWithRole('employee');
        $this->actingAs($user)->postJson('/documents', ['file' => $this->pdf('looks-like.png')])->assertUnprocessable();
    }

    public function test_unattached_documents_are_visible_only_to_the_uploader(): void
    {
        $owner = $this->userWithRole('employee');
        $other = $this->userWithRole('system_admin');
        $id = $this->actingAs($owner)->post('/documents', ['file' => $this->pdf()])->json('id');

        $this->actingAs($owner)->get("/documents/{$id}")->assertOk()->assertHeader('Content-Disposition');
        // Knowing the URL grants nothing - not even to an administrator.
        $this->actingAs($other)->get("/documents/{$id}")->assertForbidden();
    }

    public function test_infected_files_are_rejected_and_deleted(): void
    {
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function scan(string $absolutePath): string
            {
                return 'infected';
            }
        });
        $user = $this->userWithRole('employee');

        $this->expectException(ValidationException::class);
        try {
            app(DocumentStore::class)->store($this->pdf(), null, $user);
        } finally {
            $this->assertSame([], Storage::disk('private')->allFiles());
            $this->assertDatabaseHas('audit_events', ['action' => 'document.rejected_malware', 'outcome' => 'denied']);
        }
    }
}
