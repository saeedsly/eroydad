<?php
declare(strict_types=1);

namespace Eroydad\Reports;

use Eroydad\Excel\XlsxReader;
use Eroydad\Employees\EmployeeImportService;
use Eroydad\Security\IranianNationalId;
use PDO;

/**
 * ImportService — ورود امن اطلاعات از CSV یا XLSX با پیش‌نمایش، اعتبارسنجی ردیف‌به‌ردیف و بازگشت تراکنش.
 * فقط دو مسیر مجاز وجود دارد: شرکت‌کنندگان یک دوره و فهرست کارکنان (لایهٔ مجوز).
 */
final class ImportService
{
    public const MAX_BYTES = 5242880; // ۵ مگابایت
    public const MAX_ROWS = 5000;

    private PDO $db;
    private array $config;

    public function __construct(PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * پیش‌نمایش فایل پیش از ورود اطلاعات.
     * @return array<string,mixed>
     */
    public function preview(string $tmpPath, string $originalName, string $target): array
    {
        if (!is_file($tmpPath)) {
            throw new \DomainException('فایل بارگذاری نشد.');
        }
        if (filesize($tmpPath) > self::MAX_BYTES) {
            throw new \DomainException('حجم فایل بیش از ۵ مگابایت است.');
        }
        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt', 'xlsx'], true)) {
            throw new \DomainException('تنها فایل CSV یا XLSX پذیرفته می‌شود.');
        }
        $table = $extension === 'xlsx' ? $this->readXlsx($tmpPath) : $this->readCsv($tmpPath);
        if ($table === []) {
            throw new \DomainException('فایل خالی است.');
        }
        $headers = array_map([$this, 'canonicalHeader'], array_values((array)array_shift($table)));
        $rows = [];
        $errors = [];
        $seenPhones = [];
        foreach ($table as $index => $raw) {
            $lineNumber = $index + 2;
            $values = array_values((array)$raw);
            $record = [];
            foreach ($headers as $columnIndex => $column) {
                $record[$column] = isset($values[$columnIndex]) ? trim((string)$values[$columnIndex]) : '';
            }
            $record['_row'] = $lineNumber;
            $rowErrors = $this->validateRow($record, $target, $seenPhones);
            if ($rowErrors !== []) {
                foreach ($rowErrors as $error) {
                    $errors[] = $error + ['row' => $lineNumber];
                }
            }
            $rows[] = $record;
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new \DomainException('حداکثر ' . self::MAX_ROWS . ' ردیف در هر بارگذاری مجاز است.');
        }
        return [
            'target' => $target,
            'headers' => $headers,
            'rows' => $rows,
            'errors' => $errors,
            'total' => count($rows),
            'invalid' => count(array_unique(array_column($errors, 'row'))),
        ];
    }

