<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\InventoryStatsWidget;
use App\Filament\Widgets\RecentMovementsWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->registration()
            ->colors([
                'primary' => Color::Blue,
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->navigationGroups([
                NavigationGroup::make('Catalog'),
                NavigationGroup::make('Sales'),
                NavigationGroup::make('Purchase'),
                NavigationGroup::make('Inventory'),
            ])
            ->navigationItems([
                $this->reportsNavigationItem('Sales'),
                $this->reportsNavigationItem('Purchase'),
                $this->reportsNavigationItem('Inventory'),
            ])
            ->sidebarCollapsibleOnDesktop()
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_END, fn (): string => view('filament.sidebar-accordion')->render())
            ->maxContentWidth('full')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                InventoryStatsWidget::class,
                RecentMovementsWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->resourceCreatePageRedirect('index')
            ->resourceEditPageRedirect('index')
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * A URL-less parent item that holds a module's report pages (pages set `$navigationParentItem = 'Reports'`).
     */
    protected function reportsNavigationItem(string $group): NavigationItem
    {
        return NavigationItem::make('Reports')
            ->group($group)
            ->icon(Heroicon::OutlinedChartBar)
            ->sort(1000)
            ->extraAttributes(['class' => 'fi-sidebar-reports-item']);
    }
}
