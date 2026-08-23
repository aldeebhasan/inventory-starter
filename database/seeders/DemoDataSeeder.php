<?php

namespace Database\Seeders;

use App\Enums\LocationType;
use App\Enums\ProductType;
use App\Models\Addon;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // Brands
        $brands = collect([
            ['name' => 'Samsung', 'is_active' => true],
            ['name' => 'Apple', 'is_active' => true],
            ['name' => 'Sony', 'is_active' => true],
            ['name' => 'LG', 'is_active' => true],
            ['name' => 'Dell', 'is_active' => true],
            ['name' => 'HP', 'is_active' => false],
        ])->map(fn (array $data) => Brand::factory()->createOne($data));

        // Categories
        $categoryNames = ['Electronics', 'Accessories', 'Computers', 'Mobile Phones', 'Audio', 'Home Appliances', 'Office Supplies'];
        $categoryIds = [];
        foreach ($categoryNames as $name) {
            $categoryIds[] = Category::query()->create(['name' => $name])->getKey();
        }

        // Units (base + derived)
        $piece = Unit::factory()->create(['name' => 'Piece', 'abbreviation' => 'pc']);
        $box = Unit::factory()->create([
            'name' => 'Box',
            'abbreviation' => 'bx',
            'base_unit_id' => $piece->id,
            'conversion_factor' => 12,
        ]);
        $kg = Unit::factory()->create(['name' => 'Kilogram', 'abbreviation' => 'kg']);
        $gram = Unit::factory()->create([
            'name' => 'Gram',
            'abbreviation' => 'g',
            'base_unit_id' => $kg->id,
            'conversion_factor' => 0.001,
        ]);
        $meter = Unit::factory()->create(['name' => 'Meter', 'abbreviation' => 'm']);
        $liter = Unit::factory()->create(['name' => 'Liter', 'abbreviation' => 'L']);

        // Addons
        $addons = collect([
            ['name' => 'Extended Warranty', 'price' => 49.99, 'is_active' => true],
            ['name' => 'Gift Wrapping', 'price' => 5.00, 'is_active' => true],
            ['name' => 'Express Shipping', 'price' => 15.00, 'is_active' => true],
            ['name' => 'Installation Service', 'price' => 75.00, 'is_active' => true],
            ['name' => 'Screen Protector', 'price' => 12.50, 'is_active' => true],
            ['name' => 'Premium Support', 'price' => 99.00, 'is_active' => false],
        ])->map(fn (array $data) => Addon::factory()->create($data));

        // Locations
        $locations = collect([
            ['name' => 'Main Warehouse', 'code' => 'WH-001', 'is_active' => true, 'meta' => ['type' => LocationType::Warehouse->value]],
            ['name' => 'Secondary Warehouse', 'code' => 'WH-002', 'is_active' => true, 'meta' => ['type' => LocationType::Warehouse->value]],
            ['name' => 'Downtown Store', 'code' => 'ST-001', 'is_active' => true, 'meta' => ['type' => LocationType::Store->value]],
            ['name' => 'Mall Store', 'code' => 'ST-002', 'is_active' => true, 'meta' => ['type' => LocationType::Store->value]],
            ['name' => 'Transit Hub', 'code' => 'TR-001', 'is_active' => true, 'meta' => ['type' => LocationType::Transit->value]],
        ])->map(fn (array $data) => Location::query()->create($data));

        // Products (inventory)
        $inventoryProducts = collect([
            ['name' => 'Galaxy S24 Ultra', 'price' => 1299.99, 'cost' => 850.00, 'brand' => 0, 'unit' => $piece, 'cats' => [0, 3]],
            ['name' => 'iPhone 15 Pro', 'price' => 1199.99, 'cost' => 780.00, 'brand' => 1, 'unit' => $piece, 'cats' => [0, 3]],
            ['name' => 'MacBook Pro 16"', 'price' => 2499.99, 'cost' => 1800.00, 'brand' => 1, 'unit' => $piece, 'cats' => [0, 2]],
            ['name' => 'Dell XPS 15', 'price' => 1899.99, 'cost' => 1350.00, 'brand' => 4, 'unit' => $piece, 'cats' => [0, 2]],
            ['name' => 'Sony WH-1000XM5', 'price' => 349.99, 'cost' => 220.00, 'brand' => 2, 'unit' => $piece, 'cats' => [1, 4]],
            ['name' => 'LG OLED 55" TV', 'price' => 1499.99, 'cost' => 1050.00, 'brand' => 3, 'unit' => $piece, 'cats' => [0, 5]],
            ['name' => 'Samsung Monitor 27"', 'price' => 449.99, 'cost' => 300.00, 'brand' => 0, 'unit' => $piece, 'cats' => [0, 2]],
            ['name' => 'USB-C Cable Pack', 'price' => 19.99, 'cost' => 5.00, 'brand' => null, 'unit' => $box, 'cats' => [1, 6]],
            ['name' => 'Wireless Mouse', 'price' => 29.99, 'cost' => 12.00, 'brand' => 4, 'unit' => $piece, 'cats' => [1, 6]],
            ['name' => 'Mechanical Keyboard', 'price' => 129.99, 'cost' => 65.00, 'brand' => null, 'unit' => $piece, 'cats' => [1, 6]],
        ])->map(function (array $data) use ($brands, $categoryIds, $addons) {
            $product = Product::factory()->create([
                'name' => $data['name'],
                'price' => $data['price'],
                'cost' => $data['cost'],
                'type' => ProductType::Inventory,
                'brand_id' => $data['brand'] !== null ? $brands->get($data['brand'])?->id : null,
                'unit_id' => $data['unit']->id,
            ]);

            $product->categories()->attach(
                collect($data['cats'])->map(fn (int $i) => $categoryIds[$i])
            );

            // Attach 1-2 random addons to each product
            $product->addons()->attach(
                $addons->where('is_active', true)->random(rand(1, 2))->pluck('id')
            );

            return $product;
        });

        // Products (non-inventory)
        collect([
            ['name' => 'Software License', 'price' => 199.99, 'cost' => 0],
            ['name' => 'Consultation Service', 'price' => 150.00, 'cost' => 0],
        ])->each(function (array $data) use ($piece, $categoryIds) {
            $product = Product::factory()->nonInventory()->create([
                'name' => $data['name'],
                'price' => $data['price'],
                'cost' => $data['cost'],
                'unit_id' => $piece->id,
            ]);
            $product->categories()->attach($categoryIds[6]);
        });

        $this->command->info('Seeded: 6 brands, 7 categories, 6 units, 6 addons, 5 locations, 12 products');
    }
}
