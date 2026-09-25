<?php

namespace Tests\Feature\Tickets;

use App\Http\Controllers\Web\TicketController;
use App\Models\Client;
use App\Models\TechnicianActionLog;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Mcp\TicketTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Card mSKGIORa: the staff ticket page shows the whole timeline in one scrollable
 * list with no Newer/Older pager, and folds adjacent tool entries into one row.
 */
class TicketTimelineScrollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function ticket(): Ticket
    {
        return Ticket::factory()->for(Client::factory())->create();
    }

    private function note(Ticket $ticket, string $at, string $body): TicketNote
    {
        return TicketNote::create(['ticket_id' => $ticket->id, 'noted_at' => $at, 'body' => $body, 'note_type' => 'note']);
    }

    private function tool(Ticket $ticket, string $at, string $name): TechnicianActionLog
    {
        return TechnicianActionLog::forceCreate(['ticket_id' => $ticket->id, 'client_id' => $ticket->client_id,
            'action_type' => $name, 'actor_label' => 'Synthetic actor', 'tier' => 'approve',
            'result_status' => 'executed', 'content_hash' => str_repeat('a', 64), 'summary' => 'RAW-SECRET-PAYLOAD',
            'correlation_id' => (string) Str::uuid(), 'created_at' => $at]);
    }

    private function show(Ticket $ticket, array $query = [])
    {
        return $this->actingAs(User::factory()->create())
            ->get(route('tickets.show', ['ticket' => $ticket] + $query))->assertOk();
    }

    public function test_more_than_two_projection_pages_render_whole_with_no_pager(): void
    {
        $ticket = $this->ticket();
        $base = Carbon::parse('2026-01-01 00:00:00');
        $markers = [];
        for ($i = 0; $i < 120; $i++) {
            $markers[] = sprintf('scroll-marker-%03d', $i);
            $this->note($ticket, $base->copy()->addMinutes($i)->toDateTimeString(), $markers[$i]);
        }
        $response = $this->show($ticket);
        // Newest first: the last marker written is the first rendered.
        $response->assertSeeInOrder(array_reverse($markers))
            ->assertDontSee('>Newer</a>', false)->assertDontSee('>Older</a>', false)
            ->assertDontSee('More entries available in this direction')
            ->assertSee('data-timeline-count="120"', false)
            ->assertSee('120 entries, full history.');
    }

    public function test_old_bookmark_cursor_params_are_ignored_and_filters_still_apply(): void
    {
        $ticket = $this->ticket();
        for ($i = 0; $i < 30; $i++) {
            $this->note($ticket, Carbon::parse('2026-01-01')->addMinutes($i)->toDateTimeString(), sprintf('bookmark-note-%02d', $i));
        }
        $this->tool($ticket, '2025-12-31 00:00:00', 'synthetic_filter_tool');
        $page = app(TicketTimeline::class)->page($ticket, ['limit' => 5]);
        foreach (['before' => $page['before'], 'after' => $page['after']] as $param => $token) {
            $this->show($ticket, [$param => $token])
                ->assertSee('bookmark-note-29')->assertSee('bookmark-note-00')->assertSee('synthetic_filter_tool');
        }
        // A token that never decoded used to 422; the page now ignores the param.
        $this->show($ticket, ['before' => 'not-a-cursor'])->assertSee('bookmark-note-00');
        $this->show($ticket, ['types' => ['tool']])
            ->assertSee('synthetic_filter_tool')->assertDontSee('bookmark-note-')
            ->assertSee('data-timeline-count="1"', false);
        $this->actingAs(User::factory()->create())
            ->get(route('tickets.show', ['ticket' => $ticket, 'types' => ['bogus']]))->assertSessionHasErrors('types.0');
    }

    public function test_three_adjacent_tool_entries_render_as_one_collapsed_group(): void
    {
        $ticket = $this->ticket();
        $this->note($ticket, '2026-01-01 12:00:00', 'note-above-run');
        $this->tool($ticket, '2026-01-01 11:00:00', 'synthetic_tool_alpha');
        $this->tool($ticket, '2026-01-01 10:00:00', 'synthetic_tool_beta');
        $this->tool($ticket, '2026-01-01 09:00:00', 'synthetic_tool_alpha');
        $this->note($ticket, '2026-01-01 08:00:00', 'note-below-run');
        $html = $this->show($ticket)->getContent();

        $this->assertSame(1, substr_count($html, 'data-timeline-kind="tool-run"'));
        $this->assertSame(1, preg_match('#<details[^>]*data-timeline-run-count="3"[^>]*>(.*?)</details>#s', $html, $group));
        $this->assertSame(3, substr_count($group[1], 'data-timeline-kind="tool"'));
        $this->assertStringContainsString('3 tool calls', $group[1]);
        $this->assertStringContainsString('synthetic_tool_alpha ×2, synthetic_tool_beta', $group[1]);
        $this->assertStringContainsString('2026-01-01 09:00:00 – 2026-01-01 11:00:00 UTC', $group[1]);
        $this->assertStringNotContainsString('note-above-run', $group[1]);
        $this->assertStringNotContainsString('note-below-run', $group[1]);
        $this->assertSame(3, substr_count($html, 'data-timeline-kind="tool"'));
        $this->assertStringNotContainsString('RAW-SECRET-PAYLOAD', $html);
    }

    public function test_tool_note_tool_renders_three_separate_rows(): void
    {
        $ticket = $this->ticket();
        $this->tool($ticket, '2026-01-01 11:00:00', 'synthetic_tool_first');
        $this->note($ticket, '2026-01-01 10:00:00', 'note-between-tools');
        $this->tool($ticket, '2026-01-01 09:00:00', 'synthetic_tool_second');
        $html = $this->show($ticket)->assertSeeInOrder(['synthetic_tool_first', 'note-between-tools', 'synthetic_tool_second'])->getContent();

        $this->assertSame(0, substr_count($html, 'data-timeline-kind="tool-run"'));
        $this->assertSame(0, substr_count($html, '<details'));
        $this->assertSame(2, substr_count($html, 'data-timeline-kind="tool"'));
    }

    public function test_a_single_tool_entry_renders_as_before(): void
    {
        $ticket = $this->ticket();
        $this->tool($ticket, '2026-01-01 11:00:00', 'synthetic_tool_lone');
        $html = $this->show($ticket)->getContent();

        $this->assertSame(0, substr_count($html, 'data-timeline-kind="tool-run"'));
        $this->assertSame(1, substr_count($html, 'data-timeline-kind="tool"'));
    }

    public function test_a_run_spanning_the_internal_page_boundary_is_one_group(): void
    {
        $ticket = $this->ticket();
        $base = Carbon::parse('2026-01-01 00:00:00');
        // Newest first: 48 notes, then 4 tools at display positions 49-52, then 10 notes.
        // Each internal projection page holds 50 entries, so the run crosses it.
        for ($i = 0; $i < 10; $i++) {
            $this->note($ticket, $base->copy()->addMinutes($i)->toDateTimeString(), 'old-note');
        }
        for ($i = 10; $i < 14; $i++) {
            $this->tool($ticket, $base->copy()->addMinutes($i)->toDateTimeString(), 'synthetic_boundary_tool');
        }
        for ($i = 14; $i < 62; $i++) {
            $this->note($ticket, $base->copy()->addMinutes($i)->toDateTimeString(), 'new-note');
        }
        $first = app(TicketTimeline::class)->page($ticket, ['limit' => 50]);
        $this->assertSame(2, count(array_filter($first['items'], fn ($e) => $e['kind'] === 'tool')), 'fixture must straddle the page');
        $html = $this->show($ticket)->getContent();

        $this->assertSame(1, substr_count($html, 'data-timeline-kind="tool-run"'));
        $this->assertStringContainsString('data-timeline-run-count="4"', $html);
    }

    public function test_a_ticket_past_the_render_ceiling_says_older_entries_are_not_shown(): void
    {
        $ticket = $this->ticket();
        $base = Carbon::parse('2026-01-01 00:00:00');
        $rows = [];
        for ($i = 0; $i <= TicketController::TIMELINE_MAX_ENTRIES; $i++) {
            $rows[] = ['ticket_id' => $ticket->id, 'noted_at' => $base->copy()->addMinutes($i)->toDateTimeString(),
                'body' => $i === 0 ? 'oldest-beyond-ceiling' : 'bulk', 'note_type' => 'note',
                'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('ticket_notes')->insert($chunk);
        }
        $max = TicketController::TIMELINE_MAX_ENTRIES;
        $this->show($ticket)
            ->assertSee('data-timeline-count="'.$max.'"', false)
            ->assertSee("Showing the newest {$max} entries; older entries are not shown on this page.")
            ->assertDontSee('oldest-beyond-ceiling');
    }

    public function test_query_count_does_not_grow_per_entry(): void
    {
        $counts = [];
        foreach ([10, 90] as $n) {
            $ticket = $this->ticket();
            for ($i = 0; $i < $n; $i++) {
                $at = Carbon::parse('2026-01-01')->addMinutes($i)->toDateTimeString();
                $i % 3 === 0 ? $this->tool($ticket, $at, 'synthetic_count_tool') : $this->note($ticket, $at, 'count-note');
            }
            $this->actingAs(User::factory()->create());
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('tickets.show', $ticket))->assertOk();
            $counts[$n] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        // 90 entries cross one extra projection page (a fixed handful of queries);
        // per-entry hydration would add at least 80.
        $this->assertLessThan(20, $counts[90] - $counts[10], json_encode($counts));
    }

    public function test_mcp_timeline_limit_and_cursor_contract_is_unchanged(): void
    {
        $ticket = $this->ticket();
        for ($i = 0; $i < 60; $i++) {
            $this->note($ticket, Carbon::parse('2026-01-01')->addMinutes($i)->toDateTimeString(), 'mcp-note');
        }
        $timeline = app(TicketTimeline::class);
        $default = $timeline->page($ticket);
        $this->assertSame(20, $default['limit']);
        $this->assertCount(20, $default['items']);
        $this->assertTrue($default['has_more']);
        $this->assertNotNull($default['next_cursor']);
        $max = $timeline->page($ticket, ['limit' => 50]);
        $this->assertCount(50, $max['items']);
        $rest = $timeline->page($ticket, ['limit' => 50, 'before' => $max['next_cursor']]);
        $this->assertCount(10, $rest['items']);
        $this->assertNull($rest['next_cursor']);
        foreach ([0, 51, '20'] as $bad) {
            try {
                $timeline->page($ticket, ['limit' => $bad]);
                $this->fail('limit '.json_encode($bad).' must be refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('limit must be an integer from 1 to 50', $e->getMessage());
            }
        }
        $this->assertArrayNotHasKey('model', $default['items'][0]);
        $schema = \App\Services\Mcp\TicketTimelineTool::definition()['input_schema']['properties'];
        $this->assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 50], $schema['limit']);
        $this->assertSame(['type' => 'string'], $schema['before']);
        $this->assertSame(['type' => 'string'], $schema['after']);
    }
}
