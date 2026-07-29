<?php

namespace Tests\Feature\Web;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-717bn follow-up (review-gate run psa-717bn.9#332r2, escalated finding
 * context:1 / diff:5): the ticket-merge typeahead on tickets/show builds its
 * result rows client-side from /api/tickets/search and interpolated
 * t.display_id, t.subject (via truncateStr), t.priority_class and t.priority
 * RAW into innerHTML, while escHtml() sat defined on the same page and was
 * applied only to the category chip. t.subject is attacker-influenceable with
 * no staff action (inbound email subject), and the payload fires in the STAFF
 * browser the moment a merge search matches — same stored-XSS class as the
 * emails/index typeahead fixed in PR #328 and held escaped by
 * EmailsTypeaheadEscapingTest.
 *
 * Guard shape: the untrusted text never reaches server-rendered HTML, so the
 * guard is on the render SOURCE. Concatenation into an HTML string is the
 * sink, so no bare '+ t.<field>' may appear in string-concatenation position
 * anywhere in the page script; textContent assignments (= t.display_id) are
 * safe by construction and deliberately not flagged.
 */
class TicketMergeTypeaheadEscapingTest extends TestCase
{
    use RefreshDatabase;

    private const RENDERED_FIELDS = ['display_id', 'priority_class', 'priority', 'subject'];

    public function test_merge_typeahead_escapes_every_rendered_ticket_field(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress]);

        $js = $this->pageScript(
            $this->actingAs(User::factory()->create())
                ->get(route('tickets.show', $ticket))
                ->assertOk()
                ->getContent()
        );

        // The helper the escHtml() calls resolve to must actually be defined on
        // the page, and must encode double quotes (it also feeds the chip's
        // title="" attribute context).
        $this->assertMatchesRegularExpression(
            '/function\s+escHtml\s*\(/',
            $js,
            'tickets/show: no escHtml() helper is defined, so the escaped-field calls resolve to nothing.'
        );
        $this->assertMatchesRegularExpression(
            '/&quot;/',
            $js,
            'tickets/show: escHtml() no longer quote-encodes — unsafe for the title attribute context.'
        );

        // Positive control: the merge renderer must still interpolate each field
        // through escHtml, and the subject must keep its truncation.
        $this->assertMatchesRegularExpression('/escHtml\(\s*t\.display_id\s*\)/', $js);
        $this->assertMatchesRegularExpression('/escHtml\(\s*truncateStr\(\s*t\.subject\b/', $js);
        $this->assertMatchesRegularExpression('/escHtml\(\s*t\.priority_class\s*\)/', $js);
        $this->assertMatchesRegularExpression('/escHtml\(\s*t\.priority\s*\)/', $js);

        // The sink itself: no ticket field may be string-concatenated into HTML
        // unescaped. '+ t.<field>' only occurs in concatenation position; the
        // safe textContent uses are assignments and do not match.
        foreach (self::RENDERED_FIELDS as $field) {
            $this->assertDoesNotMatchRegularExpression(
                '/\+\s*t\.'.$field.'\b/',
                $js,
                "tickets/show: t.{$field} is concatenated into an HTML string unescaped — ".
                'stored XSS into the staff browser via the merge typeahead (subject arrives from inbound email).'
            );
        }
        $this->assertDoesNotMatchRegularExpression(
            '/\+\s*truncateStr\(\s*t\.subject\b/',
            $js,
            'tickets/show: truncateStr(t.subject) is concatenated into an HTML string without escHtml().'
        );
    }

    /** The concatenated body of every <script> element on the page. */
    private function pageScript(string $html): string
    {
        preg_match_all('#<script\b[^>]*>(.*?)</script>#si', $html, $m);

        return implode("\n", $m[1]);
    }
}
