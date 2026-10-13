<?php

namespace Tests\Feature\Prepay;

use App\Models\Client;
use App\Models\Contract;
use App\Models\PrepayTransaction;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\PrepayService;
use App\Services\TimeEntryContractMoveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContractC6bTest extends TestCase
{
    use RefreshDatabase;

    private const LOCK = 'Locked: ledger source contract is missing or belongs to another client';

    private function fixture(string $kind = 'hours', bool $ledgered = true): array
    {
        Http::fake();
        Http::preventStrayRequests();
        $client = Client::create(['name' => 'Synthetic C6b']);
        $attributes = ['client_id' => $client->id, 'name' => 'Synthetic source', 'type' => 'managed', 'status' => 'active', 'start_date' => '2026-01-01', 'prepay_as_amount' => $kind === 'amount', 'prepay_balance' => $kind === 'none' ? null : 5, 'prepay_used' => 1];
        $source = Contract::create($attributes);
        $target = Contract::create(array_merge($attributes, ['name' => 'Synthetic target', 'prepay_balance' => 5, 'prepay_as_amount' => false]));
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'contract_id' => $target->id]);
        $note = TicketNote::withoutEvents(fn () => TicketNote::forceCreate(['ticket_id' => $ticket->id, 'contract_id' => $source->id, 'body' => 'Synthetic time', 'note_type' => 'note', 'is_billable' => true, 'time_minutes' => 45]));
        if ($ledgered) {
            PrepayTransaction::create(['contract_id' => $source->id, 'ticket_note_id' => $note->id, 'source' => 'ticket_time', 'date' => now(), 'hours' => -0.75]);
        }

        return [$ticket, $note, $source, $target, User::factory()->create()];
    }

    public function test_foreign_ledger_source_is_locked_in_preview(): void
    {
        [$ticket, $note, $source] = $this->fixture();
        $source->update(['client_id' => Client::create(['name' => 'Synthetic other'])->id]);
        $row = app(TimeEntryContractMoveService::class)->entries($ticket)->first();
        $this->assertSame(self::LOCK, $row['locked']);
        $html = view('tickets._contract-change-modal', ['ticket' => $ticket, 'contractEntries' => collect([$row])])->render();
        $this->assertStringContainsString('data-locked-text="'.self::LOCK.'"', $html);
        $this->assertMatchesRegularExpression('/id="ccMovenote'.$note->id.'"\s+disabled/', $html);
    }

    public function test_deleted_source_renders_balance_facts_consumed_by_preview(): void
    {
        [$ticket, , $source] = $this->fixture();
        $source->delete();
        $rows = app(TimeEntryContractMoveService::class)->entries($ticket);
        $html = view('tickets._contract-change-modal', ['ticket' => $ticket, 'contractEntries' => $rows])->render();
        $this->assertStringContainsString('data-source-prepay="1"', $html);
        $this->assertStringContainsString('data-source-balance="5"', $html);
        $this->assertStringContainsString('data-source-name="Synthetic source"', $html);
        $js = file_get_contents(public_path('js/ticket-contract-change.js'));
        $this->assertStringContainsString("const sourcePrepay = r.dataset.sourcePrepay === '1';", $js);
        $this->assertStringContainsString('if (hours > 0 && sourcePrepay)', $js);
        $this->assertStringContainsString('balance: parseFloat(r.dataset.sourceBalance', $js);
        $this->assertStringNotContainsString('hours > 0 && prepay[from]', $js);
    }

    public static function q5Cases(): array
    {
        return [['same'], ['foreign'], ['missing'], ['deleted'], ['unledgered']];
    }

    #[DataProvider('q5Cases')]
    public function test_q5_preview_server_parity(string $case): void
    {
        [$ticket, $note, $source, $target, $by] = $this->fixture('hours', $case !== 'unledgered');
        if (in_array($case, ['foreign', 'unledgered'])) {
            $source->update(['client_id' => Client::create(['name' => 'Synthetic other'])->id]);
        } elseif ($case === 'deleted') {
            $source->delete();
        } elseif ($case === 'missing') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
            PrepayTransaction::where('ticket_note_id', $note->id)->update(['contract_id' => 999999]);
        }
        $prepay = app(PrepayService::class);
        $row = app(TimeEntryContractMoveService::class)->entries($ticket, $target->id)->first();
        $refuses = in_array($case, ['foreign', 'missing']);
        $this->assertSame($refuses ? self::LOCK : null, $row['locked']);
        $this->assertSame(! in_array($case, ['foreign', 'missing', 'unledgered']), $prepay->ledgerSourceMatchesClient($case === 'missing' ? null : Contract::withTrashed()->find($source->id), (int) $ticket->client_id));
        $before = PrepayTransaction::count();
        try {
            $result = $prepay->moveEntryContract($note, $target, 'Synthetic correction', $by);
            $this->assertFalse($refuses);
            $this->assertSame((int) $target->id, $result['to_contract_id']);
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue($refuses);
            $this->assertSame("That note's ledger source contract is missing or belongs to another client; nothing moved.", $e->getMessage());
            $this->assertSame($before, PrepayTransaction::count());
            $this->assertSame((int) $source->id, (int) $note->fresh()->contract_id);
        }
    }

    public static function balanceCases(): array
    {
        $cases = [];
        foreach (['hours', 'amount', 'none'] as $kind) {
            foreach ([true, false] as $ledgered) {
                foreach ([true, false] as $deleted) {
                    $cases[] = [$kind, $ledgered, $deleted];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('balanceCases')]
    public function test_rendered_balance_delta_matches_executed_move(string $kind, bool $ledgered, bool $deleted): void
    {
        [$ticket, $note, $source, $target, $by] = $this->fixture($kind, $ledgered);
        if ($deleted) {
            $source->delete();
        }
        $prepay = app(PrepayService::class);
        $row = app(TimeEntryContractMoveService::class)->entries($ticket, $target->id)->first();
        $this->assertNull($row['locked']);
        $this->assertSame($kind === 'hours', $prepay->isHoursPrepay($source));
        $this->assertSame($prepay->isHoursPrepay($source), $row['contract_prepay']);
        $html = view('tickets._contract-change-modal', ['ticket' => $ticket, 'contractEntries' => collect([$row])])->render();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $tr = (new \DOMXPath($dom))->query('//tr[contains(@class,"js-cc-row")]')->item(0);
        $this->assertNotNull($tr);
        $previewSource = $tr->getAttribute('data-ledger') === '1' && $tr->getAttribute('data-source-prepay') === '1' ? (float) $tr->getAttribute('data-hours') : 0.0;
        $previewTarget = -($ledgered ? (float) $tr->getAttribute('data-hours') : (float) $tr->getAttribute('data-draw-hours'));
        $sourceBefore = (float) $source->prepay_balance;
        $targetBefore = (float) $target->prepay_balance;
        $prepay->moveEntryContract($note, $target, 'Synthetic correction', $by);
        $this->assertSame($previewSource, round((float) $source->refresh()->prepay_balance - $sourceBefore, 4));
        $this->assertSame($previewTarget, round((float) $target->refresh()->prepay_balance - $targetBefore, 4));
        $this->assertSame($ledgered ? 1 : 0, PrepayTransaction::where('contract_id', $source->id)->where('source', 'entry_moved_out')->count());
    }
}
