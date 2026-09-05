<?php

namespace App\Filament\Resources\PurchaseOrders\Schemas;

use App\Models\Product;
use App\Models\Unit;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('order_number')
                    ->unique(ignoreRecord: true)
                    ->disabled()
                    ->dehydrated(),
                Select::make('supplier_id')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->required()
                    ->preload()
                    ->afterStateUpdated(function (Set $set, Get $get, $state) {
                        $items = $get('items') ?? [];
                        foreach ($items as $key => $item) {
                            $productId = $item['product_id'] ?? null;
                            if (! $productId) {
                                continue;
                            }
                            $cost = self::resolveSupplierCost((int) $productId, $state, $item['unit_id'] ?? null);
                            $set("items.{$key}.unit_cost", $cost);
                        }
                    }),
                Select::make('location_id')
                    ->relationship('location', 'name')
                    ->preload()
                    ->label('Warehouse')
                    ->searchable()
                    ->required(),
                DateTimePicker::make('ordered_at')
                    ->default(now())
                    ->required(),
                Textarea::make('notes')
                    ->nullable(),
                Repeater::make('items')
                    ->relationship()
                    ->defaultItems(1)
                    ->schema([
                        Grid::make(5)->schema([
                            Select::make('product_id')
                                ->relationship('product', 'name')
                                ->searchable()
                                ->required()
                                ->live()
                                ->columnSpan(2)
                                ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                    $product = Product::query()->find((int) $state);
                                    $set('unit_id', $product?->unit_id);

                                    if (! $product) {
                                        return;
                                    }

                                    $supplierId = $get('../../supplier_id');
                                    $set('unit_cost', self::resolveSupplierCost($product->id, $supplierId, $product->unit_id));
                                }),
                            Select::make('unit_id')
                                ->label('Unit')
                                ->options(function (Get $get): array {
                                    $product = Product::query()->find((int) $get('product_id'));
                                    if (! $product?->unit_id) {
                                        return Unit::query()->whereNull('base_unit_id')->pluck('name', 'id')->toArray();
                                    }

                                    return Unit::query()->where('id', $product->unit_id)
                                        ->orWhere('base_unit_id', $product->unit_id)
                                        ->pluck('name', 'id')
                                        ->toArray();
                                })
                                ->nullable()
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                    $productId = $get('product_id');
                                    if (! $productId) {
                                        return;
                                    }

                                    $supplierId = $get('../../supplier_id');
                                    $set('unit_cost', self::resolveSupplierCost((int) $productId, $supplierId, $state));
                                })
                                ->columnSpan(1),
                            TextInput::make('quantity')
                                ->numeric()
                                ->required()
                                ->columnSpan(1),
                            TextInput::make('unit_cost')
                                ->numeric()
                                ->nullable()
                                ->columnSpan(1),
                        ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Resolve the unit cost for a product from the supplier pivot,
     * converting if the selected unit differs from the stored unit.
     * Falls back to the product's default cost.
     */
    private static function resolveSupplierCost(int $productId, mixed $supplierId, mixed $selectedUnitId): ?float
    {
        $product = Product::query()->find($productId);
        if (! $product) {
            return null;
        }

        if (! $supplierId) {
            return $product->cost;
        }

        $supplierPivot = $product->suppliers()->where('supplier_id', (int) $supplierId)->first();

        /** @phpstan-ignore property.notFound */
        $storedCost = $supplierPivot?->pivot?->unit_cost;
        if ($storedCost === null) {
            return $product->cost;
        }

        /** @phpstan-ignore property.notFound */
        $storedUnitId = $supplierPivot->pivot->unit_id;

        if (! $selectedUnitId || ! $storedUnitId || (int) $selectedUnitId === (int) $storedUnitId) {
            return $storedCost;
        }

        $storedUnit = Unit::query()->find((int) $storedUnitId);
        $selectedUnit = Unit::query()->find((int) $selectedUnitId);

        if (! $storedUnit || ! $selectedUnit) {
            return $storedCost;
        }

        $storedFactor = $storedUnit->base_unit_id ? $storedUnit->conversion_factor : 1;
        $selectedFactor = $selectedUnit->base_unit_id ? $selectedUnit->conversion_factor : 1;

        return round($storedCost * $selectedFactor / $storedFactor, 3);
    }
}
