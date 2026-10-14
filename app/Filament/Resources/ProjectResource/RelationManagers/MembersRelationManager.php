<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\ProjectRole;
use App\Enums\StaffRole;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MembersRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'members';

    protected static ?string $title = 'Komanda';

    protected static ?string $modelLabel = 'Üzv';

    protected static ?string $pluralModelLabel = 'Üzvlər';

    // Əvvəl başlıq atributu yox idi: Filament seçim siyahısında hər əməkdaşı
    // modelin adı ilə — «Üzv» sözü ilə — göstərirdi və kimin kim olduğu bilinmirdi.
    protected static ?string $recordTitleAttribute = 'name';

    /** Seçim siyahısındakı yazı: «Ad Soyad — rol», eyniadlı işçiləri ayırmaq üçün. */
    public static function memberOptionLabel(User $user): string
    {
        $role = $user->role?->label();

        return $role ? $user->name.' — '.$role : $user->name;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Ad, soyad')
                    ->description(fn ($record) => $record->email),
                Tables\Columns\TextColumn::make('role')
                    ->label('Sistem rolu')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (StaffRole $state) => $state->label()),
                Tables\Columns\TextColumn::make('project_role')
                    ->label('Layihədəki rol')
                    ->badge()
                    ->state(fn ($record) => ProjectRole::tryFrom($record->pivot->project_role)?->label() ?? $record->pivot->project_role),
            ])
            ->headerActions([
                Actions\AttachAction::make()
                    ->label('Üzv əlavə et')
                    ->modalHeading('Komandaya üzv əlavə et')
                    // Bir neçə əməkdaş bir dəfəyə əlavə olunur — hamısı aşağıda
                    // seçilən layihə rolunu alır. Əvvəl hər üzv üçün pəncərəni
                    // ayrıca açmaq lazım idi.
                    ->multiple()
                    ->preloadRecordSelect()
                    ->recordTitle(fn (User $record): string => static::memberOptionLabel($record))
                    ->recordSelectSearchColumns(['name', 'email'])
                    // Yalnız bu studiyanın AKTİV işçiləri. Tenant scope-u onsuz da
                    // süzür, amma açıq şərt kontekst qurulmayan halda başqa
                    // studiyanın işçisinin siyahıya düşməsinə yol vermir.
                    // Artıq üzv olanları Filament özü çıxarır (allowsDuplicates = false).
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query
                        ->where('users.is_active', true)
                        ->where('users.tenant_id', $this->getOwnerRecord()->tenant_id)
                        // Birbaşa sorğu ilə artıq üzv olanı yenidən göndərmək dublikat
                        // pivot sətri yaradardı — şərt icra mərhələsində də işləyir.
                        ->whereDoesntHave('projects', fn (Builder $project): Builder => $project->whereKey($this->getOwnerRecord()->getKey()))
                        ->orderBy('users.name'))
                    ->form(fn (Actions\AttachAction $action) => [
                        $action->getRecordSelect()
                            ->label('Əməkdaşlar')
                            ->hiddenLabel(false)
                            ->placeholder('Ad və ya e-poçt ilə axtarın')
                            ->helperText('Bir neçə əməkdaş seçə bilərsiniz — hamısı eyni layihə rolunu alacaq.'),
                        Forms\Components\Select::make('project_role')
                            ->label('Layihədəki rol')
                            ->options(collect(ProjectRole::cases())->mapWithKeys(fn ($r) => [$r->value => $r->label()]))
                            ->required()
                            ->native(false),
                    ])
                    ->successNotificationTitle('Komanda yeniləndi'),
            ])
            ->actions([
                Actions\DetachAction::make()->label('Çıxar'),
            ]);
    }
}
