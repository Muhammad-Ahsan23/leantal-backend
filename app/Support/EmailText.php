<?php

namespace App\Support;

/**
 * Turns a stored email body into something safe and readable, and separates what the
 * person NEWLY wrote from the history their mail client quoted underneath it.
 *
 * Why this exists: mailbox sync stores the body as HTML (Gmail/Outlook replies are HTML,
 * and plain-text replies are converted to HTML). Showing that HTML as text in the app
 * printed raw tags in conversations, and previews glued paragraphs together
 * ("Thanks" + "On Wed, Oct 7 ... wrote:" became "ThanksOn Wed...") and repeated the quoted
 * history of earlier messages in every reply.
 *
 * Output is always PLAIN TEXT. The browser never receives (or renders) the sender's HTML,
 * so a hostile email cannot inject markup or scripts into the app.
 *
 * Nothing is thrown away: the quoted part is returned separately so the UI can offer
 * "Show quoted text" (like Gmail does).
 */
class EmailText
{
    /** Bodies bigger than this (bytes) are cut before processing — keeps regexes cheap. */
    protected const MAX_BYTES = 300000;

    /** Class/id values that mail clients put on the wrapper of the quoted history. */
    protected const QUOTE_MARKERS = 'gmail_quote|gmail_extra|yahoo_quoted|moz-cite-prefix|appendonsend|divRplyFwdMsg|mail-editor-reference-message-container|OutlookMessageHeader';

    /**
     * @return array{text:string, quoted:string}
     */
    public static function split(?string $body): array
    {
        if ($body === null || trim($body) === '') {
            return ['text' => '', 'quoted' => ''];
        }

        $body = mb_scrub(substr($body, 0, self::MAX_BYTES), 'UTF-8');
        $isHtml = (bool) preg_match('#<\s*/?\s*(?:p|div|br|span|a|b|i|u|strong|em|ul|ol|li|table|tr|td|html|body|blockquote|font|img|h[1-6]|hr)\b#i', $body);
        // Text with no tags can still carry entities (&amp; &#8217;) — decode those too.
        $decode = $isHtml || (bool) preg_match('/&(?:[a-z]{2,8}|#\d{1,6}|#x[0-9a-f]{1,6});/i', $body);

        $visibleHtml = $body;
        $quotedHtml = '';

        if ($isHtml) {
            $body = preg_replace('#<!--.*?-->#s', '', $body) ?? $body;
            $body = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1\s*>#is', '', $body) ?? $body;

            // The first marker (a known quote wrapper, or any <blockquote>) is where history begins.
            $pos = null;
            $wrapper = '#<(?:div|blockquote|table|font|span|section)\b[^>]*\b(?:class|id)\s*=\s*["\'][^"\']*\b(?:'.self::QUOTE_MARKERS.')\b[^"\']*["\']#i';
            foreach ([$wrapper, '#<blockquote\b#i'] as $re) {
                if (preg_match($re, $body, $m, PREG_OFFSET_CAPTURE)) {
                    $pos = $pos === null ? $m[0][1] : min($pos, $m[0][1]);
                }
            }
            $visibleHtml = $pos === null ? $body : substr($body, 0, $pos);
            $quotedHtml = $pos === null ? '' : substr($body, $pos);
        }

        $visible = self::toText($visibleHtml, $isHtml, $decode);
        $quoted = $quotedHtml === '' ? '' : self::toText($quotedHtml, true, true);

        // Some clients put the attribution line ("On ... wrote:") OUTSIDE the quote wrapper, and
        // plain-text mail has no wrapper at all: cut at the first attribution / quoted-line pattern.
        $cut = self::attributionOffset($visible);
        if ($cut !== null) {
            $quoted = trim(substr($visible, $cut)."\n".$quoted);
            $visible = trim(substr($visible, 0, $cut));
        }

        // A message that is ONLY quoted/forwarded content must not show up empty.
        if ($visible === '' && $quoted !== '') {
            return ['text' => $quoted, 'quoted' => ''];
        }

        return ['text' => $visible, 'quoted' => $quoted];
    }

    /** One-line preview for list views. */
    public static function snippet(?string $body, int $limit = 160): string
    {
        $text = self::split($body)['text'];
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit)).'...' : $text;
    }

    protected static function toText(string $html, bool $isHtml, bool $decode): string
    {
        $t = str_replace(["\r\n", "\r"], "\n", $html);

        if ($isHtml) {
            // strip_tags alone glues neighbouring blocks together ("Thanks" + "On Wed..."), so block
            // boundaries become markers FIRST. \x01 = line boundary, \x02 = paragraph boundary.
            // A run of markers collapses to ONE break, so Gmail's "</div><div>" is a single new line
            // (not a blank line), and text followed directly by a nested <div> still breaks.
            $t = preg_replace('#(?<=[^\s>])<br\s*/?>(?=\s*</(?:div|p|li|td|tr)>)#i', '', $t) ?? $t;   // trailing <br> placeholder
            $t = preg_replace('#<br\s*/?>#i', "\n", $t) ?? $t;
            $t = preg_replace('#<li\b[^>]*>#i', "\x01• ", $t) ?? $t;
            $t = preg_replace('#</?(?:p)\b[^>]*>#i', "\x02", $t) ?? $t;
            $t = preg_replace('#</?(?:div|tr|h[1-6]|ul|ol|li|table|blockquote|section|article)\b[^>]*>#i', "\x01", $t) ?? $t;
            $t = strip_tags($t);
        }

        if ($decode) {
            $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $t = preg_replace_callback('/[\x01\x02]+/', fn ($m) => str_contains($m[0], "\x02") ? "\n\n" : "\n", $t) ?? $t;
        $t = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xEF\xBB\xBF"], [' ', '', '', ''], $t); // nbsp, zero-width, BOM
        $t = preg_replace('/[ \t]+/', ' ', $t) ?? $t;
        $t = preg_replace('/ ?\n ?/', "\n", $t) ?? $t;
        $t = preg_replace('/\n{3,}/', "\n\n", $t) ?? $t;

        return trim($t);
    }

    /** Byte offset where quoted history starts inside already-converted text, or null. */
    protected static function attributionOffset(string $text): ?int
    {
        $patterns = [
            '/^[ \t]*On\s[^\n]{5,200}(?:\n[^\n]{0,120})?\bwrote:[ \t]*$/mi',       // Gmail / Apple Mail / Thunderbird (may wrap to 2 lines)
            '/^[ \t]*-{2,}[ \t]*Original Message[ \t]*-{2,}[ \t]*$/mi',            // Outlook plain text
            '/^[ \t]*From:[^\n]+\n(?:[^\n]*\n){0,2}[ \t]*(?:Sent|Date):/mi',        // Outlook header block
            '/^[ \t]*>/m',                                                          // "> quoted" lines
        ];

        $best = null;
        foreach ($patterns as $re) {
            if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                $best = $best === null ? $m[0][1] : min($best, $m[0][1]);
            }
        }

        return $best;
    }
}
