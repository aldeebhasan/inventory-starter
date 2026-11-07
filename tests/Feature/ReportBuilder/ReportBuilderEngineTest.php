<?php

use App\Enums\SaleOrderStatus;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ReportDefinition;
use App\Models\SaleOrder;
use App\Models\SaleOrderItem;
use App\Models\User;
use App\Reports\DateRange;
use App\Reports\DefinitionEditor;
use App\Reports\ReportPreview;
use App\Reports\ReportSchema;
use Illuminate\Support\Carbon;

function editor(): DefinitionEditor
{
    return app(DefinitionEditor::class);
}

function preview(array $definition, ?User $user = null): array
{
    $user ??= User::factory()->create();

    return app(ReportPreview::class)->build(new ReportDefinition(['user_id' => $user->id, 'definition' => $definition]), $user);
}

describe('date range', function () {
    it('resolves presets and the previous period', function () {
        $now = Carbon::parse('2026-03-15 10:00');

        expect(DateRange::from(['preset' => 'last_7_days'], $now)->params())->toBe(['from' => '2026-03-09', 'to' => '2026-03-15'])
            ->and(DateRange::from(['preset' => 'last_7_days'], $now)->previous()->params())->toBe(['from' => '2026-03-02', 'to' => '2026-03-08'])
            ->and(DateRange::from(['preset' => 'last_month'], $now)->params())->toBe(['from' => '2026-02-01', 'to' => '2026-02-28'])
            ->and(DateRange::from(['preset' => 'all_time'], $now)->isBounded())->toBeFalse()
            ->and(DateRange::from(['preset' => 'custom', 'from' => '2026-01-31', 'to' => '2026-01-01'], $now)->params())->toBe(['from' => '2026-01-01', 'to' => '2026-01-31']);
    });
});

