<?php
declare(strict_types=1);

namespace Eroydad\Events;

use Eroydad\Media\PosterService;
use PDO;

/**
 * EventContentService — محتوای دوره: جلسات چندگانه، برچسب‌ها، پیوست‌ها، پوستر و پیش‌نمایش.
 */
final class EventContentService
{
    private PDO $db;
    private PosterService $posters;

    public function __construct(PDO $db, ?PosterService $posters = null)
    {
        $this->db = $db;
        $this->posters = $posters ?? new PosterService($db);
    }

    // ---------------- جلسات ----------------

    public function sessions(int $eventId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM event_sessions WHERE event_id=? ORDER BY sort_order ASC, starts_at_utc ASC');
        $stmt->execute([$eventId]);
        return $stmt->fetchAll();
    }

    public function addSession(int $eventId, array $input): int
    {
        $title = trim((string)($input['title'] ?? ''));
        if (mb_strlen($title, 'UTF-8') < 3 || mb_strlen($title, 'UTF-8') > 190) {
            throw new \DomainException('عنوان جلسه باید بین ۳ تا ۱۹۰ نویسه باشد.');
        }
        $start = EventService::localDateToUtc((string)($input['starts_at'] ?? ''));
        $end = EventService::localDateToUtc((string)($input['ends_at'] ?? ''));
        if ($end <= $start) {
            throw new \DomainException('زمان پایان جلسه باید بعد از زمان شروع باشد.');
        }
        $venue = trim((string)($input['venue'] ?? ''));
        if (mb_strlen($venue, 'UTF-8') > 240) {
            throw new \DomainException('محل جلسه بیش از حد طولانی است.');
        }
        $order = (int)($this->db->query('SELECT COUNT(*) FROM event_sessions WHERE event_id=' . (int)$eventId)->fetchColumn());
        $stmt = $this->db->prepare('INSERT INTO event_sessions (event_id,title,starts_at_utc,ends_at_utc,venue,sort_order) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$eventId, $title, $start, $end, $venue, $order]);
        return (int)$this->db->lastInsertId();
    }

    public function deleteSession(int $sessionId, int $eventId): void
    {
        $stmt = $this->db->prepare('DELETE FROM event_sessions WHERE id=? AND event_id=?');
        $stmt->execute([$sessionId, $eventId]);
    }

    // ---------------- برچسب‌ها ----------------

    public function tags(int $eventId): array
    {
        $stmt = $this->db->prepare('SELECT tag FROM event_tags WHERE event_id=? ORDER BY tag ASC');
        $stmt->execute([$eventId]);
        return array_column($stmt->fetchAll(), 'tag');
    }

    public function setTags(int $eventId, string $raw): array
    {
        $parts = preg_split('/[,،\n]+/u', $raw) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $tag = trim($part);
            if ($tag === '') {
                continue;
            }
            if (mb_strlen($tag, 'UTF-8') > 60) {
                $tag = mb_substr($tag, 0, 60, 'UTF-8');
            }
            $clean[$tag] = true;
        }
        $clean = array_slice(array_keys($clean), 0, 12);
        $this->db->prepare('DELETE FROM event_tags WHERE event_id=?')->execute([$eventId]);
        $stmt = $this->db->prepare('INSERT INTO event_tags (event_id,tag) VALUES (?,?)');
        foreach ($clean as $tag) {
            $stmt->execute([$eventId, $tag]);
        }
        return $clean;
    }

    public function popularTags(int $limit = 20): array
    {
        $limit = max(1, min(60, $limit));
        $stmt = $this->db->query('SELECT tag, COUNT(*) AS total FROM event_tags GROUP BY tag ORDER BY total DESC, tag ASC LIMIT ' . $limit);
        return $stmt->fetchAll();
    }

    // ---------------- پیوست‌ها ----------------

    public function attachments(int $eventId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM event_attachments WHERE event_id=? ORDER BY id DESC');
        $stmt->execute([$eventId]);
        return $stmt->fetchAll();
    }

