<?php
declare(strict_types=1);

namespace Eroydad\Security;

/**
 * Ics — خروجی تقویم شخصی استاندارد (RFC 5545) بدون وابستگی بیرونی.
 */
final class Ics
{
    /**
     * @param array<int,array<string,mixed>> $events هر رویداد: title, description, location, start (UTC), end (UTC), url
     */
    public static function build(array $events, string $calendarName = 'ای‌رویداد'): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Erooydad//Local//FA',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::escape($calendarName),
            'X-WR-TIMEZONE:Asia/Tehran',
        ];
        $stamp = gmdate('Ymd\THis\Z');
        foreach ($events as $event) {
            $start = (string)($event['start'] ?? '');
            $end = (string)($event['end'] ?? '');
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . self::escape((string)($event['uid'] ?? ('event-' . md5($start . ($event['title'] ?? ''))))) . '@erooydad.local';
            $lines[] = 'DTSTAMP:' . $stamp;
            if ($start !== '') {
                $lines[] = 'DTSTART:' . self::utcStamp($start);
            }
            if ($end !== '') {
                $lines[] = 'DTEND:' . self::utcStamp($end);
            }
            $lines[] = 'SUMMARY:' . self::escape((string)($event['title'] ?? 'دوره'));
            if (!empty($event['description'])) {
                $lines[] = 'DESCRIPTION:' . self::escape((string)$event['description']);
            }
            if (!empty($event['location'])) {
                $lines[] = 'LOCATION:' . self::escape((string)$event['location']);
            }
            if (!empty($event['url'])) {
                $lines[] = 'URL:' . self::escape((string)$event['url']);
            }
            $lines[] = 'STATUS:CONFIRMED';
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", $lines) . "\r\n";
    }

    private static function utcStamp(string $utc): string
    {
        $timestamp = strtotime($utc . ' UTC');
        if ($timestamp === false) {
            $timestamp = time();
        }
        return gmdate('Ymd\THis\Z', $timestamp);
    }

    private static function escape(string $value): string
    {
        $value = str_replace(["\\", "\r\n", "\n", "\r", ';', ','], ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'], $value);
        return $value;
    }
}
