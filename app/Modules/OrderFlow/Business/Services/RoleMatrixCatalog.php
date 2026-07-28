<?php

namespace App\Modules\OrderFlow\Business\Services;

class RoleMatrixCatalog
{
    public const ACTIONS = [
        'view' => 'View',
        'create' => 'Create',
        'edit_own' => 'Edit own',
        'edit_all' => 'Edit all',
        'delete' => 'Delete',
        'assign' => 'Assign',
        'approve' => 'Approve',
        'export' => 'Export',
        'override' => 'Override',
        'manage' => 'Manage',
    ];

    public const SCOPES = [
        'none' => 'None',
        'own_records' => 'Own records',
        'assigned_jobs' => 'Assigned Jobs',
        'selected_clients' => 'Selected clients',
        'department' => 'Department',
        'all_records' => 'All records',
    ];

    public const MODULES = [
        ['code' => 'dashboard', 'name' => 'Dashboard', 'group' => 'General'],
        ['code' => 'clients', 'name' => 'Clients', 'group' => 'Commercial'],
        ['code' => 'jobs', 'name' => 'Jobs', 'group' => 'Commercial'],
        ['code' => 'tasks', 'name' => 'Tasks & Checklists', 'group' => 'Operations'],
        ['code' => 'quotation', 'name' => 'Quotations', 'group' => 'Commercial'],
        ['code' => 'artwork', 'name' => 'Artwork', 'group' => 'Operations'],
        ['code' => 'sample', 'name' => 'Swatch / Sample', 'group' => 'Operations'],
        ['code' => 'production', 'name' => 'Production', 'group' => 'Operations'],
        ['code' => 'shipment', 'name' => 'Shipment', 'group' => 'Operations'],
        ['code' => 'invoice', 'name' => 'Invoice & Payment', 'group' => 'Finance'],
        ['code' => 'documents', 'name' => 'Documents', 'group' => 'Records'],
        ['code' => 'reports', 'name' => 'Reports', 'group' => 'Reporting'],
        ['code' => 'workflow', 'name' => 'Workflow Setup', 'group' => 'Administration'],
        ['code' => 'masterdata', 'name' => 'Master Data', 'group' => 'Administration'],
        ['code' => 'users', 'name' => 'Users & Access', 'group' => 'Administration'],
        ['code' => 'audit', 'name' => 'Audit Log', 'group' => 'Administration'],
    ];

    public const SENSITIVE_FIELDS = [
        ['code' => 'supplier_cost', 'name' => 'Supplier cost', 'group' => 'Commercial'],
        ['code' => 'gross_margin', 'name' => 'Gross margin', 'group' => 'Commercial'],
        ['code' => 'client_target_price', 'name' => 'Client target price', 'group' => 'Commercial'],
        ['code' => 'confirmed_selling_price', 'name' => 'Confirmed selling price', 'group' => 'Commercial'],
        ['code' => 'invoice_amount', 'name' => 'Invoice amount', 'group' => 'Restricted data'],
        ['code' => 'payment_history', 'name' => 'Payment history', 'group' => 'Restricted data'],
        ['code' => 'internal_management_notes', 'name' => 'Internal management notes', 'group' => 'Restricted data'],
        ['code' => 'client_contact_details', 'name' => 'Client contact details', 'group' => 'Restricted data'],
        ['code' => 'supplier_banking_details', 'name' => 'Supplier banking details', 'group' => 'Restricted data'],
    ];

    public function payload(): array
    {
        return [
            'modules' => self::MODULES,
            'actions' => collect(self::ACTIONS)->map(fn (string $label, string $code) => compact('code', 'label'))->values()->all(),
            'scopes' => collect(self::SCOPES)->map(fn (string $label, string $code) => compact('code', 'label'))->values()->all(),
            'sensitiveFields' => self::SENSITIVE_FIELDS,
        ];
    }

    public function moduleCodes(): array
    {
        return array_column(self::MODULES, 'code');
    }

    public function actionCodes(): array
    {
        return array_keys(self::ACTIONS);
    }

    public function scopeCodes(): array
    {
        return array_keys(self::SCOPES);
    }

    public function sensitiveFieldCodes(): array
    {
        return array_column(self::SENSITIVE_FIELDS, 'code');
    }
}