    /**
     * ورود نهایی فهرست شرکت‌کنندگان یک دوره.
     * @param array<int,array<string,mixed>> $rows
     */
    public function commitAttendees(int $eventId, array $rows, int $actorId): array
    {
        $event = $this->db->prepare('SELECT id, title, created_by FROM events WHERE id=? LIMIT 1');
        $event->execute([$eventId]);
        if (!$event->fetch()) {
            throw new \DomainException('دوره پیدا نشد.');
        }
        if ($rows === []) {
            throw new \DomainException('ردیفی برای ورود وجود ندارد.');
        }
        $seenPhones = [];
        $errors = [];
        foreach ($rows as $row) {
            foreach ($this->validateRow($row, 'attendees', $seenPhones) as $error) {
                $errors[] = $error + ['row' => (int)($row['_row'] ?? 0)];
            }
        }
        if ($errors !== []) {
            throw new \DomainException(count(array_unique(array_column($errors, 'row'))) . ' ردیف نامعتبر است؛ هیچ داده‌ای وارد نشد. فهرست خطاها را ببینید.');
        }
        $created = 0;
        $existingUsers = 0;
        $skipped = 0;
        $imported = 0;
        $this->db->beginTransaction();
        try {
            foreach ($rows as $row) {
                $phone = \normalizePhone((string)($row['phone'] ?? ''));
                $name = trim((string)($row['name'] ?? ''));
                $nationalId = IranianNationalId::normalize((string)($row['national_id'] ?? ''));
                $municipality = '';
                $isEmployee = false;
                if ($nationalId !== '' && IranianNationalId::isValid($nationalId)) {
                    $hmac = IranianNationalId::hmac($nationalId, (string)$this->config['app']['employee_hmac_key']);
                    $check = $this->db->prepare('SELECT municipality FROM employee_allowlist WHERE national_id_hmac=? AND is_active=1 LIMIT 1');
                    $check->execute([$hmac]);
                    $municipality = (string)$check->fetchColumn();
                    $isEmployee = $municipality !== '';
                }
                $userStmt = $this->db->prepare('SELECT id FROM users WHERE phone=? LIMIT 1');
                $userStmt->execute([$phone]);
                $userId = (int)$userStmt->fetchColumn();
                if ($userId < 1) {
                    $random = bin2hex(random_bytes(24));
                    $insert = $this->db->prepare('INSERT INTO users (phone, full_name, municipality, password_hash, account_type, phone_verified_at, employee_verified_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(),?)');
                    $insert->execute([
                        $phone,
                        $name,
                        $municipality !== '' ? $municipality : null,
                        password_hash(\passwordMaterial($random), PASSWORD_DEFAULT),
                        $isEmployee ? 'municipal' : 'public',
                        $isEmployee ? gmdate('Y-m-d H:i:s') : null,
                    ]);
                    $userId = (int)$this->db->lastInsertId();
                    $role = $this->db->prepare("SELECT id FROM roles WHERE code='attendee'");
                    $role->execute();
                    $roleId = (int)$role->fetchColumn();
                    if ($roleId > 0) {
                        $this->db->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?,?)')->execute([$userId, $roleId]);
                    }
                    $created++;
                } else {
                    $existingUsers++;
                }
                $registrationCheck = $this->db->prepare('SELECT id FROM registrations WHERE event_id=? AND user_id=? AND status IN (\'confirmed\',\'pending_payment\',\'waitlisted\') LIMIT 1');
                $registrationCheck->execute([$eventId, $userId]);
                if ($registrationCheck->fetchColumn()) {
                    // ردیف تکراری رد می‌شود ولی کل ورود را باطل نمی‌کند.
                    $skipped++;
                    continue;
                }
                $insertRegistration = $this->db->prepare("INSERT INTO registrations (event_id, user_id, account_type, status, idempotency_key) VALUES (?,?,?, 'confirmed', ?)");
                $insertRegistration->execute([$eventId, $userId, $isEmployee ? 'municipal' : 'public', \Eroydad\Registration\RegistrationService::uuidV4()]);
                $registrationId = (int)$this->db->lastInsertId();
                $code = \Eroydad\Registration\RegistrationService::uuidV4();
                $this->db->prepare("INSERT INTO tickets (registration_id, public_code, status, issued_by) VALUES (?, ?, 'issued', ?)")->execute([$registrationId, $code, $actorId]);
                \auditLog($actorId, 'import.attendees.row', 'registration', (string)$registrationId, ['event_id' => $eventId, 'imported' => true]);
                $imported++;
            }
            \auditLog($actorId, 'import.attendees.committed', 'event', (string)$eventId, ['rows' => count($rows), 'created_users' => $created, 'skipped' => $skipped]);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        return ['rows' => count($rows), 'created_users' => $created, 'existing_users' => $existingUsers, 'skipped' => $skipped, 'imported' => $imported];
    }

