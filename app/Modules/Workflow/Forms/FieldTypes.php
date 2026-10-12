<?php

namespace App\Modules\Workflow\Forms;

/** Field types of the form builder (FORM-001). */
final class FieldTypes
{
    public const ALL = [
        'text', 'textarea', 'number', 'decimal', 'date', 'time', 'datetime', 'email', 'tel',
        'select', 'multiselect', 'radio', 'checkbox', 'table', 'file', 'files',
        'user', 'student', 'department', 'vendor', 'calculated', 'readonly', 'remark', 'signature',
    ];

    /** Types with an `options` list. */
    public const WITH_OPTIONS = ['select', 'multiselect', 'radio'];

    /** Types the initiator never types into. */
    public const NOT_INPUT = ['calculated', 'readonly', 'signature'];

    /** Types allowed as columns of a repeating table. */
    public const TABLE_COLUMNS = ['text', 'number', 'decimal', 'date', 'select', 'email', 'tel'];

    public const PEOPLE = ['user' => null, 'student' => 'student', 'vendor' => 'vendor'];
}
