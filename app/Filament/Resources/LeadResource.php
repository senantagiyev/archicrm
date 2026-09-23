<?php

namespace App\Filament\Resources;

use App\Enums\ClientSource;
use App\Enums\ClientStatus;
use App\Enums\LeadStatus;
use App\Enums\StaffRole;
use App\Filament\Resources\LeadResource\Pages;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-funnel';

    protected static string|\UnitEnum|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Lidlər';

    protected static ?string $modelLabel = 'Lid';

    protected static ?string $pluralModelLabel = 'Lidlər';

    protected static ?string $recordTitleAttribute = 'first_name';

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Əsas məlumatlar')->columns(2)->schema([
                Forms\Components\TextInput::make('first_name')
                    ->label('Ad')
                    ->required()
                    ->maxLength(191),
                Forms\Components\TextInput::make('last_name')
                    ->label('Soyad')
                    ->maxLength(191),
                Forms\Components\TextInput::make('company')
                    ->label('Şirkət')
                    ->maxLength(191),
                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(collect(LeadStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                    ->default(LeadStatus::New->value)
                    ->required()
                    ->native(false),
                Forms\Components\TextInput::make('lead_source')
                    ->label('Müraciət mənbəyi')
                    ->maxLength(64),
                Forms\Components\Select::make('responsible_user_id')
                    ->label('Məsul menecer')
                    ->options(fn () => User::query()
                        ->where('is_active', true)
                        ->whereIn('role', [StaffRole::Owner->value, StaffRole::ProjectManager->value])
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->native(false),
            ]),

            Section::make('Əlaqə')->columns(2)->schema([
                Forms\Components\TextInput::make('phone')
                    ->label('Telefon')
                    ->tel()
                    ->maxLength(32),
                Forms\Components\TextInput::make('email')
                    ->label('E-poçt')
                    ->email()
                    ->maxLength(191),
                Forms\Components\TextInput::make('whatsapp')
                    ->label('WhatsApp')
                    ->maxLength(32),
                Forms\Components\TextInput::make('telegram')
                    ->label('Telegram')
                    ->maxLength(64),
            ]),

            Section::make('Layihə qiymətləndirməsi')->columns(2)->schema([
                Forms\Components\TextInput::make('estimated_project_type')
                    ->label('Ehtimal olunan layihə tipi')
                    ->maxLength(64),
                Forms\Components\TextInput::make('estimated_area')
                    ->label('Ehtimal olunan sahə (m²)')
                    ->numeric()
                    ->step('0.01'),
                Forms\Components\TextInput::make('estimated_budget')
                    ->label('Ehtimal olunan büdcə')
                    ->numeric()
                    ->step('0.01'),
                Forms\Components\DatePicker::make('first_contact_date')
                    ->label('İlk müraciət tarixi')
                    ->default(now())
                    ->native(false),
                Forms\Components\DatePicker::make('next_follow_up_date')
                    ->label('Növbəti əlaqə tarixi')
                    ->native(false),
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
                Tables\Columns\TextColumn::make('first_name')
                    ->label('Ad, soyad')
                    ->formatStateUsing(fn (Lead $r) => $r->full_name)
                    ->searchable(['first_name', 'last_name'])
                    ->sortable()
                    ->description(fn (Lead $r) => $r->company),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (LeadStatus $state) => $state->label())
                    ->color(fn (LeadStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Telefon')
                    ->searchable(),
                Tables\Columns\TextColumn::make('lead_source')
                    ->label('Mənbə')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('responsible.name')
                    ->label('Məsul')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('estimated_budget')
                    ->label('Ehtimal büdcə')
                    ->money('AZN')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('next_follow_up_date')
                    ->label('Növbəti əlaqə')
                    ->date('d.m.Y')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('responsible_user_id')
                    ->label('Məsul menecer')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(LeadStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\Action::make('convertToClient')
                    ->label('Müştəriyə çevir')
                    ->icon('heroicon-o-user-plus')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Lidi müştəriyə çevir')
                    ->modalDescription('Bu liddən yeni müştəri yaradılacaq və lidin statusu "Qazanılıb" olaraq işarələnəcək.')
                    ->visible(fn (Lead $record) => $record->status !== LeadStatus::Won)
                    ->action(function (Lead $record) {
                        $client = static::convertToClient($record);

                        // Konversiya artıq idempotentdir: təkrar çağırışda yeni
                        // sətir yaranmır, mövcud müştəri qaytarılır. Operator bunu
                        // bilməlidir — əks halda «düymə işləmədi» deyə yenidən basır.
                        if (! $client->wasRecentlyCreated) {
                            Notification::make()
                                ->title('Bu lid artıq çevrilib')
                                ->body("Lid \"{$client->name}\" müştərisinə bağlıdır — ikinci müştəri yaradılmadı.")
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Müştəri yaradıldı')
                            ->body("\"{$client->name}\" müştəri kimi əlavə olundu.")
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([]);
    }

    /**
     * Bir liddən müştəri yaradır və lidin statusunu "Qazanılıb" edir.
     *
     * İdempotentdir: eyni lid ikinci dəfə çevriləndə yeni sətir yaranmır, artıq
     * bağlanmış müştəri qaytarılır. Çağıran tərəf `wasRecentlyCreated` ilə iki
     * halı ayırd edir.
     */
    public static function convertToClient(Lead $lead): Client
    {
        // Təkrar konversiyanın qarşısı. `status` adi fillable sahədir — operator
        // "Qazanılıb"-ı geri çevirəndə düymə yenidən görünür, əvvəllər isə bu,
        // `clients` cədvəlində eyni adlı ikinci sətir demək idi. İndi maneə
        // statusda yox, `leads.client_id` izindədir.
        if ($lead->client_id) {
            $existing = Client::find($lead->client_id);

            if ($existing) {
                // Lid onsuz da çevrilib: status əl ilə geri çevrilmişdisə,
                // həqiqətə uyğun vəziyyətə qaytarılır — amma YENİ müştəri yox.
                if ($lead->status !== LeadStatus::Won) {
                    $lead->forceFill(['status' => LeadStatus::Won->value])->save();
                }

                return $existing;
            }

            // Bağlı müştəri silinibsə, lid dalanda qalmamalıdır: aşağıda yenisi
            // yaradılır və iz yenilənir.
        }

        // lead_source sərbəst mətndir, ClientSource isə enum. Əvvəllər uyğun
        // gəlməyən mənbə (məs. "tiktok-reklam") səssizcə `null`-a düşürdü və
        // marketinq atribusiyası tamamilə itirdi.
        $source = ClientSource::tryFrom((string) $lead->lead_source);
        $notes = $lead->notes;

        if (! $source && filled($lead->lead_source)) {
            // Enum-a hər yeni kanal üçün case əlavə etmirik: sərbəst mətn
            // mənbələri sonsuzdur, enum isə hesabat filtrinin lüğətidir. Ona görə
            // `other` seçilir (müştəri «mənbəsiz» qalmır), orijinal mətn isə
            // qeydin başına yazılır ki, atribusiya insan üçün itməsin.
            $source = ClientSource::Other;
            $notes = trim('Lid mənbəyi: '.$lead->lead_source.(filled($notes) ? PHP_EOL.$notes : ''));
        }

        $client = Client::create([
            'name' => trim("{$lead->first_name} {$lead->last_name}"),
            'company' => $lead->company,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'whatsapp' => $lead->whatsapp,
            'telegram' => $lead->telegram,
            'source' => $source?->value,
            'status' => ClientStatus::Client->value,
            'responsible_user_id' => $lead->responsible_user_id,
            'first_contact_at' => $lead->first_contact_date,
            'notes' => $notes,
        ]);

        // `client_id` `Lead::$fillable`-da deyil (iz texniki sahədir, formadan
        // doldurulmur), ona görə `forceFill` ilə yazılır.
        $lead->forceFill([
            'client_id' => $client->id,
            'status' => LeadStatus::Won->value,
        ])->save();

        return $client;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'company', 'phone', 'email'];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeads::route('/'),
            'create' => Pages\CreateLead::route('/create'),
            'edit' => Pages\EditLead::route('/{record}/edit'),
        ];
    }
}