    /**
     * ورود فهرست کارکنان.
     * @param array<int,array<string,mixed>> $rows
     */
    public function commitEmployees(array $rows, int $actorId): array
    {
        if ($rows === []) {
            throw new \DomainException('ردیفی برای ورود وجود ندارد.');
        }
        $csv = "national_id,municipality\n";
        $errors = [];
        foreach ($rows as $row) {
            $nationalId = IranianNationalId::normalize((string)($row['national_id'] ?? ''));
            $municipality = trim((string)($row['municipality'] ?? ''));
            if (!IranianNationalId::isValid($nationalId)) {
                $errors[] = ['row' => (int)($row['_row'] ?? 0), 'column' => 'national_id', 'message' => 'کد ملی معتبر نیست.'];
                continue;
            }
            if ($municipality === '' || mb_strlen($municipality, 'UTF-8') > 160) {
                $errors[] = ['row' => (int)($row['_row'] ?? 0), 'column' => 'municipality', 'message' => 'نام شهرداری نامعتبر است.'];
                continue;
            }
            $csv .= $nationalId . ',' . str_replace([',', '"'], '', $municipality) . "\n";
        }
        if ($errors !== []) {
            throw new \DomainException(count($errors) . ' ردیف نامعتبر است؛ هیچ داده‌ای وارد نشد.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'erooydad-import-');
        if ($tmp === false) {
            throw new \RuntimeException('فایل موقت ساخته نشد.');
        }
        file_put_contents($tmp, $csv);
        try {
            $service = new EmployeeImportService($this->db, (string)$this->config['app']['employee_hmac_key']);
            return $service->import($tmp, $actorId);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<int,array<string,string>>
     */
    private function validateRow(array $row, string $target, array &$seenPhones): array
    {
        $errors = [];
        if ($target === 'employees') {
            $nationalId = IranianNationalId::normalize((string)($row['national_id'] ?? ''));
            if ($nationalId === '' || !IranianNationalId::isValid($nationalId)) {
                $errors[] = ['column' => 'کد ملی', 'message' => 'کد ملی معتبر نیست.'];
            }
            $municipality = trim((string)($row['municipality'] ?? ''));
            if ($municipality === '' || mb_strlen($municipality, 'UTF-8') > 160) {
                $errors[] = ['column' => 'شهرداری', 'message' => 'نام شهرداری الزامی است.'];
            }
            return $errors;
        }
        $name = trim((string)($row['name'] ?? ''));
        if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 160) {
            $errors[] = ['column' => 'نام', 'message' => 'نام باید بین ۲ تا ۱۶۰ نویسه باشد.'];
        }
        $phone = (string)($row['phone'] ?? '');
        if (!\validIranMobile($phone)) {
            $errors[] = ['column' => 'موبایل', 'message' => 'شمارهٔ موبایل معتبر نیست.'];
        } else {
            $normalized = \normalizePhone($phone);
            if (isset($seenPhones[$normalized])) {
                $errors[] = ['column' => 'موبایل', 'message' => 'این شماره در فایل تکرار شده است.'];
            }
            $seenPhones[$normalized] = true;
        }
        $nationalId = IranianNationalId::normalize((string)($row['national_id'] ?? ''));
        if ($nationalId !== '' && !IranianNationalId::isValid($nationalId)) {
            $errors[] = ['column' => 'کد ملی', 'message' => 'کد ملی واردشده معتبر نیست.'];
        }
        return $errors;
    }

    /** @return array<int,array<int,string>> */
    private function readCsv(string $path): array
    {
        $content = (string)file_get_contents($path);
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $content);
        $delimiter = ',';
        $firstLine = $lines[0] ?? '';
        $tabs = substr_count($firstLine, "\t");
        $semis = substr_count($firstLine, ';');
        $commas = substr_count($firstLine, ',');
        if ($tabs > $commas && $tabs > $semis) {
            $delimiter = "\t";
        } elseif ($semis > $commas) {
            $delimiter = ';';
        }
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = str_getcsv($line, $delimiter);
        }
        return $rows;
    }

    /** @return array<int,array<int,string>> */
    private function readXlsx(string $path): array
    {
        $binary = (string)file_get_contents($path);
        $reader = new XlsxReader($binary);
        return $reader->firstSheetRows();
    }

    private function canonicalHeader(string $header): string
    {
        $header = trim((string)$header);
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $normalized = mb_strtolower($header, 'UTF-8');
        $map = [
            'نام' => 'name', 'نام و نام خانوادگی' => 'name', 'name' => 'name', 'full_name' => 'name',
            'موبایل' => 'phone', 'موبایل/تلفن' => 'phone', 'تلفن' => 'phone', 'phone' => 'phone', 'mobile' => 'phone',
            'کد ملی' => 'national_id', 'کدملی' => 'national_id', 'national_id' => 'national_id', 'کد ملی (کد ملی)' => 'national_id',
            'شهرداری' => 'municipality', 'شهرداری محل خدمت' => 'municipality', 'municipality' => 'municipality', 'سازمان' => 'municipality',
        ];
        return $map[$normalized] ?? ($normalized !== '' ? $normalized : 'column_' . substr(md5($header), 0, 6));
    }
}
