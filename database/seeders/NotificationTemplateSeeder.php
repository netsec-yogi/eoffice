<?php

namespace Database\Seeders;

use App\Modules\Notifications\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

/**
 * Default wording for platform notifications. Administrators edit these in
 * Admin -> Notifications; the seeder only creates missing ones. SMS and
 * WhatsApp templates are created inactive: providers require registered
 * templates (DLT / WhatsApp Business) before they can be enabled.
 */
class NotificationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $key => $channels) {
            foreach ($channels as $channel => [$subject, $body]) {
                NotificationTemplate::query()->firstOrCreate(['key' => $key, 'channel' => $channel], [
                    'subject' => $subject,
                    'body' => $body,
                    'is_active' => in_array($channel, ['mail', 'in_app'], true),
                ]);
            }
        }
    }

    /** @return array<string, array<string, array{0: ?string, 1: string}>> */
    private function templates(): array
    {
        $task = 'Request {{ instance.reference }} ({{ instance.title }}) from {{ instance.initiator }} is waiting for your action at "{{ task.name }}".';

        return [
            'workflow.task_assigned' => [
                'mail' => ['Action needed: {{ instance.reference }}', "Dear {{ recipient.name }},\n\n{$task}\nDue: {{ task.due }}"],
                'in_app' => ['Action needed', $task],
                'sms' => [null, 'Action needed on {{ instance.reference }}: {{ task.name }}. Due {{ task.due }}.'],
            ],
            'workflow.task_reminder' => [
                'mail' => ['Reminder: {{ instance.reference }} is waiting for you', "Dear {{ recipient.name }},\n\nReminder: {$task}\nDue: {{ task.due }}"],
                'in_app' => ['Reminder', 'Reminder: '.$task],
            ],
            'workflow.task_escalated' => [
                'mail' => ['Escalation: {{ instance.reference }} is overdue', "Dear {{ recipient.name }},\n\nThe step \"{{ task.name }}\" of {{ instance.reference }} ({{ instance.title }}) assigned to {{ task.assignee }} was due {{ task.due }} and has been escalated to you (level {{ escalation.level }})."],
                'in_app' => ['Escalated to you', '{{ instance.reference }}: "{{ task.name }}" assigned to {{ task.assignee }} is overdue (level {{ escalation.level }}).'],
            ],
            'workflow.submitted' => [
                'mail' => ['Submitted: {{ instance.reference }}', "Dear {{ recipient.name }},\n\nYour request {{ instance.reference }} ({{ instance.title }}) has been submitted."],
                'in_app' => ['Request submitted', 'Your request {{ instance.reference }} ({{ instance.title }}) has been submitted.'],
            ],
            'workflow.returned' => [
                'mail' => ['Clarification needed: {{ instance.reference }}', "Dear {{ recipient.name }},\n\n{{ actor.name }} returned your request {{ instance.reference }} ({{ instance.title }}) for clarification:\n\n{{ remarks }}"],
                'in_app' => ['Returned for clarification', '{{ actor.name }} returned {{ instance.reference }}: {{ remarks }}'],
            ],
            'workflow.approved' => [
                'mail' => ['Approved: {{ instance.reference }}', "Dear {{ recipient.name }},\n\nYour request {{ instance.reference }} ({{ instance.title }}) has been completed with approval."],
                'in_app' => ['Request approved', '{{ instance.reference }} ({{ instance.title }}) has been approved.'],
                'sms' => [null, 'Your request {{ instance.reference }} has been approved.'],
            ],
            'workflow.rejected' => [
                'mail' => ['Not approved: {{ instance.reference }}', "Dear {{ recipient.name }},\n\nYour request {{ instance.reference }} ({{ instance.title }}) was not approved.\n\nRemarks: {{ remarks }}"],
                'in_app' => ['Request not approved', '{{ instance.reference }} was not approved: {{ remarks }}'],
            ],
            'workflow.cancelled' => [
                'mail' => ['Cancelled: {{ instance.reference }}', "Dear {{ recipient.name }},\n\nRequest {{ instance.reference }} ({{ instance.title }}) was cancelled.\n\nRemarks: {{ remarks }}"],
                'in_app' => ['Request cancelled', '{{ instance.reference }} was cancelled: {{ remarks }}'],
            ],
            'efile.received' => [
                'mail' => ['File {{ file.number }} sent to you', "Dear {{ recipient.name }},\n\n{{ sender }} has sent you file {{ file.number }} ({{ file.subject }}) {{ action }}.\n\n{{ reason }}\n\nAcknowledge receipt to take custody of it."],
                'in_app' => ['File sent to you', '{{ sender }} sent file {{ file.number }} {{ action }}.'],
            ],
            'correspondence.marked' => [
                'mail' => ['Correspondence {{ entry.number }} marked to you', "Dear {{ recipient.name }},\n\nIncoming {{ entry.kind }} {{ entry.number }} from {{ entry.from }} ({{ entry.subject }}) has been marked to you."],
                'in_app' => ['Correspondence marked to you', '{{ entry.number }} from {{ entry.from }}: {{ entry.subject }}'],
            ],
            'circular.published' => [
                'mail' => ['{{ circular.kind }} {{ circular.number }}: {{ circular.title }}', "Dear {{ recipient.name }},\n\n{{ circular.kind }} {{ circular.number }} \"{{ circular.title }}\" has been issued. {{ circular.ack }}"],
                'in_app' => ['{{ circular.kind }} issued', '{{ circular.number }}: {{ circular.title }}. {{ circular.ack }}'],
            ],
            'po.issued' => [
                'mail' => ['Purchase order {{ order.number }}', "Dear {{ recipient.name }},\n\nPurchase order {{ order.number }} for ₹{{ order.total }} has been issued to you. Download it from the vendor portal."],
                'in_app' => ['Purchase order issued', 'Order {{ order.number }} (₹{{ order.total }}) is available in the vendor portal.'],
            ],
            'official_document.ready' => [
                'mail' => ['{{ document.name }} ready: {{ document.reference }}', "Dear {{ recipient.name }},\n\nYour request {{ document.reference }} has been approved and the official document \"{{ document.name }}\" is ready. Download it from Official documents after signing in."],
                'in_app' => ['Official document ready', '{{ document.name }} for {{ document.reference }} is ready to download.'],
            ],
            'official_document.failed' => [
                'mail' => ['Official document not generated: {{ document.reference }}', "Dear {{ recipient.name }},\n\nThe official document \"{{ document.name }}\" for {{ document.reference }} could not be generated ({{ document.reason }}). The request itself remains approved. See Administration > Official documents to correct and retry."],
                'in_app' => ['Official document failed', '{{ document.name }} for {{ document.reference }}: {{ document.reason }}. Retry from the generation monitor.'],
            ],
            'invoice.paid' => [
                'mail' => ['Payment made: invoice {{ invoice.number }}', "Dear {{ recipient.name }},\n\n₹{{ invoice.net }} has been paid against your invoice {{ invoice.number }} (reference {{ invoice.reference }}). The payment advice is in the vendor portal."],
                'in_app' => ['Invoice paid', '₹{{ invoice.net }} paid against invoice {{ invoice.number }}.'],
            ],
            'efile.disposition_due' => [
                'in_app' => ['Files due for disposition', '{{ count }} closed file(s) have reached the end of their retention period.'],
            ],
            'workflow.integration_blocked' => [
                'mail' => ['Manual action needed: {{ instance.reference }}', "Dear {{ recipient.name }},\n\nThe automated step \"{{ task.name }}\" of {{ instance.reference }} could not run: {{ error }}. Please complete it manually."],
                'in_app' => ['Manual action needed', '{{ instance.reference }}: automated step "{{ task.name }}" could not run ({{ error }}).'],
            ],
        ];
    }
}