describe('editor', function () {
    it('starts a list report from dimensions and becomes grouped once a metric is added', function () {
        $definition = editor()->addDimension(DefinitionEditor::blank('sale_order_items'), 'sale_order_items.product_id');

        expect($definition['group_by'])->toBe([])
            ->and(array_column($definition['columns'], 'field'))->toBe(['sale_order_items.product_id']);

        $definition = editor()->addMetric($definition, 'sum', 'sale_order_items.quantity');

        expect($definition['group_by'])->toBe(['sale_order_items.product_id'])
            ->and($definition['aggregates'][0])->toMatchArray(['fn' => 'sum', 'as' => 'sum_sale_order_items_quantity'])
            ->and($definition['columns'][1])->toMatchArray(['label' => null, 'format' => 'number', 'total' => true])
            ->and(editor()->columnLabel($definition, $definition['columns'][1]))->toBe('Total quantity')
            ->and(app(ReportSchema::class)->errors($definition))->toBe([]);
    });

    it('auto-joins a related dataset and drops the join when its last field goes', function () {
        $definition = DefinitionEditor::blank('sale_order_items');
        $definition = editor()->addDimension($definition, 'name', 'sale_order_items.product_id>products.id');

        expect($definition['joins'])->toBe([['relation' => 'sale_order_items.product_id>products.id', 'alias' => 'products', 'type' => 'left']])
            ->and(editor()->dimensions($definition))->toBe(['products.name']);

        // A second join to the same dataset gets its own alias.
        $twice = editor()->addMetric($definition, 'avg', 'price', 'sale_order_items.product_id>products.id');
        expect($twice['joins'][1]['alias'])->toBe('products_2');

        $definition = editor()->removeDimension($definition, 'products.name');
        expect($definition['joins'])->toBe([]);
    });

    it('keeps a join another join goes through', function () {
        $definition = editor()->addDimension(DefinitionEditor::blank('sale_order_items'), 'name', 'sale_order_items.product_id>products.id');
        $definition = editor()->addDimension($definition, 'name', 'products.brand_id>brands.id');
        $definition = editor()->removeDimension($definition, 'products.name');

        expect(array_column($definition['joins'], 'alias'))->toBe(['products', 'brands']);
    });

    it('names a column after its metric until it is renamed', function () {
        $definition = editor()->addMetric(editor()->addDimension(DefinitionEditor::blank('sale_order_items'), 'sale_order_items.product_id'), 'sum', 'sale_order_items.quantity');
        $as = $definition['aggregates'][0]['as'];
        $label = fn (array $definition): string => editor()->columnLabel($definition, collect($definition['columns'])->firstWhere('field', $as));

        $definition = editor()->setMetricFunction($definition, $as, 'avg');
        expect($label($definition))->toBe('Average quantity');

        // A label that only named the old function, saved before labels were automatic, follows the new one too.
        $definition['columns'][1]['label'] = 'Average quantity';
        expect($label(editor()->setMetricFunction($definition, $as, 'max')))->toBe('Highest quantity');

        $definition = editor()->updateColumn($definition, $as, 'Units sold', 'number');
        expect($label(editor()->setMetricFunction($definition, $as, 'min')))->toBe('Units sold');

        $definition = editor()->updateColumn($definition, $as, '  ', 'number');
        expect($label($definition))->toBe('Average quantity');
    });

    it('regroups a date dimension by bucket', function () {
        $definition = editor()->addMetric(editor()->addDimension(DefinitionEditor::blank('sale_orders'), 'sale_orders.created_at'), 'count');
        $definition = editor()->setBucket($definition, 'sale_orders.created_at', 'month');

        expect($definition['group_by'])->toBe(['sale_orders.created_at__month'])
            ->and(editor()->columnLabel($definition, $definition['columns'][0]))->toBe('Sale Orders created at (month)');

        expect(editor()->setBucket($definition, 'sale_orders.created_at__month', null)['group_by'])->toBe(['sale_orders.created_at']);
    });

    it('turns a filter on a metric into a having condition', function () {
        $definition = editor()->addMetric(editor()->addDimension(DefinitionEditor::blank('sale_order_items'), 'sale_order_items.product_id'), 'count');
        $definition = editor()->addFilter($definition, 'count_rows', '>', '2');
        $definition = editor()->addFilter($definition, 'sale_order_items.quantity', '>=', '1');

        expect($definition['having'])->toBe([['column' => 'count_rows', 'operator' => '>', 'value' => '2']])
            ->and($definition['filters'])->toHaveCount(1);
    });

    it('toggles the sort direction', function () {
        $definition = editor()->sortBy(DefinitionEditor::blank('products'), 'products.name');

        expect(editor()->sortBy($definition, 'products.name')['order_by'])->toBe([['column' => 'products.name', 'direction' => 'desc']]);
    });

    it('needs date parameters only for a bounded range on dated data', function () {
        expect(editor()->setDateRange(DefinitionEditor::blank('sale_orders'), ['preset' => 'last_30_days'])['parameters'])->toBe(['from', 'to'])
            ->and(editor()->setDateRange(DefinitionEditor::blank('sale_orders'), ['preset' => 'all_time'])['parameters'])->toBe([])
            ->and(editor()->setDateRange(DefinitionEditor::blank('products'), ['preset' => 'last_30_days'])['parameters'])->toBe([]);
    });

    it('stops offering a relation once it is joined, and never the way back along it', function () {
        $definition = editor()->addDimension(DefinitionEditor::blank('customers'), 'order_number', 'customers.id>sale_orders.customer_id');
        $relations = array_column(editor()->fieldGroups($definition), 'relation');
        $labels = array_column(editor()->fieldGroups($definition), 'label');

        expect($relations)->not->toContain('customers.id>sale_orders.customer_id')
            ->not->toContain('sale_orders.customer_id>customers.id')
            ->toContain('sale_orders.location_id>locations.id')
            ->and(array_count_values($labels)['Sale Orders'] ?? 0)->toBe(1);
    });

    it('still offers a second, different relation to the same dataset', function () {
        $definition = editor()->addDimension(DefinitionEditor::blank('transfer_orders'), 'name', 'transfer_orders.from_location_id>locations.id');

        expect(array_column(editor()->fieldGroups($definition), 'relation'))
            ->toContain('transfer_orders.to_location_id>locations.id')
            ->not->toContain('transfer_orders.from_location_id>locations.id');
    });

    it('lists own, joined and related fields without the bucket fields', function () {
        $groups = editor()->fieldGroups(DefinitionEditor::blank('products'));

        expect($groups[0]['label'])->toBe('Products')
            ->and(array_column($groups[0]['fields'], 'name'))->toContain('price', 'created_at')->not->toContain('created_at__month')
            ->and(collect($groups[0]['fields'])->firstWhere('name', 'brand_id')['numeric'])->toBeFalse()
            ->and(collect($groups[0]['fields'])->firstWhere('name', 'price')['numeric'])->toBeTrue()
            ->and(array_column($groups, 'relation'))->toContain('products.brand_id>brands.id');
    });
});

