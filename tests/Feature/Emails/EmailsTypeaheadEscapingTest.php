<?php

namespace Tests\Feature\Emails;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * psa-ed1an — the emails index "link to ticket" typeahead (single-email modal and
 * bulk-link modal) builds its result rows client-side from /api/tickets/search and
 * used to inject the ticket fields RAW into innerHTML:
 *
 *     resultsDiv.innerHTML = tickets.map(function(t) {
 *         return '...<strong>' + t.display_id + '</strong> '
 *              + '<span class="badge ' + t.priority_class + ' ...">' + t.priority + '</span>'
 *              + t.subject + '...';
 *     }).join('') ...
 *
 * t.subject is attacker-influenceable: TicketController@apiSearch selects the raw
 * ticket subject, and a subject arrives from inbound email with no staff action at
 * all, so a subject like <img src=x onerror=...> executes JS in the STAFF browser
 * the moment the typeahead renders a matching ticket. A DOM/stored XSS on a staff
 * surface.
 *
 * The two sibling consumers of the same payload already escape every field
 * (public/js/softphone.js esc(...), public/js/command-palette.js escapeHtml(...));
 * emails/index was the only unescaped one. This test holds it escaped.
 *
 * Unlike ProfileLineOptionsJsContextTest / InvoiceLineOptionsJsContextTest — which
 * guard SERVER-RENDERED data islands with a JS-context lexer — the untrusted text
 * here never reaches the server-rendered HTML: it is fetched and interpolated
 * entirely client-side. So the guard is on the render SOURCE: every rendered
 * t.<field> must pass through escapeHtml() before it reaches innerHTML, and the
 * escapeHtml() helper it calls must actually be defined on the page.
 */
class EmailsTypeaheadEscapingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every ticket field the typeahead renders into innerHTML. t.id is deliberately
     * excluded: it is a numeric DB primary key interpolated in JS-argument position
     * inside onclick="selectLinkTicket(...)", not HTML text — escapeHtml would be the
     * wrong encoder there and it is not a vector.
     */
    private const RENDERED_FIELDS = ['display_id', 'priority_class', 'priority', 'subject'];

    public function test_link_ticket_typeahead_escapes_every_rendered_ticket_field(): void
    {
        $this->actingAs(User::factory()->create());

        $js = $this->pageScript($this->get(route('emails.index'))->assertOk()->getContent());

        // The helper the escapeHtml() calls resolve to must exist on the page — a
        // call to an undefined escapeHtml would throw and render nothing, "passing"
        // a source check that only looked for the call (the data-island lesson from
        // AssertsInertJsData: asserting the call without the definition proves
        // nothing).
        $this->assertMatchesRegularExpression(
            '/function\s+escapeHtml\s*\(/',
            $js,
            'emails/index: no escapeHtml() helper is defined, so the escaped-field calls resolve to nothing.'
        );

        foreach (self::RENDERED_FIELDS as $field) {
            $total = preg_match_all('/\bt\.'.$field.'\b/', $js);
            $escaped = preg_match_all('/escapeHtml\(\s*t\.'.$field.'\b/', $js);

            // subject is rendered at BOTH typeahead sites — a count of 0 means the
            // fixture never rendered the typeahead and the test proved nothing.
            if ($field === 'subject') {
                $this->assertGreaterThanOrEqual(
                    2,
                    $total,
                    'emails/index: expected the link-ticket typeahead to render t.subject at both the '.
                    'single-email and bulk-link sites, found '.$total.'. The typeahead did not render.'
                );
            }

            $this->assertSame(
                $total,
                $escaped,
                "emails/index: {$escaped} of {$total} t.{$field} references are escapeHtml()-wrapped. ".
                'Every ticket field interpolated into the typeahead innerHTML must be HTML-escaped — '.
                't.subject is attacker-influenceable via inbound email subject (stored XSS into the staff browser).'
            );
        }
    }

    /** The concatenated body of every <script> element on the page. */
    private function pageScript(string $html): string
    {
        preg_match_all('#<script\b[^>]*>(.*?)</script>#si', $html, $m);

        return implode("\n", $m[1]);
    }
}
