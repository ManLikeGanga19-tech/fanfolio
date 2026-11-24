<?php

declare(strict_types=1);

namespace App\Modules\Creators\Filament\Admin;

use App\Modules\Creators\Actions\ApproveCreator;
use App\Modules\Creators\Actions\RejectCreator;
use App\Modules\Creators\Actions\RequestCreatorFix;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\FixKind;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorKyc;
use App\Modules\Creators\Models\PayoutMethod;
use App\Modules\Identity\Contracts\Data\DocumentSide;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Decision D-10: the minimal "Creators awaiting review" page, so onboarding can
 * finish in M2. The full approvals board from the canvas replaces it in M8.
 *
 * Rule 13: staff see masked details only (document type and last 4, payout hint,
 * whether the payout name matches the ID), never the legal name, date of birth or
 * payout numbers. The ID image is fetched from the provider on demand (D-01).
 * Every decision is checked again and audited inside its Action.
 */
final class CreatorApprovals extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Creators awaiting review';

    protected static ?string $title = 'Creators awaiting review';

    protected static ?string $slug = 'creator-approvals';

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Creator::query()->where('status', CreatorStatus::Submitted))
            ->defaultSort('submitted_at')
            ->emptyStateHeading('No applications waiting')
            ->columns([
                TextColumn::make('display_name')->label('Page')->description(static fn (Creator $record): string => '@'.$record->handle),
                TextColumn::make('category')->formatStateUsing(static fn (string $state): string => self::categoryLabel($state)),
                IconColumn::make('is_adult')->label('18+ content')->boolean(),
                TextColumn::make('document')->label('ID')->state(static fn (Creator $record): string => self::documentLabel($record->latestKyc())),
                TextColumn::make('payout')->label('Payouts')->state(static fn (Creator $record): string => $record->payoutMethod()->display_hint ?? 'None'),
                IconColumn::make('payout_name')->label('Payout name matches ID')->boolean()
                    ->state(static fn (Creator $record): bool => $record->payoutMethod() instanceof PayoutMethod && $record->payoutMethod()->name_matches_kyc),
                TextColumn::make('submitted_at')->label('Submitted')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('document')
                    ->label('View ID')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(static fn (Creator $record): string => route('creator.kyc-document', ['creator' => $record->id, 'side' => DocumentSide::Front->value]))
                    ->openUrlInNewTab(),
                Action::make('approve')
                    ->label('Approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('The creator can start posting and earning straight away.')
                    ->action(fn (Creator $record): mixed => $this->run(static fn (string $staff): Creator => app(ApproveCreator::class)->handle($record->id, $staff), 'Approved.')),
                Action::make('fix')
                    ->label('Ask for a fix')
                    ->color('warning')
                    ->schema([
                        Select::make('kind')->label('What to fix')->required()
                            ->options(collect(FixKind::cases())->mapWithKeys(static fn (FixKind $kind): array => [$kind->value => $kind->label()])->all()),
                        Textarea::make('note')->label('Note to the creator (optional)')->maxLength(300)
                            ->helperText('Shown to the creator. No personal data.'),
                    ])
                    ->action(fn (Creator $record, array $data): mixed => $this->run(
                        static fn (string $staff): Creator => app(RequestCreatorFix::class)->handle(
                            $record->id,
                            $staff,
                            FixKind::from(is_string($data['kind'] ?? null) ? $data['kind'] : ''),
                            is_string($data['note'] ?? null) ? $data['note'] : null,
                        ),
                        'Sent back to the creator.',
                    )),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The creator cannot apply again with this account.')
                    ->schema([
                        Select::make('reason')->label('Reason')->required()->options(RejectCreator::REASONS),
                    ])
                    ->action(fn (Creator $record, array $data): mixed => $this->run(
                        static fn (string $staff): Creator => app(RejectCreator::class)->handle($record->id, $staff, is_string($data['reason'] ?? null) ? $data['reason'] : ''),
                        'Rejected.',
                    )),
            ]);
    }

    public static function categoryLabel(string $key): string
    {
        $label = config()->array('fanfolio.creators.categories')[$key] ?? null;

        return is_string($label) ? $label : $key;
    }

    public static function documentLabel(?CreatorKyc $check): string
    {
        if (! $check instanceof CreatorKyc) {
            return 'Not checked';
        }

        $type = ['national_id' => 'National ID', 'passport' => 'Passport', 'driving_licence' => 'Licence'][$check->id_type] ?? 'ID';

        return $type.($check->document_last4 !== null ? ' ····'.$check->document_last4 : '').($check->liveness_passed ? ' · selfie matched' : '');
    }

    /**
     * @param  callable(string): Creator  $decision
     */
    private function run(callable $decision, string $done): null
    {
        $staff = auth()->id();

        if (! is_string($staff)) {
            abort(403);
        }

        try {
            $decision($staff);
            Notification::make()->title($done)->success()->send();
        } catch (CreatorException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }

        return null;
    }
}
