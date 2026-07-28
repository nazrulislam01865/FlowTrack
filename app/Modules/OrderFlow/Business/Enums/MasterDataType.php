<?php

namespace App\Modules\OrderFlow\Business\Enums;

enum MasterDataType: string
{
    case ProductCategory = 'product_category';
    case Product = 'product';
    case Supplier = 'supplier';
    case ProductionUnit = 'production_unit';
    case ShipmentMethod = 'shipment_method';
    case Currency = 'currency';
    case DocumentCategory = 'document_category';
    case Department = 'department';
    case Priority = 'priority';
    case TaskStatus = 'task_status';

    public function key(): string
    {
        return match ($this) {
            self::ProductCategory => 'productCategories',
            self::Product => 'products',
            self::Supplier => 'suppliers',
            self::ProductionUnit => 'productionUnits',
            self::ShipmentMethod => 'shipmentMethods',
            self::Currency => 'currencies',
            self::DocumentCategory => 'documentCategories',
            self::Department => 'departments',
            self::Priority => 'priorities',
            self::TaskStatus => 'taskStatuses',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ProductCategory => 'Product Categories',
            self::Product => 'Products',
            self::Supplier => 'Suppliers',
            self::ProductionUnit => 'Production Units',
            self::ShipmentMethod => 'Shipment Methods',
            self::Currency => 'Currencies',
            self::DocumentCategory => 'Document Categories',
            self::Department => 'Departments',
            self::Priority => 'Priorities',
            self::TaskStatus => 'Task Statuses',
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::ProductCategory => 'CAT-',
            self::Product => 'PRD-',
            self::Supplier => 'SUP-',
            self::ProductionUnit => 'PU-',
            self::ShipmentMethod => 'SHP-',
            self::Currency => 'CUR-',
            self::DocumentCategory => 'DOC-',
            self::Department => 'DEP-',
            self::Priority => 'PRI-',
            self::TaskStatus => 'TST-',
        };
    }

    public function parentType(): ?self
    {
        return $this === self::Product ? self::ProductCategory : null;
    }

    /** @return array{key:string,type:string,label:string,prefix:string,parentKey:?string,parentType:?string} */
    public function catalogEntry(): array
    {
        $parent = $this->parentType();

        return [
            'key' => $this->key(),
            'type' => $this->value,
            'label' => $this->label(),
            'prefix' => $this->prefix(),
            'parentKey' => $parent?->key(),
            'parentType' => $parent?->value,
        ];
    }
}
