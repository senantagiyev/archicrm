<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MeetingResource\Pages;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\User;
use App\Support\AccessMatrix;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

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
                    ->options(fn () => static::scopedProjectQuery()->orderBy('name')->pluck('name', 'id'))
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
                    // Bitmə başlanğıcdan əvvəl olanda görüş təqvimə mənfi
                    // uzunluqlu hadisə kimi düşürdü.
                    ->after('starts_at')
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

            // `participants` və `recording_link` sütunları əvvəldən mövcud idi və
            // proqramla yazılırdı, amma formada heç bir sahəsi yox idi — modulun
            // məqsədinin iki hissəsi (iştirakçılar və protokol) admin paneldən
            // ümumiyyətlə əlçatmaz idi.
            Section::make('İştirakçılar və protokol')->columns(2)->schema([
                Forms\Components\Select::make('participants')
                    ->label('İştirakçılar')
                    ->multiple()
                    // Ad yox, `users.id` saxlanılır: işçinin adı dəyişəndə köhnə
                    // görüşün iştirakçısı itmir.
                    ->options(fn () => static::participantOptions())
                    // `options()` closure ilə verildiyi üçün Filament avtomatik
                    // `in` qaydası ƏLAVƏ ETMİR — yəni siyahının daralması
                    // təkbaşına yalnız UI-dır. Əl ilə qurulmuş Livewire yükü
                    // (və ya gələcək API) BAŞQA STUDİYANIN `users.id`-sini
                    // iştirakçı massivinə yazdıra bilirdi: görüşdə heç vaxt
                    // adı açılmayan «#123» iştirakçı görünür və qeyd yad
                    // studiyanın identifikatoru ilə çirklənirdi. Ona görə qayda
                    // serverdə AÇIQ verilir.
                    // `nestedRecursiveRules` — qayda MASSİVİN ÖZÜNƏ deyil, hər
                    // elementinə tətbiq olunur (çoxseçimli sahədə dəyər massivdir).
                    ->nestedRecursiveRules([fn () => Rule::in(array_keys(static::participantOptions()))])
                    ->searchable()
                    ->native(false)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('recording_link')
                    ->label('Protokol / yazı keçidi')
                    ->url()
                    ->maxLength(191)
                    ->helperText('Görüşün protokolu və ya video yazısına keçid.')
                    ->columnSpanFull(),
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
                Tables\Columns\TextColumn::make('participants')
                    // `state()` (formatStateUsing yox): xam dəyər massivdir, onu
                    // olduğu kimi versək Filament hər element üçün ayrıca sətir
                    // çəkir və id-ləri göstərir. Burada hazır ad sətri qaytarılır.
                    ->state(fn (Meeting $record) => implode(', ', $record->participantNames()) ?: '—')
                    ->label('İştirakçılar')
                    ->wrap()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('recording_link')
                    ->label('Protokol')
                    ->url(fn (Meeting $record) => $record->recording_link)
                    ->openUrlInNewTab()
                    ->formatStateUsing(fn (?string $state) => filled($state) ? 'Keçid' : '—')
                    ->toggleable(),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                // Filtr də siyahı ilə eyni məhdudiyyətə tabedir: əks halda
                // «yalnız öz layihələri» rolu görmədiyi layihələrin adlarını
                // filtr siyahısında oxuyurdu.
                Tables\Filters\SelectFilter::make('project_id')
                    ->label('Layihə')
                    ->options(fn () => static::scopedProjectQuery()->orderBy('name')->pluck('name', 'id')),
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

    /**
     * Cari istifadəçinin görə bildiyi layihələr.
     *
     * Forma dropdown-u `Project::query()` idi — scope yox idi, halbuki siyahı
     * (getEloquentQuery) daraldılırdı. «Yalnız öz layihələri» rolu üzv olmadığı
     * layihəyə görüş yaradırdı, sonra isə onu nə siyahıda görürdü, nə redaktə
     * edirdi — yaradıb itirirdi. Filament Select seçilmiş dəyəri `options()`
     * siyahısına görə yoxladığı üçün bu, həm də serverdə validasiyadır.
     */
    /**
     * İştirakçı ola biləcək işçilər — həm seçim siyahısı, həm də validasiya
     * mənbəyi. İkisi BİR yerdən oxunmalıdır, əks halda siyahı daralır, qayda isə
     * köhnə qalır (məhz bu uyğunsuzluq yad studiyanın id-sinin yazılmasına
     * imkan verirdi).
     *
     * `User` `BelongsToTenant` işlədir, yəni qlobal scope sorğunu cari studiya
     * ilə onsuz da məhdudlaşdırır; burada yalnız aktivlik və sıra əlavə olunur.
     *
     * @return array<int, string>
     */
    protected static function participantOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected static function scopedProjectQuery(): Builder
    {
        $query = Project::query();
        $user = auth()->user();

        if ($user && AccessMatrix::requiresOwnProject($user)) {
            // Qruplaşdırma vacibdir: `orWhere` başqa şərtlərlə (tenant scope)
            // qarışmasın.
            $query->where(fn (Builder $project) => $project
                ->where('manager_user_id', $user->id)
                ->orWhereHas('members', fn (Builder $member) => $member->whereKey($user->id)));
        }

        return $query;
    }
}
