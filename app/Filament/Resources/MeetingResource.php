<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MeetingResource\Pages;
use App\Models\Meeting;
use App\Models\Project;
use App\Support\AccessMatrix;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MeetingResource extends Resource
{
    protected static ?string $model = Meeting::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'Layihələr';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Görüşlər';

    protected static ?string $modelLabel = 'Görüş';

    protected static ?string $pluralModelLabel = 'Görüşlər';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Görüş məlumatları')->columns(2)->schema([
                Forms\Components\Select::make('project_id')
                    ->label('Layihə')
                    ->options(fn () => Project::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('title')
                    ->label('Başlıq')
                    ->required()
                    ->maxLength(191),
                Forms\Components\DateTimePicker::make('starts_at')
                    ->label('Başlama vaxtı')
                    ->required()
                    ->seconds(false)
                    ->native(false),
                Forms\Components\DateTimePicker::make('ends_at')
                    ->label('Bitmə vaxtı')
                    ->seconds(false)
                    ->native(false),
                Forms\Components\TextInput::make('location')
                    ->label('Yer')
                    ->maxLength(191),
                Forms\Components\TextInput::make('online_link')
                    ->label('Onlayn keçid')
                    ->url()
                    ->maxLength(191),
            ]),

            Section::make()->schema([
                Forms\Components\Textarea::make('notes')
                    ->label('Qeydlər')
                    ->rows(3)
                    ->autosize(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('starts_at')
                    ->label('Tarix')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Layihə')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Başlıq')
                    ->searchable(),
                Tables\Columns\TextColumn::make('location')
                    ->label('Yer')
                    ->toggleable(),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Layihə')
                    ->options(fn () => Project::orderBy('name')->pluck('name', 'id')),
            ])
            ->actions([
                Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'project.name'];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMeetings::route('/'),
            'create' => Pages\CreateMeeting::route('/create'),
            'edit' => Pages\EditMeeting::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && AccessMatrix::requiresOwnProject($user)) {
            $query->whereHas('project', fn (Builder $project) => $project
                ->where('manager_user_id', $user->id)
                ->orWhereHas('members', fn (Builder $member) => $member->whereKey($user->id)));
        }

        return $query;
    }
}
