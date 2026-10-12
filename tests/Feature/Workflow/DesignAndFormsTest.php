<?php

namespace Tests\Feature\Workflow;

use App\Modules\Documents\DocumentStore;
use App\Modules\Workflow\Engine\WorkflowException;
use App\Modules\Workflow\Forms\FormDataValidator;
use App\Modules\Workflow\Forms\FormSchemaValidator;
use App\Modules\Workflow\Graph\GraphValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\BuildsWorkflows;
use Tests\TestCase;

/** WF-004, FORM-001..005 */
class DesignAndFormsTest extends TestCase
{
    use BuildsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganisation();
    }

    private function errors(array $nodes, array $edges): array
    {
        $result = app(GraphValidator::class)->check(
            array_map(fn ($n) => ['key' => $n[0], 'type' => $n[1], 'name' => $n[2] ?? $n[0], 'config' => $n[3] ?? []], $nodes),
            array_map(fn ($e) => ['key' => 'x', 'from_node' => $e[0], 'to_node' => $e[1], 'outcome' => $e[2] ?? 'default', 'condition' => $e[3] ?? null], $edges),
            null,
        );

        return array_column($result['errors'], 'message');
    }

    public function test_graph_validator_catches_workflows_that_could_get_stuck(): void
    {
        $hod = ['assignees' => [['type' => 'authority', 'authority' => 'HOD']]];

        $this->assertSame([], $this->errors(
            [['s', 'start'], ['a', 'approval', 'A', $hod], ['ok', 'end'], ['no', 'end', 'no', ['outcome' => 'rejected']]],
            [['s', 'a'], ['a', 'ok', 'approve'], ['a', 'no', 'reject']],
        ));

        $missingReject = $this->errors([['s', 'start'], ['a', 'approval', 'A', $hod], ['ok', 'end']], [['s', 'a'], ['a', 'ok', 'approve']]);
        $this->assertStringContainsString('no rejection connection', implode(' ', $missingReject));

        $unreachable = $this->errors([['s', 'start'], ['ok', 'end'], ['island', 'end']], [['s', 'ok']]);
        $this->assertStringContainsString('cannot be reached', implode(' ', $unreachable));

        // x and y only lead to each other: a request sent there could never finish.
        $deadEnd = $this->errors([['s', 'start'], ['d', 'decision'], ['x', 'decision'], ['y', 'decision'], ['ok', 'end']], [['s', 'd'], ['d', 'ok', 'default', ['field' => 'a', 'op' => 'eq', 'value' => 1]], ['d', 'x'], ['x', 'y'], ['y', 'x']]);
        $this->assertStringContainsString('can never reach an End', implode(' ', $deadEnd));

        $noElse = $this->errors([['s', 'start'], ['d', 'decision'], ['ok', 'end']], [['s', 'd'], ['d', 'ok', 'default', ['field' => 'a', 'op' => 'eq', 'value' => 1]]]);
        $this->assertStringContainsString('without a condition', implode(' ', $noElse));

        $badAuthority = $this->errors([['s', 'start'], ['a', 'approval', 'A', ['assignees' => [['type' => 'authority', 'authority' => 'NOPE']]]], ['ok', 'end'], ['no', 'end']], [['s', 'a'], ['a', 'ok', 'approve'], ['a', 'no', 'reject']]);
        $this->assertStringContainsString("'NOPE' does not exist", implode(' ', $badAuthority));

        $badCommittee = $this->errors([['s', 'start'], ['c', 'committee', 'C', ['committee' => 'X', 'rule' => 'quorum']], ['ok', 'end'], ['no', 'end']], [['s', 'c'], ['c', 'ok', 'approve'], ['c', 'no', 'reject']]);
        $this->assertStringContainsString('existing committee', implode(' ', $badCommittee));
        $this->assertStringContainsString('quorum rule needs', implode(' ', $badCommittee));

        $signature = $this->errors([['s', 'start'], ['sg', 'signature', 'Sign', $hod], ['ok', 'end'], ['no', 'end']], [['s', 'sg'], ['sg', 'ok', 'approve'], ['sg', 'no', 'reject']]);
        $this->assertStringContainsString('No electronic-signature provider', implode(' ', $signature), 'no fake signing');
    }

    public function test_publishing_a_broken_graph_is_refused(): void
    {
        $this->expectException(WorkflowException::class);
        $this->workflow([['start', 'start'], ['a', 'approval', 'A', ['assignees' => [['type' => 'authority', 'authority' => 'HOD']]]], ['ok', 'end']], [['start', 'a'], ['a', 'ok', 'approve']]);
    }

    public function test_form_schema_validator(): void
    {
        $v = app(FormSchemaValidator::class);
        $ok = ['sections' => [['key' => 'main', 'title' => 'Main', 'fields' => [
            ['key' => 'travel', 'type' => 'select', 'label' => 'Travel', 'options' => [['value' => 'domestic', 'label' => 'Domestic'], ['value' => 'international', 'label' => 'International']]],
            ['key' => 'country', 'type' => 'text', 'label' => 'Country', 'visible_if' => ['field' => 'travel', 'op' => 'eq', 'value' => 'international']],
            ['key' => 'items', 'type' => 'table', 'label' => 'Bills', 'columns' => [['key' => 'amount', 'type' => 'decimal', 'label' => 'Amount']]],
            ['key' => 'total', 'type' => 'calculated', 'label' => 'Total', 'formula' => 'sum(items.amount)'],
        ]]]];
        $this->assertSame([], $v->problems($ok));

        $bad = $ok;
        $bad['sections'][0]['fields'][] = ['key' => 'travel', 'type' => 'text', 'label' => 'Dup'];
        $bad['sections'][0]['fields'][] = ['key' => 'x', 'type' => 'calculated', 'label' => 'X', 'formula' => 'nope + 1'];
        $bad['sections'][0]['fields'][] = ['key' => 'y', 'type' => 'select', 'label' => 'Y', 'options' => []];
        $bad['sections'][0]['fields'][] = ['key' => 'z', 'type' => 'text', 'label' => 'Z', 'visible_if' => ['field' => 'ghost', 'op' => 'eq', 'value' => 1]];
        $problems = implode(' | ', $v->problems($bad));
        $this->assertStringContainsString('used twice', $problems);
        $this->assertStringContainsString("unknown field 'nope'", $problems);
        $this->assertStringContainsString('at least one option', $problems);
        $this->assertStringContainsString("unknown field 'ghost'", $problems);
    }

    private function schema(): array
    {
        return ['sections' => [
            ['key' => 'main', 'title' => 'Main', 'fields' => [
                ['key' => 'travel', 'type' => 'radio', 'label' => 'Travel', 'required' => true, 'options' => [['value' => 'domestic', 'label' => 'D'], ['value' => 'international', 'label' => 'I']]],
                ['key' => 'country', 'type' => 'text', 'label' => 'Country', 'required' => true, 'visible_if' => ['field' => 'travel', 'op' => 'eq', 'value' => 'international']],
                ['key' => 'advance', 'type' => 'decimal', 'label' => 'Advance', 'min' => 0, 'max' => 100000],
                ['key' => 'justification', 'type' => 'textarea', 'label' => 'Why', 'required_if' => ['field' => 'advance', 'op' => 'gt', 'value' => 50000]],
                ['key' => 'items', 'type' => 'table', 'label' => 'Bills', 'columns' => [['key' => 'what', 'type' => 'text', 'label' => 'What', 'required' => true], ['key' => 'amount', 'type' => 'decimal', 'label' => 'Amount', 'required' => true]]],
                ['key' => 'total', 'type' => 'calculated', 'label' => 'Total', 'formula' => 'sum(items.amount)'],
                ['key' => 'approved_amount', 'type' => 'decimal', 'label' => 'Approved amount', 'editable_at' => ['hod']],
                ['key' => 'bill', 'type' => 'file', 'label' => 'Bill', 'accept' => ['pdf']],
            ]],
            ['key' => 'foreign', 'title' => 'Foreign travel', 'visible_if' => ['field' => 'travel', 'op' => 'eq', 'value' => 'international'], 'fields' => [
                ['key' => 'passport', 'type' => 'text', 'label' => 'Passport', 'required' => true],
            ]],
        ]];
    }

    public function test_hidden_fields_are_neither_required_nor_kept(): void
    {
        $data = app(FormDataValidator::class)->validate($this->schema(), [
            'travel' => 'domestic', 'country' => 'Spoofed', 'passport' => 'X123', 'advance' => '100',
        ], $this->initiator);

        $this->assertSame('domestic', $data['travel']);
        $this->assertArrayNotHasKey('country', $data);
        $this->assertArrayNotHasKey('passport', $data, 'fields of a hidden section are dropped');
        $this->assertSame(100.0, $data['advance']);
    }

    public function test_conditional_requirements_are_enforced_on_the_server(): void
    {
        try {
            app(FormDataValidator::class)->validate($this->schema(), ['travel' => 'international', 'advance' => 60000], $this->initiator);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['country', 'justification', 'passport'], array_keys($e->errors()));
        }
    }

    public function test_calculated_values_are_recomputed_never_trusted(): void
    {
        $data = app(FormDataValidator::class)->validate($this->schema(), [
            'travel' => 'domestic', 'total' => 999999,
            'items' => [['what' => 'Train', 'amount' => '1200.50'], ['what' => 'Hotel', 'amount' => 3000]],
        ], $this->initiator);

        $this->assertSame(4200.5, $data['total']);
        $this->assertSame([['what' => 'Train', 'amount' => 1200.5], ['what' => 'Hotel', 'amount' => 3000.0]], $data['items']);
    }

    public function test_step_fields_are_only_editable_at_their_step(): void
    {
        $v = app(FormDataValidator::class);
        $submitted = $v->validate($this->schema(), ['travel' => 'domestic', 'approved_amount' => 5], $this->initiator);
        $this->assertNull($submitted['approved_amount'], 'the initiator cannot fill an approver field');

        $atHod = $v->validate($this->schema(), ['approved_amount' => 4000, 'travel' => 'international'], $this->hod, 'hod', $submitted, $this->initiator);
        $this->assertSame(4000.0, $atHod['approved_amount']);
        $this->assertSame('domestic', $atHod['travel'], 'other fields keep their submitted value');
    }

    public function test_files_must_be_the_submitters_own_uploads(): void
    {
        Storage::fake('private');
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n%%EOF");
        $mine = app(DocumentStore::class)->store(new UploadedFile($path, 'bill.pdf', null, null, true), null, $this->initiator);
        $theirs = app(DocumentStore::class)->store(new UploadedFile($path, 'other.pdf', null, null, true), null, $this->hod);

        $data = app(FormDataValidator::class)->validate($this->schema(), ['travel' => 'domestic', 'bill' => $mine->uuid], $this->initiator);
        $this->assertSame($mine->uuid, $data['bill']);

        $this->expectException(ValidationException::class);
        app(FormDataValidator::class)->validate($this->schema(), ['travel' => 'domestic', 'bill' => $theirs->uuid], $this->initiator);
    }
}
