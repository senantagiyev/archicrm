<?php

namespace App\Filament\Resources;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Filament\Resources\RoleResource\Pages;
use App\Models\Role;
use App\Support\AccessMatrix;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static string|\UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?int $navigationSort = 70;

    protected static ?string $navigationLabel = 'Rollar';

    protected static ?string $modelLabel = 'Rol';

    protected static ?string $pluralModelLabel = 'Rollar';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Governance surface, gated on the matrix rather than the raw role column —
     * otherwise the role constructor cannot govern access to itself. Only the
     * owner holds OwnerDashboard = Tam in the built-in matrix, so the six
     * standard roles behave exactly as before.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && AccessMatrix::allows($user, Domain::OwnerDashboard, AccessLevel::Full);
    }

    /**
     * The studio sees the platform-wide catalogue plus its own roles. Editing a
     * platform row from here copies it into the studio first (see EditRole), so
     * one studio's governance decisions never reach another's. Once copied, the
     * copy replaces the platform row in the list — one role, one row.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->catalogFor(auth()->user()?->tenant_id);
    }

    /** AZ labels for the access domains (TZ §5.4). */
    private static function domainLabels(): array
    {
        return [
            Domain::Clients->value => 'Müştərilər',
            Domain::Projects->value => 'Layihələr',
            Domain::Brief->value => 'Brif',
            Domain::StagesTasks->value => 'Mərhələ / Tapşırıq',
            Domain::FilesDocuments->value => 'Fayllar / Sənədlər',
            Domain::Budget->value => 'Smeta / Büdcə',
            Domain::Procurement->value => 'Komplektasiya',
            Domain::Payments->value => 'Ödənişlər',
            Domain::OwnerDashboard->value => 'Rəhbər paneli',
            Domain::Analytics->value => 'Analitika',
        ];
    }

    public static function form(Schema $form): Schema
    {
        $levelOptions = [0 => 'Yoxdur', 1 => 'Baxış', 2 => 'Redaktə', 3 => 'Tam'];

        $matrix = [];
        foreach (self::domainLabels() as $domain => $label) {
            $matrix[] = Forms\Components\Select::make("levels.{$domain}")
                ->label($label)
                ->options($levelOptions)
                ->default(0)
                ->required()
                ->native(false);
        }

        return $form->schema([
            Section::make('Rol')->columns(2)->schema([
                Forms\Components\TextInput::make('key')
                    ->label('Açar (key)')
                    ->required()
                    ->maxLength(50)
                    // Unique per studio, matching the (tenant_id, key) index. A
                    // bare unique rule blocked a studio from using a key that
                    // exists only in another studio — and refused to re-save a
                    // global role once any studio had forked it.
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule) => $rule->where('tenant_id', auth()->user()?->tenant_id),
                    )
                    // A standard role's key is how the staff enum finds it; a
                    // studio copy keeps that key or it stops replacing the role.
                    ->disabled(fn (?Role $record) => $record?->is_system || $record?->isStudioCopy())
                    // A custom role must not take a standard role's key — it
                    // would silently replace that role for every holder.
                    ->notIn(fn (?Role $record) => array_values(array_diff(Role::platformKeys(), [$record?->key])))
                    ->validationMessages([
                        'unique' => 'Bu açar artıq studiyanızın başqa rolunda istifadə olunur. Başqa açar yazın.',
                        'not_in' => 'Bu açar standart rola aiddir. Standart rolu dəyişmək üçün siyahıda həmin rolu redaktə edin.',
                    ])
                    ->helperText('Standart rollarda dəyişdirilə bilməz.'),
                Forms\Components\TextInput::make('name')
                    ->label('Ad')
                    ->required()
                    ->maxLength(120),
                Forms\Components\Toggle::make('own_projects_only')
                    ->label('Yalnız öz layihələri')
                    ->helperText('Layihə səviyyəli hüquqlar yalnız üzv olduğu layihələrə şamil olunur.'),
                Forms\Components\Toggle::make('active')
                    ->label('Aktiv')
                    ->default(true),
            ]),

            Section::make('İcazə matrisi')->columns(2)->schema($matrix),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Ad')
                    ->description(fn (Role $r) => $r->isStudioCopy()
                        ? $r->key.' · standart rol, studiyanızda dəyişdirilib'
                        : $r->key)
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_system')
                    ->label('Sistem')
                    ->tooltip('Standart (sistem) rolu: açarı dəyişmir və silinmir. Redaktə etdikdə studiyanız üçün öz versiyası saxlanılır.')
                    ->boolean(),
                Tables\Columns\IconColumn::make('own_projects_only')
                    ->label('Öz layihələri')
                    ->boolean(),
                Tables\Columns\IconColumn::make('active')
                    ->label('Aktiv')
                    ->boolean(),
            ])
            ->defaultSort('id')
            ->actions([
                Actions\EditAction::make(),
                // Platform-wide rows belong to every studio; a studio may only
                // delete a role it created itself.
                Actions\DeleteAction::make()
                    ->label(fn (Role $r) => $r->isStudioCopy() ? 'Standarta qaytar' : 'Sil')
                    ->visible(fn (Role $r) => ! $r->is_system && $r->tenant_id !== null),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
