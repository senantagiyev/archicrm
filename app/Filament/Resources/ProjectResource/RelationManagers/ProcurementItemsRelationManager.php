<?php

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Enums\ApprovalStatus;
use App\Enums\PurchaseStatus;
use App\Enums\SelectionStatus;
use App\Exports\ProcurementExport;
use App\Filament\Concerns\OptimisticLock;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\PurchaseOrderResource;
use App\Models\BudgetLine;
use App\Models\Payment;
use App\Models\ProcurementItem;
use App\Models\PurchaseOrder;
use App\Rules\SafeUpload;
use App\Services\Approvals\ApprovalService;
use App\Services\Finance\ProcurementValueService;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

/**
 * «Komplektasiya» — Spesifikasiyalar ilə birləşdirilmiş vahid bölmə (TZ
 * «Layihə naviqasiyası» §7). Material, mebel və avadanlıq pozisiyaları,
 * xüsusiyyətləri, miqdar/vahid, təchizatçı, seçim/razılaşma/satınalma
 * statusları, şəkil və texniki sənədlər pozisiyanın ÖZÜNDƏDİR.
 *
 * Üç dəyər göstəricisi AYRI-AYRI göstərilir və mənbəyi yazılır
 * (`ProcurementValueService`): plan dəyəri (bu forma), sifariş dəyəri
 * (satınalma sifarişinin sətirləri), faktiki ödəniş (mühasibatlıq). Formadakı
 * qiymətin dəyişməsi sifariş və ödəniş rəqəmlərinə toxunmur.
 */
class ProcurementItemsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'procurementItems';

    protected static ?string $title = 'Komplektasiya';

    protected static ?string $modelLabel = 'Pozisiya';

    /** Livewire bunu sorğular arasında saxlamır — hər render təzə rəqəm oxuyur. */
    protected ?ProcurementValueService $values = null;

    public function form(Schema $form): Schema
    {
        return $form->schema([
            Forms\Components\FileUpload::make('photo_path')
                ->label('Foto')
                ->image()
                ->imageEditor()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(5120)
                ->rules([SafeUpload::image()])
                ->disk('public')
                ->directory('procurement')
                ->openable()
                ->downloadable()
                ->columnSpanFull(),
            Forms\Components\TextInput::make('name')
                ->label('Ad')
                ->required()
                ->maxLength(191),
            Forms\Components\TextInput::make('sku')
                ->label('Artikul')
                ->maxLength(64),
            Forms\Components\TextInput::make('brand')
                ->label('Brend')
                ->maxLength(191),
            Forms\Components\TextInput::make('model')
                ->label('Model')
                ->maxLength(191),
            Forms\Components\TextInput::make('category')
                ->label('Kateqoriya')
                ->placeholder('Mebel / İşıqlandırma / Santexnika ...')
                ->maxLength(191),
            Forms\Components\TextInput::make('room')
                ->label('Otaq')
                ->maxLength(191),
            Forms\Components\Textarea::make('characteristics')
                ->label('Xüsusiyyətlər')
                ->placeholder('Ölçülər, material, rəng, texniki parametrlər ...')
                ->rows(3)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('qty')
                ->label('Miqdar')
                ->numeric()
                ->default(1)
                ->minValue(0)
                ->required(),
            Forms\Components\TextInput::make('unit')
                ->label('Ölçü vahidi')
                ->placeholder('ədəd / m² / m')
                ->maxLength(32),
            Forms\Components\TextInput::make('price')
                ->label('Plan qiyməti (vahid)')
                ->helperText('Seçim üçün planlaşdırılan qiymət. Sifariş dəyərini və faktiki ödənişi dəyişmir.')
                ->numeric()
                ->required()
                ->minValue(0)
                ->suffix('₼'),
            Forms\Components\TextInput::make('supplier_cost')
                ->label('Təchizatçı mayası (daxili)')
                ->helperText('Müştəriyə heç vaxt göstərilmir.')
                ->numeric()
                ->minValue(0)
                ->suffix('₼'),
            Forms\Components\TextInput::make('reserve_percent')
                ->label('Ehtiyat %')
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%')
                ->helperText('Zədə/itki üçün ehtiyat faizi.'),
            Forms\Components\TextInput::make('delivery_assembly_price')
                ->label('Çatdırılma / yığılma')
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->suffix('₼'),
            Forms\Components\TextInput::make('store')
                ->label('Mağaza / təchizatçı')
                ->maxLength(191),
            Forms\Components\TextInput::make('url')
                ->label('Link')
                ->url()
                ->maxLength(191),
            Forms\Components\TextInput::make('attachment_url')
                ->label('Sənəd/əlavə linki')
                ->url()
                ->maxLength(191),
            // Pozisiyanın şəkilləri və texniki sənədləri BİR DƏFƏ burada
            // yüklənir və pozisiyadan açılır — başqa bölməyə təkrar yüklənmir.
            Forms\Components\FileUpload::make('attachments')
                ->label('Şəkillər və texniki sənədlər')
                ->multiple()
                ->maxFiles(20)
                ->acceptedFileTypes(['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(20480)
                ->rules([SafeUpload::document()])
                ->disk('public')
                ->directory('procurement/attachments')
                ->openable()
                ->downloadable()
                ->reorderable()
                ->columnSpanFull(),
            // Smeta ilə AÇIQ əlaqə: sətrin tərkibi və dəyəri smetada qalır,
            // burada yalnız istinad saxlanır — dublikat yaranmır.
            Forms\Components\Select::make('budget_line_id')
                ->label('Smeta sətri')
                ->options(fn () => $this->budgetLineOptions())
                ->in(fn () => array_keys($this->budgetLineOptions()))
                ->searchable()
                ->native(false)
                ->visible(fn () => auth()->user()?->can('viewAny', BudgetLine::class))
                ->columnSpanFull(),
            Forms\Components\Select::make('selection_status')
                ->label('Seçim statusu')
                ->options(collect(SelectionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                ->default(SelectionStatus::Draft->value)
                ->native(false),
            Forms\Components\Select::make('purchase_status')
                ->label('Satınalma / çatdırılma statusu')
                ->options(collect(PurchaseStatus::cases())
                    ->reject(fn ($s) => $s === PurchaseStatus::Cancelled)
                    ->mapWithKeys(fn ($s) => [$s->value => $s->label()]))
                ->default(PurchaseStatus::Planned->value)
                ->native(false),
            Forms\Components\DatePicker::make('delivery_date')
                ->label('Çatdırılma tarixi')
                ->native(false),
            Forms\Components\Toggle::make('paid')
                ->label('Ödənilib (işarə)')
                ->helperText('Əl ilə qoyulan işarə. Faktiki ödəniş məbləği mühasibatlıqdakı ödəniş qeydlərindən gəlir.')
                ->inline(false),
            // `procurement_items.visible_to_client` DB-də `false` defoltludur və
            // paneldə heç bir sahəsi YOX idi: yəni paneldən yaradılan pozisiya
            // müştəriyə heç vaxt açıla bilmirdi və portalın bütün Komplektasiya
            // bölməsi (siyahı, CSV ixracı, avtorizasiyalı foto marşrutu) boş
            // qalırdı. Açar məhz burada — pozisiyanın öz formasında — olmalıdır.
            Forms\Components\Toggle::make('visible_to_client')
                ->label('Sifarişçiyə görünür')
                ->helperText('Açıq olduqda pozisiya müştəri portalının Komplektasiya bölməsində görünür.')
                ->inline(false),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            // Policy hər sətir üçün `$record->project`-i oxuyur; faktiki ödəniş
            // cəmi isə sətir başına sorğu olmasın deyə əvvəlcədən yüklənir.
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['project', 'budgetLine'])
                ->withSum(['payments as paid_payments_total' => fn (Builder $payments) => $payments->where('status', 'paid')], 'amount'))
            ->columns([
                Tables\Columns\ImageColumn::make('photo_path')
                    ->label('Foto')
                    ->disk('public')
                    ->square(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Ad')
                    ->searchable()
                    ->description(fn (ProcurementItem $record) => trim(implode(' ', array_filter([$record->brand, $record->model, $record->sku]))) ?: null)
                    ->wrap(),
                Tables\Columns\TextColumn::make('category')
                    ->label('Kateqoriya')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('room')
                    ->label('Otaq')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('qty')
                    ->label('Miqdar')
                    ->formatStateUsing(fn ($state, ProcurementItem $record) => rtrim(rtrim(number_format((float) $state, 2, '.', ' '), '0'), '.')
                        .($record->unit ? ' '.$record->unit : '')),
                Tables\Columns\TextColumn::make('price')
                    ->label('Plan qiyməti')
                    ->money('AZN'),
                // ── Üç ayrı göstərici ─────────────────────────────────────
                Tables\Columns\TextColumn::make('total')
                    ->label('Plan dəyəri')
                    ->money('AZN')
                    ->weight('bold')
                    ->description('Mənbə: komplektasiya')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money('AZN')->label('Cəm')),
                Tables\Columns\TextColumn::make('ordered_total')
                    ->label('Sifariş dəyəri')
                    ->state(fn (ProcurementItem $record) => $this->values()->orderedTotal($record))
                    ->money('AZN')
                    ->placeholder('Sifariş yoxdur')
                    ->description(fn (ProcurementItem $record) => $this->orderSourceLabel($record))
                    ->url(fn (ProcurementItem $record) => $this->orderSourceUrl($record)),
                Tables\Columns\TextColumn::make('paid_total')
                    ->label('Faktiki ödəniş')
                    ->state(fn (ProcurementItem $record) => $record->paidAmount() > 0 ? $record->paidAmount() : null)
                    ->money('AZN')
                    ->placeholder('Ödəniş yoxdur')
                    ->description(fn (ProcurementItem $record) => $this->paymentSourceLabel($record))
                    ->url(fn (ProcurementItem $record) => $this->paymentSourceUrl($record))
                    // Ödəniş məbləğləri mühasibatlıq domenidir: hüququ olmayan
                    // rol (məs. dizayner) bu sütunu ümumiyyətlə görmür.
                    ->visible(fn () => auth()->user()?->can('viewAny', Payment::class)),
                // ──────────────────────────────────────────────────────────
                Tables\Columns\TextColumn::make('budgetLine.work_type')
                    ->label('Smeta sətri')
                    ->placeholder('—')
                    ->description(fn (ProcurementItem $record) => $record->budgetLine
                        ? 'Smeta: '.number_format((float) $record->budgetLine->total, 2, '.', ' ').' ₼'
                        : null)
                    ->visible(fn () => auth()->user()?->can('viewAny', BudgetLine::class))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('selection_status')
                    ->label('Seçim')
                    ->badge()
                    ->placeholder('—')
                    // Köhnə pozisiyalarda seçim statusu yoxdur (NULL) — tip nullable olmalıdır.
                    ->formatStateUsing(fn (?SelectionStatus $state) => $state?->label())
                    ->color(fn (?SelectionStatus $state) => $state?->color() ?? 'gray'),
                Tables\Columns\TextColumn::make('approval_status')
                    ->label('Razılaşma')
                    ->badge()
                    ->formatStateUsing(fn (ApprovalStatus $state) => $state->label())
                    ->color(fn (ApprovalStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('purchase_status')
                    ->label('Satınalma')
                    ->badge()
                    ->formatStateUsing(fn (PurchaseStatus $state) => $state->label())
                    ->color(fn (PurchaseStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('delivery_date')
                    ->label('Çatdırılma')
                    ->date('d.m.Y')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('paid')
                    ->label('Ödənilib (işarə)')
                    ->boolean(),
                // Görünürlük cədvəldə də oxunmalıdır: əks halda menecer hansı
                // pozisiyanın müştəriyə çıxdığını yalnız formanı açaraq bilir.
                Tables\Columns\IconColumn::make('visible_to_client')
                    ->label('Sifarişçiyə görünür')
                    ->boolean(),
                Tables\Columns\TextColumn::make('attachments_count')
                    ->label('Sənədlər')
                    ->state(fn (ProcurementItem $record) => count($record->attachments ?? []) ?: null)
                    ->placeholder('—')
                    ->icon('heroicon-o-paper-clip')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('store')
                    ->label('Mağaza')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('selection_status')
                    ->label('Seçim')
                    ->options(collect(SelectionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                Tables\Filters\SelectFilter::make('approval_status')
                    ->label('Razılaşma')
                    ->options(collect(ApprovalStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                Tables\Filters\SelectFilter::make('purchase_status')
                    ->label('Satınalma')
                    ->options(collect(PurchaseStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                Tables\Filters\SelectFilter::make('category')
                    ->label('Kateqoriya')
                    ->options(fn () => $this->getOwnerRecord()->procurementItems()
                        ->whereNotNull('category')->distinct()->pluck('category', 'category')->all()),
                Tables\Filters\SelectFilter::make('room')
                    ->label('Otaq')
                    ->options(fn () => $this->getOwnerRecord()->procurementItems()
                        ->whereNotNull('room')->distinct()->pluck('room', 'room')->all()),
            ])
            ->headerActions([
                Actions\Action::make('exportExcel')
                    ->label('Excel-ə ixrac')
                    ->icon('heroicon-o-arrow-down-tray')
                    // The export carries every purchase price; it is a read of the
                    // Procurement domain and must be gated like one.
                    ->visible(fn () => auth()->user()?->can('viewAny', ProcurementItem::class))
                    ->action(fn () => Excel::download(
                        new ProcurementExport($this->getOwnerRecord()),
                        'komplektasiya-'.$this->getOwnerRecord()->id.'.xlsx',
                    )),
                Actions\CreateAction::make()->label('Pozisiya əlavə et'),
            ])
            ->actions([
                // Baxış hüququ olan (məs. dizayner) pozisiyanın şəkil və
                // sənədlərini redaktə formasına girmədən açır.
                Actions\ViewAction::make(),
                Actions\Action::make('requestApproval')
                    ->label('Razılaşdırmaya göndər')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn ($record) => in_array($record->approval_status, [ApprovalStatus::Draft, ApprovalStatus::Rejected], true)
                        && auth()->user()->can('update', $record))
                    ->requiresConfirmation()
                    ->modalDescription('Pozisiya sifarişçiyə razılaşdırma üçün göndəriləcək və ona bildiriş gedəcək.')
                    ->action(fn ($record, ApprovalService $service) => $service->request($record, auth()->user())),
                Actions\Action::make('cancelItem')
                    ->label('Ləğv et')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn ($record) => $record->isDeletionLocked() && $record->purchase_status !== PurchaseStatus::Cancelled
                        && auth()->user()->can('update', $record))
                    ->form([
                        Forms\Components\Textarea::make('cancel_comment')
                            ->label('Ləğv səbəbi (məcburi)')
                            ->required()
                            ->rows(2),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Pozisiyanı ləğv et')
                    ->modalDescription('Razılaşdırılmış və ödənilmiş pozisiya silinə bilməz — yalnız şərhlə ləğv edilə bilər (TZ §5.10).')
                    ->action(fn ($record, array $data) => $record->update([
                        'purchase_status' => PurchaseStatus::Cancelled,
                        'cancel_comment' => $data['cancel_comment'],
                    ])),
                Actions\EditAction::make()->using(OptimisticLock::updateUsing()),
                Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->hidden(fn ($record) => $record->isDeletionLocked()),
            ]);
    }

    private function values(): ProcurementValueService
    {
        return $this->values ??= new ProcurementValueService;
    }

    /** @return array<int, string> */
    private function budgetLineOptions(): array
    {
        return $this->getOwnerRecord()->budgetLines()
            ->orderBy('position')
            ->get(['id', 'work_type', 'room', 'total'])
            ->mapWithKeys(fn (BudgetLine $line) => [
                $line->id => trim($line->work_type.($line->room ? ' — '.$line->room : ''))
                    .' ('.number_format((float) $line->total, 2, '.', ' ').' ₼)',
            ])
            ->all();
    }

    private function orderSourceLabel(ProcurementItem $item): ?string
    {
        $orderIds = collect($this->values()->orderLinesFor($item))->pluck('order_id')->unique();

        if ($orderIds->isEmpty()) {
            return null;
        }

        return 'Mənbə: satınalma sifarişi '.$orderIds->map(fn ($id) => '№'.$id)->implode(', ');
    }

    /** Keçid yalnız sifarişə baxmaq hüququ olduqda (Domain::Procurement). */
    private function orderSourceUrl(ProcurementItem $item): ?string
    {
        $orderIds = collect($this->values()->orderLinesFor($item))->pluck('order_id')->unique()->values();

        if ($orderIds->count() !== 1) {
            return null;
        }

        $order = PurchaseOrder::query()->find($orderIds->first());

        return $order && auth()->user()?->can('view', $order)
            ? PurchaseOrderResource::getUrl('edit', ['record' => $order])
            : null;
    }

    private function paymentSourceLabel(ProcurementItem $item): ?string
    {
        $count = $this->values()->paymentsFor($item)->count();

        return $count > 0 ? "Mənbə: mühasibatlıq · {$count} ödəniş qeydi" : null;
    }

    /** Keçid yalnız mühasibatlıq qeydinə baxmaq hüququ olduqda (Domain::Payments). */
    private function paymentSourceUrl(ProcurementItem $item): ?string
    {
        $payments = $this->values()->paymentsFor($item);
        $user = auth()->user();

        if ($payments->isEmpty() || ! $user) {
            return null;
        }

        if ($payments->count() === 1) {
            $payment = $payments->first()->setRelation('project', $item->project);

            return $user->can('view', $payment) ? PaymentResource::getUrl('edit', ['record' => $payment]) : null;
        }

        return $user->can('viewAny', Payment::class)
            ? PaymentResource::getUrl('index', ['tableFilters' => ['project_id' => ['value' => $item->project_id]]])
            : null;
    }
}
