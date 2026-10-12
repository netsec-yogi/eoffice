<?php

namespace App\Modules\OfficialDocuments\Pdf;

use RuntimeException;

/**
 * Generation cannot succeed until someone changes something (data,
 * mapping, template). Not retried automatically. `code` is shown to the
 * applicant; the message only to administrators.
 */
class GenerationException extends RuntimeException
{
    public const CODES = [
        'template_missing' => 'No published template',
        'missing_data' => 'Required data missing',
        'overflow' => 'Text does not fit its box',
        'too_many_rows' => 'More rows than the form has room for',
        'invalid_value' => 'Value cannot be formatted',
        'render_failed' => 'The PDF could not be rendered',
        'validation_failed' => 'The generated PDF failed its checks',
        'approvals_incomplete' => 'Required approvals are not recorded',
    ];

    public function __construct(public readonly string $reason, string $detail, public readonly ?string $field = null)
    {
        parent::__construct($detail);
    }
}
