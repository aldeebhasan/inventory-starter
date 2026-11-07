<?php

use Aldeebhasan\Panorama\Facades\Panorama;
use App\Enums\SaleOrderStatus;
use App\Models\Product;
use App\Models\ReportDefinition;
use App\Models\SaleOrder;
use App\Models\SaleOrderItem;
use App\Models\TransferOrderItem;
use App\Models\User;
use App\Reports\DefinedReport;
use App\Reports\ReportSchema;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

function definedReport(array $definition, array $attributes = []): DefinedReport
{
    return new DefinedReport(ReportDefinition::factory()->create(['definition' => $definition, ...$attributes]));
}

function runDefined(DefinedReport $report, array $params = []): array
{
    return Panorama::run($report, $params, $report->definition->user)->get();
}

beforeEach(function () {
    $this->widget = Product::factory()->create(['name' => 'Widget']);
    $this->gadget = Product::factory()->create(['name' => 'Gadget']);
    $confirmed = SaleOrder::factory()->create(['status' => SaleOrderStatus::Confirmed]);
    $draft = SaleOrder::factory()->create(['status' => SaleOrderStatus::Draft]);

    SaleOrderItem::factory()->create(['sale_order_id' => $confirmed->id, 'product_id' => $this->widget->id, 'quantity' => 4, 'unit_price' => 10]);
    SaleOrderItem::factory()->create(['sale_order_id' => $confirmed->id, 'product_id' => $this->widget->id, 'quantity' => 1, 'unit_price' => 10]);
    SaleOrderItem::factory()->create(['sale_order_id' => $draft->id, 'product_id' => $this->gadget->id, 'quantity' => 7, 'unit_price' => 3]);
});

it('selects joined columns under collision-free output names', function () {
    $rows = runDefined(definedReport([
        'base' => 'sale_order_items',
        'joins' => [['relation' => 'sale_order_items.product_id>products.id', 'alias' => 'products', 'type' => 'inner']],
        'columns' => [
            ['field' => 'products.name', 'label' => 'Product', 'format' => 'text'],
            ['field' => 'sale_order_items.quantity', 'label' => null, 'format' => 'number'],
        ],
        'order_by' => [['column' => 'sale_order_items.quantity', 'direction' => 'desc']],
    ]));

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toBe(['products__name' => 'Gadget', 'sale_order_items__quantity' => 7.0]);
});

it('groups, aggregates, filters and orders', function () {
    $rows = runDefined(definedReport([
        'base' => 'sale_order_items',
        'joins' => [
            ['relation' => 'sale_order_items.product_id>products.id', 'alias' => 'products', 'type' => 'inner'],
            ['relation' => 'sale_order_items.sale_order_id>sale_orders.id', 'alias' => 'sale_orders', 'type' => 'inner'],
        ],
        'filters' => [['field' => 'sale_orders.status', 'operator' => 'in', 'value' => 'confirmed,shipped']],
        'group_by' => ['products.name'],
        'aggregates' => [
            ['fn' => 'sum', 'field' => 'sale_order_items.quantity', 'as' => 'qty'],
            ['fn' => 'count', 'field' => null, 'as' => 'lines'],
        ],
        'having' => [['column' => 'qty', 'operator' => '>', 'value' => '1']],
        'order_by' => [['column' => 'qty', 'direction' => 'desc']],
    ]));

    expect($rows)->toBe([['products__name' => 'Widget', 'qty' => 5.0, 'lines' => 2]]);
});

it('coerces filter values to the field type', function () {
    $rows = runDefined(definedReport([
        'base' => 'sale_order_items',
        'filters' => [['field' => 'sale_order_items.quantity', 'operator' => '>=', 'value' => '4']],
        'columns' => [['field' => 'sale_order_items.quantity', 'format' => 'number', 'label' => '']],
    ]));

    expect(array_column($rows, 'sale_order_items__quantity'))->toEqualCanonicalizing([4.0, 7.0]);
});

it('joins one dataset twice under aliases', function () {
    $item = TransferOrderItem::factory()->create();

    $rows = runDefined(definedReport([
        'base' => 'transfer_orders',
        'joins' => [
            ['relation' => 'transfer_orders.from_location_id>locations.id', 'alias' => 'source', 'type' => 'inner'],
            ['relation' => 'transfer_orders.to_location_id>locations.id', 'alias' => 'destination', 'type' => 'inner'],
        ],
        'columns' => [
            ['field' => 'source.name', 'label' => 'From'],
            ['field' => 'destination.name', 'label' => 'To'],
        ],
    ]));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['source__name'])->toBe($item->transferOrder->fromLocation->name)
        ->and($rows[0]['destination__name'])->toBe($item->transferOrder->toLocation->name);
});

it('requires and pushes down run-time date parameters', function () {
    Carbon::setTestNow('2020-01-01');
    SaleOrderItem::factory()->create(['product_id' => $this->widget->id]);
    Carbon::setTestNow();

    $report = definedReport(['base' => 'sale_order_items', 'parameters' => ['from', 'to']]);

    expect(fn () => runDefined($report))->toThrow(ValidationException::class)
        ->and(runDefined($report, ['from' => '2019-12-01', 'to' => '2020-01-31']))->toHaveCount(1);
});

it('authorizes the owner and shared reports only', function () {
    $private = definedReport(['base' => 'products']);
    $shared = definedReport(['base' => 'products'], ['is_shared' => true]);
    $stranger = User::factory()->create();

    expect($private->authorize($private->definition->user))->toBeTrue()
        ->and($private->authorize($stranger))->toBeFalse()
        ->and($shared->authorize($stranger))->toBeTrue()
        ->and($private->authorize(null))->toBeFalse();
});

it('maps column formats and totals', function () {
    $columns = definedReport([
        'base' => 'products',
        'columns' => [['field' => 'products.price', 'label' => 'Price', 'format' => 'money', 'total' => true]],
    ])->columns();

    expect($columns[0]->heading())->toBe('Price')
        ->and($columns[0]->currency())->toBe('USD')
        ->and($columns[0]->totalled())->toBeTrue();
});

it('reports every reason a definition cannot run', function () {
    $errors = app(ReportSchema::class)->errors([
        'base' => 'sale_order_items',
        'joins' => [
            ['relation' => 'sale_order_items.product_id>products.id', 'alias' => 'products', 'type' => 'inner'],
            ['relation' => 'nope', 'alias' => 'products', 'type' => 'inner'],
        ],
        'columns' => [['field' => 'products.missing']],
        'filters' => [['field' => 'products.price', 'operator' => 'between', 'value' => '1']],
        'aggregates' => [['fn' => 'sum', 'field' => 'products.name', 'as' => 'total']],
    ]);

    expect($errors)->toContain(
        'Join #2: choose a relation of a dataset already in the report.',
        'Join #2: the alias "products" is already used; give the second one its own alias.',
        'Aggregate #1: sum needs a numeric field, "products.name" is not.',
        'Filter on "products.price": between needs two values, separated by a comma.',
    )->and(app(ReportSchema::class)->errors(['base' => 'products']))->toBe([]);
});

it('offers relations in both directions for the next join', function () {
    $options = app(ReportSchema::class)->relationOptions(['base' => 'products'], 0);

    expect($options)->toHaveKeys(['products.brand_id>brands.id', 'products.id>sale_order_items.product_id']);
});
