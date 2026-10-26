<?php

use App\Filament\Pages\AbcAnalysis;
use App\Filament\Pages\SalesSummary;
use App\Filament\Pages\SupplierPerformance;
use App\Models\User;

beforeEach(function () {
    // Full page renders need the Vite manifest, which CI doesn't build; these tests only inspect the sidebar HTML.
    $this->withoutVite();
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

it('marks the parent module section active when a report page is open', function (string $page, string $group) {
    $html = $this->get($page::getUrl())->assertSuccessful()->getContent();

    expect($html)->toMatch('/data-group-label="'.$group.'"[^>]*class="[^"]*\bfi-active\b/');
})->with([
    [SalesSummary::class, 'Sales'],
    [SupplierPerformance::class, 'Purchase'],
    [AbcAnalysis::class, 'Inventory'],
]);

it('does not mark other module sections active when a report page is open', function () {
    $html = $this->get(SalesSummary::getUrl())->assertSuccessful()->getContent();

    expect($html)->not->toMatch('/data-group-label="(Purchase|Inventory)"[^>]*class="[^"]*\bfi-active\b/');
});