describe('preview', function () {
    beforeEach(function () {
        $this->brand = Brand::factory()->create(['name' => 'Acme']);
        $widget = Product::factory()->create(['name' => 'Widget', 'brand_id' => $this->brand->id]);
        $gadget = Product::factory()->create(['name' => 'Gadget']);
        $order = SaleOrder::factory()->create(['status' => SaleOrderStatus::Confirmed]);
        SaleOrderItem::factory()->create(['sale_order_id' => $order->id, 'product_id' => $widget->id, 'quantity' => 4, 'unit_price' => 10]);
        SaleOrderItem::factory()->create(['sale_order_id' => $order->id, 'product_id' => $gadget->id, 'quantity' => 1, 'unit_price' => 2]);
    });

    it('builds rows, totals and a bar chart', function () {
        $definition = editor()->addDimension(DefinitionEditor::blank('sale_order_items'), 'name', 'sale_order_items.product_id>products.id');
        $definition = editor()->addMetric($definition, 'sum', 'sale_order_items.quantity');
        $definition = editor()->sortBy($definition, 'products.name');
        $definition['visualization'] = 'bar';

        $result = preview($definition);

        expect($result['error'])->toBeNull()
            ->and($result['rows'])->toBe([['Gadget', '1.00'], ['Widget', '4.00']])
            ->and($result['totals'])->toBe(['Total', '5.00'])
            ->and($result['chart'])->toBe([
                'type' => 'bar',
                'labels' => ['Gadget', 'Widget'],
                'datasets' => [['label' => 'Total quantity', 'data' => [1.0, 4.0]]],
            ]);
    });

    it('shows KPIs with the change against the previous period', function () {
        Carbon::setTestNow(now()->subDays(40));
        SaleOrderItem::factory()->create(['quantity' => 10]);
        Carbon::setTestNow();

        $definition = editor()->addMetric(DefinitionEditor::blank('sale_order_items'), 'sum', 'sale_order_items.quantity');
        $definition = editor()->setDateRange($definition, ['preset' => 'last_30_days', 'compare' => true]);
        $definition['visualization'] = 'kpi';

        $result = preview($definition);

        expect($result['error'])->toBeNull()->and($result['kpis'])->toBe([['label' => 'Total quantity', 'value' => '5.00', 'change' => -50.0]])
            ->and($result['compareRange'])->not->toBeNull();
    });

    it('caps the rows and drops totals when cut off', function () {
        SaleOrderItem::factory()->count(ReportPreview::ROW_LIMIT)->create(['product_id' => Product::first()->id]);

        $definition = editor()->addMetric(editor()->addDimension(DefinitionEditor::blank('sale_order_items'), 'sale_order_items.id'), 'count');

        $result = preview($definition);

        expect($result['rows'])->toHaveCount(ReportPreview::ROW_LIMIT)
            ->and($result['truncated'])->toBeTrue()
            ->and($result['totals'])->toBeNull();
    });

    it('explains a definition that cannot run', function () {
        expect(preview(['base' => 'products', 'columns' => [['field' => 'products.nope']]])['error'])->toContain('unknown field');
    });
});
