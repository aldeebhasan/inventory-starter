<?php

namespace App\Filament\Widgets;

use Aldeebhasan\Inventorix\Enums\MovementType;
use Aldeebhasan\Inventorix\Models\Movement;
use App\Models\Product;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentMovementsWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Movement::query()
                    ->with(['stockable', 'location'])
                    ->whereHasMorph('stockable', [Product::class])
                    ->latest('created_at')
            )
            ->columns([
                TextColumn::make('stockable.name')
                    ->label('Product')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHasMorph(
                            'stockable',
                            [Product::class],
                            fn (Builder $q) => $q->where('name', 'like', "%{$search}%")
                        );
                    }),

                TextColumn::make('location.name')
                    ->label('Location'),

                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (MovementType $state): string => match ($state) {
                        MovementType::Add => 'success',
                        MovementType::Deduct => 'danger',
                    }),

                TextColumn::make('quantity')
                    ->label('Quantity')
                    ->numeric(decimalPlaces: 2),

                TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime(),
            ])
            ->paginated(false)
            ->defaultPaginationPageOption(10);
    }
}
