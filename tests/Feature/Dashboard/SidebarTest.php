<?php

use App\Filament\Pages\AbcAnalysis;
use App\Filament\Pages\SalesSummary;
use App\Filament\Pages\SupplierPerformance;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the sidebar accordion script', function () {
    $this->get('/admin')
        ->assertSuccessful()
        ->assertSee('store.toggleCollapsedGroup = function (group)', false);
});

it('renders every module as a sidebar section without separate report sections', function () {
    $response = $this->get('/admin')->assertSuccessful();

    foreach (['Catalog', 'Sales', 'Purchase', 'Inventory'] as $group) {
        $response->assertSee('data-group-label="'.$group.'"', false);
    }

    foreach (['Sales Reports', 'Purchase Reports', 'Inventory Reports'] as $group) {
        $response->assertDontSee('data-group-label="'.$group.'"', false);
    }
});

it('nests report pages under a reports item in their module', function (string $page, string $group) {
    expect($page::getNavigationGroup())->toBe($group)
        ->and($page::getNavigationParentItem())->toBe('Reports');
})->with([
    [SalesSummary::class, 'Sales'],
    [SupplierPerformance::class, 'Purchase'],
    [AbcAnalysis::class, 'Inventory'],
]);

it('renders a reports item in each module section', function () {
    $html = $this->get('/admin')->assertSuccessful()->getContent();

    expect(substr_count($html, 'fi-sidebar-reports-item'))->toBeGreaterThanOrEqual(3);
});

it('marks the reports item active when a report page is open', function () {
    $this->get(SalesSummary::getUrl())
        ->assertSuccessful()
        ->assertSee('class="fi-sidebar-item fi-sidebar-item-has-active-child-items fi-sidebar-reports-item"', false);
});