    public function addAttachment(int $eventId, array $file, string $title, int $actorId): int
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \DomainException('بارگذاری پیوست ناموفق بود.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp) || $size <= 0 || $size > 10485760) {
            throw new \DomainException('حجم پیوست باید حداکثر ۱۰ مگابایت باشد.');
        }
        $allowed = [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
        ];
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = (string)finfo_file($finfo, $tmp);
                finfo_close($finfo);
            }
        }
        if ($mime === '' || !isset($allowed[$mime])) {
            throw new \DomainException('پیوست باید از نوع PDF، Word، متن یا تصویر باشد.');
        }
        $directory = (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3)) . '/storage/private/attachments';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('پوشهٔ ذخیرهٔ پیوست قابل ایجاد نیست.');
        }
        $name = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
        $target = $directory . '/' . $name;
        if (!@move_uploaded_file($tmp, $target) && !@copy($tmp, $target)) {
            throw new \RuntimeException('ذخیرهٔ پیوست ممکن نشد.');
        }
        $original = mb_substr((string)($file['name'] ?? 'پیوست'), 0, 190, 'UTF-8');
        $label = trim($title) !== '' ? mb_substr(trim($title), 0, 190, 'UTF-8') : $original;
        $stmt = $this->db->prepare('INSERT INTO event_attachments (event_id,title,stored_name,original_name,mime,size_bytes,uploaded_by) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$eventId, $label, 'attachments/' . $name, $original, $mime, $size, $actorId]);
        return (int)$this->db->lastInsertId();
    }

    public function deleteAttachment(int $attachmentId, int $eventId): void
    {
        $stmt = $this->db->prepare('SELECT stored_name FROM event_attachments WHERE id=? AND event_id=?');
        $stmt->execute([$attachmentId, $eventId]);
        $stored = $stmt->fetchColumn();
        if ($stored) {
            $path = (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3)) . '/storage/private/' . basename((string)$stored);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->db->prepare('DELETE FROM event_attachments WHERE id=? AND event_id=?')->execute([$attachmentId, $eventId]);
    }

    public function attachmentPath(array $attachment): ?string
    {
        $stored = (string)($attachment['stored_name'] ?? '');
        if ($stored === '') {
            return null;
        }
        $path = (defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(__DIR__, 3)) . '/storage/private/' . basename($stored);
        return is_file($path) ? $path : null;
    }

    // ---------------- پوستر ----------------

    public function attachPoster(int $eventId, array $file, string $alt, int $actorId): void
    {
        $stmt = $this->db->prepare('SELECT poster_path FROM events WHERE id=?');
        $stmt->execute([$eventId]);
        $previous = $stmt->fetchColumn();
        $stored = $this->posters->store($file, $alt);
        $update = $this->db->prepare('UPDATE events SET poster_path=?, poster_alt=?, poster_mime=? WHERE id=?');
        $update->execute([$stored['path'], $stored['alt'], $stored['mime'], $eventId]);
        if ($previous) {
            $this->posters->delete((string)$previous);
        }
        \auditLog($actorId, 'event.poster.uploaded', 'event', (string)$eventId, ['mime' => $stored['mime']]);
    }

    public function removePoster(int $eventId, int $actorId): void
    {
        $stmt = $this->db->prepare('SELECT poster_path FROM events WHERE id=?');
        $stmt->execute([$eventId]);
        $previous = $stmt->fetchColumn();
        if (!$previous) {
            return;
        }
        $this->db->prepare('UPDATE events SET poster_path=NULL, poster_alt=NULL, poster_mime=NULL WHERE id=?')->execute([$eventId]);
        $this->posters->delete((string)$previous);
        \auditLog($actorId, 'event.poster.removed', 'event', (string)$eventId);
    }

    // ---------------- پیش‌نمایش ----------------

    public function ensurePreviewToken(int $eventId): string
    {
        $stmt = $this->db->prepare('SELECT preview_token FROM events WHERE id=?');
        $stmt->execute([$eventId]);
        $token = (string)$stmt->fetchColumn();
        if ($token !== '') {
            return $token;
        }
        $token = bin2hex(random_bytes(18));
        $update = $this->db->prepare('UPDATE events SET preview_token=? WHERE id=?');
        $update->execute([$token, $eventId]);
        return $token;
    }

    public function byPreviewToken(string $token): ?array
    {
        $stmt = $this->db->prepare("SELECT e.*, (SELECT COUNT(*) FROM registrations r WHERE r.event_id=e.id AND r.status='confirmed') AS registered_count FROM events e WHERE e.preview_token=? LIMIT 1");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
