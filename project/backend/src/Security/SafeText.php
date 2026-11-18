<?php
declare(strict_types=1);

namespace Eroydad\Security;

/**
 * SafeText — قالب‌بندی امن متن دوره.
 * متن ابتدا escape می‌شود و سپس فقط چند قالب ساده و بی‌خطر (پرانتز، خط تیره، فهرست، پیوند) تبدیل می‌گردد.
 * هیچ HTML خامی از دادهٔ کاربر عبور نمی‌کند.
 */
final class SafeText
{
    public static function render(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $html = '';
        $inList = false;
        $paragraph = [];
        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if ($paragraph === []) {
                return;
            }
            $html .= '<p>' . implode('<br>', $paragraph) . '</p>' . "\n";
            $paragraph = [];
        };
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                $flushParagraph();
                if ($inList) {
                    $html .= "</ul>\n";
                    $inList = false;
                }
                continue;
            }
            if (preg_match('/^[-*•]\s+(.*)$/u', $trimmed, $m)) {
                $flushParagraph();
                if (!$inList) {
                    $html .= "<ul>\n";
                    $inList = true;
                }
                $html .= '<li>' . self::inline($m[1]) . "</li>\n";
                continue;
            }
            if (preg_match('/^#{1,4}\s+(.*)$/u', $trimmed, $m)) {
                $flushParagraph();
                if ($inList) {
                    $html .= "</ul>\n";
                    $inList = false;
                }
                $level = min(4, max(2, strlen($m[0]) - strlen(ltrim($m[0], '#')) + 1));
                $html .= '<h' . $level . '>' . self::inline($m[1]) . '</h' . $level . ">\n";
                continue;
            }
            if ($inList) {
                $html .= "</ul>\n";
                $inList = false;
            }
            $paragraph[] = self::inline($trimmed);
        }
        $flushParagraph();
        if ($inList) {
            $html .= "</ul>\n";
        }
        return $html;
    }

    private static function inline(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(^|\s)\*(?!\s)(.+?)\*(?=\s|$)/u', '$1<em>$2</em>', $escaped) ?? $escaped;
        $escaped = preg_replace_callback('/\[([^\]]{1,120})\]\((https?:\/\/[^\s)]{1,300})\)/u', static function (array $m): string {
            $url = htmlspecialchars($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return '<a href="' . $url . '" rel="noopener noreferrer nofollow" target="_blank">' . $m[1] . '</a>';
        }, $escaped) ?? $escaped;
        return $escaped;
    }
}
