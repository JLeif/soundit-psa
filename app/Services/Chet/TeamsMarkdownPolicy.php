<?php

namespace App\Services\Chet;

/**
 * What an agent-authored body may carry into a Teams activity once it is NO
 * LONGER run through TeamsText::escape (teams_post_message, card 5sALzgSC).
 *
 * Markdown survives: lists, emphasis, code, blank lines, headings, quotes and
 * ordinary `[text](https://...)` links. Three things do not:
 *
 *  1. Tag-shaped HTML. Any `<` that opens a tag, closing tag, comment or
 *     autolink (`<at>`, `</at>`, `<img`, `<!--`, `<https://`) becomes U+FF1C
 *     FULLWIDTH LESS-THAN SIGN, so the text stays legible and nothing can be
 *     parsed as a tag. This is what stops a body forging a Teams `<at>` mention
 *     marker; the mention ENTITY itself is only ever built server-side.
 *     A bare comparison (`a < b`, `<= 5`) is left alone.
 *  2. Images. `![alt](url)` loses its `!` and becomes a plain link, so a body
 *     cannot embed a remote image (a read beacon) in the operator chat.
 *  3. Non-web link targets. A link whose target is not http(s) (javascript:,
 *     data:, file:, a relative path, ...) keeps its text and loses its target.
 *
 * Cards and adaptive payloads are not this class's concern: the activity is
 * built in TeamsBotClient::postMarkdownMessage() from type/text/textFormat and
 * server-built mention entities only, so no body text can become an attachment.
 */
final class TeamsMarkdownPolicy
{
    public const FULLWIDTH_LT = "\u{FF1C}";

    /**
     * @return array{text: string, neutralized: array{html_tags: int, images: int, links: int}}
     */
    public static function apply(string $text): array
    {
        $tags = 0;
        $text = (string) preg_replace_callback(
            '/<(?=[\/!?]|[A-Za-z])/u',
            function () use (&$tags): string {
                $tags++;

                return self::FULLWIDTH_LT;
            },
            $text,
        );

        $images = 0;
        $text = (string) preg_replace_callback(
            '/!(?=\[[^\]\n]*\]\()/u',
            function () use (&$images): string {
                $images++;

                return '';
            },
            $text,
        );

        $links = 0;
        $text = (string) preg_replace_callback(
            '/\[([^\]\n]*)\]\(\s*([^)\s]*)[^)\n]*\)/u',
            function (array $m) use (&$links): string {
                if (preg_match('#^https?://[^\s/]#i', $m[2]) === 1) {
                    return $m[0];
                }
                $links++;

                return $m[1];
            },
            $text,
        );

        return [
            'text' => $text,
            'neutralized' => ['html_tags' => $tags, 'images' => $images, 'links' => $links],
        ];
    }
}
