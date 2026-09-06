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
     */
    public static function convertToClient(Lead $lead): Client
    {
        // lead_source sərbəst mətndir; yalnız etibarlı ClientSource dəyəri olduqda
        // ötürülür, əks halda null — enum cast pozulmasın.
        $source = ClientSource::tryFrom((string) $lead->lead_source)?->value;

        $client = Client::create([
            'name' => trim("{$lead->first_name} {$lead->last_name}"),
            'company' => $lead->company,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'whatsapp' => $lead->whatsapp,
            'telegram' => $lead->telegram,
            'source' => $source,
            'status' => ClientStatus::Client->value,
            'responsible_user_id' => $lead->responsible_user_id,
            'first_contact_at' => $lead->first_contact_date,
            'notes' => $lead->notes,
        ]);

        $lead->update(['status' => LeadStatus::Won->value]);

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
