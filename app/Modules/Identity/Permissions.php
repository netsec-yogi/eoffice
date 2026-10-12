<?php

namespace App\Modules\Identity;

/**
 * Every permission the platform checks, and the built-in roles. Roles are
 * stored in the database (Spatie) and editable by administrators; this map
 * is only the seed. Approval authority does NOT come from roles: it comes
 * from authorities, committees and workflow assignment.
 */
final class Permissions
{
    public const ADMIN_ACCESS = 'admin.access';

    public const USERS_VIEW = 'users.view';

    public const USERS_MANAGE = 'users.manage';

    public const ORGANISATION_MANAGE = 'organisation.manage';

    public const COMMITTEES_MANAGE = 'committees.manage';

    public const ROLES_MANAGE = 'roles.manage';

    public const AUDIT_VIEW = 'audit.view';

    public const CALENDARS_MANAGE = 'calendars.manage';

    public const NOTIFICATIONS_MANAGE = 'notifications.manage';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const DELEGATIONS_MANAGE = 'delegations.manage';     // create/revoke delegations for others

    public const WORKFLOWS_DESIGN = 'workflows.design';         // forms + workflow drafts

    public const WORKFLOWS_PUBLISH = 'workflows.publish';

    public const WORKFLOWS_MONITOR = 'workflows.monitor';       // all instances, read-only

    public const WORKFLOWS_MONITOR_DEPARTMENT = 'workflows.monitor.department';

    public const WORKFLOWS_INTERVENE = 'workflows.intervene';   // reassign/cancel others' work

    public const INTEGRATIONS_MANAGE = 'integrations.manage';

    public const CC_MANAGE = 'computer_centre.manage';             // asset register, licences, account ledger

    public const EFILES_OPEN = 'efiles.open';                       // open electronic files

    public const EFILES_RECORDS = 'efiles.records';                 // categories, retention, disposition, register

    public const EFILES_CORRESPONDENCE = 'efiles.correspondence';   // diary / dispatch registers

    public const REGISTRAR_MANAGE = 'registrar.manage';             // leave types and ledger, certificate templates and register

    public const CLAIMS_VIEW = 'claims.view';                       // claims register (finance / establishment)

    public const CIRCULARS_DRAFT = 'circulars.draft';

    public const CIRCULARS_MANAGE = 'circulars.manage';             // withdraw, archive, acknowledgment reports

    public const RTI_MANAGE = 'rti.manage';                         // RTI register

    public const BUDGET_VIEW = 'budget.view';                       // budget positions, bills, advances

    public const BUDGET_MANAGE = 'budget.manage';                   // heads, allocations, re-appropriation

    public const PROCUREMENT_MANAGE = 'procurement.manage';         // purchase cases, quotations, vendors

    public const PROCUREMENT_RULES = 'procurement.rules';           // publish procurement rule sets

    public const OFFICIAL_TEMPLATES_MANAGE = 'official_templates.manage';   // upload, map, publish official PDF templates; bind them to workflows

    public const OFFICIAL_DOCUMENTS_MANAGE = 'official_documents.manage';   // generation monitor, retry, authorised regeneration (not downloads)

    // Reading other people's official documents. Never implied by '*'
    // (see adminExcluded): a system administrator gets them only by explicit grant.
    public const OFFICIAL_DOCUMENTS_RECORDS = 'official_documents.records';           // record-keeping officers, incl. superseded documents

    public const OFFICIAL_DOCUMENTS_DEPARTMENT = 'official_documents.department';     // department staff, own department's documents

    public const OFFICIAL_DOCUMENTS_ALL = 'official_documents.all';                   // "administrators" download group

    public const OFFICIAL_DOCUMENTS_CONFIDENTIAL = 'official_documents.confidential'; // officers named for confidential processes

    /** @return list<string> */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }

    /**
     * Permissions that give access to other people's records and are
     * therefore never part of '*' (Brief: no blanket administrator access).
     *
     * @return list<string>
     */
    public static function adminExcluded(): array
    {
        return [self::OFFICIAL_DOCUMENTS_RECORDS, self::OFFICIAL_DOCUMENTS_DEPARTMENT, self::OFFICIAL_DOCUMENTS_ALL, self::OFFICIAL_DOCUMENTS_CONFIDENTIAL];
    }

    /** What '*' expands to. @return list<string> */
    public static function wildcard(): array
    {
        return array_values(array_diff(self::all(), self::adminExcluded()));
    }

    /**
     * Built-in roles => permissions. '*' = every permission.
     *
     * @return array<string, list<string>|string>
     */
    public static function roles(): array
    {
        return [
            'system_admin' => '*',
            'department_admin' => [self::ADMIN_ACCESS, self::USERS_VIEW, self::WORKFLOWS_MONITOR_DEPARTMENT],
            'workflow_designer' => [self::ADMIN_ACCESS, self::WORKFLOWS_DESIGN],
            'workflow_publisher' => [self::ADMIN_ACCESS, self::WORKFLOWS_DESIGN, self::WORKFLOWS_PUBLISH],
            'auditor' => [self::ADMIN_ACCESS, self::AUDIT_VIEW, self::WORKFLOWS_MONITOR],
            'workflow_supervisor' => [self::ADMIN_ACCESS, self::WORKFLOWS_MONITOR, self::WORKFLOWS_INTERVENE, self::DELEGATIONS_MANAGE],
            'cc_operator' => [self::CC_MANAGE],
            'records_officer' => [self::EFILES_OPEN, self::EFILES_RECORDS, self::EFILES_CORRESPONDENCE, self::OFFICIAL_DOCUMENTS_RECORDS],
            'registry_clerk' => [self::EFILES_CORRESPONDENCE],
            'registrar_staff' => [self::REGISTRAR_MANAGE, self::CLAIMS_VIEW, self::CIRCULARS_DRAFT, self::CIRCULARS_MANAGE],
            'finance_staff' => [self::CLAIMS_VIEW, self::BUDGET_VIEW],
            'rti_cell' => [self::RTI_MANAGE],
            'purchase_officer' => [self::PROCUREMENT_MANAGE, self::BUDGET_VIEW],
            'document_administrator' => [self::ADMIN_ACCESS, self::OFFICIAL_TEMPLATES_MANAGE, self::OFFICIAL_DOCUMENTS_MANAGE],
            'finance_manager' => [self::BUDGET_VIEW, self::BUDGET_MANAGE, self::PROCUREMENT_RULES, self::CLAIMS_VIEW],
            // Base roles every directory user receives by category: no permissions
            // beyond being signed in. Functional roles (registrar_staff,
            // cc_operator ...) are added by the module that needs them.
            'employee' => [self::EFILES_OPEN],
            'student' => [],
            'vendor' => [],
        ];
    }
}
