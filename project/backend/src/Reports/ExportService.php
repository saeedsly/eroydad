<?php
declare(strict_types=1);

namespace Eroydad\Reports;

use Eroydad\Excel\XlsxWriter;

/**
 * ExportService — خروجی Excel (XLSX واقعی) برای بخش‌های سامانه.
 * همهٔ سلول‌ها به‌صورت متن نوشته می‌شوند تا فرمول یا کد اجرایی وارد فایل نشود.
 */
final class ExportService
{
    /** ساخت فایل عمومی XLSX */
    public function generic(string $sheetName, array $headers, array $rows, array $options = []): string
    {
        $writer = new XlsxWriter();
        $table = [];
        $table[] = array_values($headers);
        foreach ($rows as $row) {
            $line = [];
            foreach (array_values((array)$row) as $value) {
                if (is_int($value) || is_float($value)) {
                    $line[] = $value;
                    continue;
                }
                $line[] = self::safeCell((string)$value);
            }
            $table[] = $line;
        }
        $columns = [];
        foreach (array_values($headers) as $header) {
            $columns[] = max(14, min(46, mb_strlen((string)$header, 'UTF-8') + 8));
        }
        $writer->addSheet($sheetName, $table, ['columns' => $columns, 'freeze' => true]);
        return $writer->toBinary();
    }

    /** @return array<int,array<int,mixed>> */
    public function eventsRows(array $events, bool $mask = false): array
    {
        $rows = [];
        foreach ($events as $event) {
            $rows[] = [
                (int)$event['id'],
                (string)$event['title'],
                (string)$event['category'],
                $this->modeLabel((string)($event['delivery_mode'] ?? '')),
                (string)($event['venue'] ?? ''),
                jalaliDate((string)($event['starts_at_utc'] ?? '')),
                (int)($event['capacity'] ?? 0),
                (int)($event['registered_count'] ?? 0),
                (int)($event['price_toman'] ?? 0),
                (string)($event['tax_percent'] ?? '0'),
                $this->taxPayerLabel((string)($event['tax_payer'] ?? 'participant')),
                (string)($event['status'] ?? ''),
                (string)($event['creator_name'] ?? ''),
            ];
        }
        return $rows;
    }

    public function eventsHeaders(): array
    {
        return ['شناسه', 'عنوان', 'دسته‌بندی', 'شیوه برگزاری', 'محل', 'زمان شروع (شمسی)', 'ظرفیت', 'ثبت‌نام قطعی', 'مبلغ پایه (تومان)', 'نرخ مالیات (٪)', 'پرداخت‌کنندهٔ مالیات', 'وضعیت', 'سازنده'];
    }

    public function registrationsRows(array $registrations, bool $mask = false): array
    {
        $rows = [];
        foreach ($registrations as $registration) {
            $phone = (string)($registration['phone'] ?? '');
            $rows[] = [
                (int)($registration['registration_id'] ?? $registration['id'] ?? 0),
                (string)($registration['full_name'] ?? ''),
                $mask ? self::maskPhone($phone) : $phone,
                ($registration['account_type'] ?? '') === 'municipal' ? 'کارمند شهرداری' : 'عمومی',
                (string)($registration['status'] ?? ''),
                (string)($registration['event_title'] ?? ''),
                (string)($registration['registered_at'] ?? ''),
                (string)($registration['checked_at'] ?? ''),
            ];
        }
        return $rows;
    }

    public function registrationsHeaders(): array
    {
        return ['شناسه ثبت‌نام', 'نام و نام خانوادگی', 'موبایل', 'نوع حساب', 'وضعیت ثبت‌نام', 'دوره', 'زمان ثبت‌نام (UTC)', 'زمان ورود (UTC)'];
    }

    public function usersRows(array $users): array
    {
        $rows = [];
        foreach ($users as $user) {
            $rows[] = [
                (int)$user['id'],
                (string)($user['full_name'] ?? ''),
                self::maskPhone((string)($user['phone'] ?? '')),
                ($user['account_type'] ?? '') === 'municipal' ? 'کارمند شهرداری' : 'عمومی',
                (string)($user['status'] ?? ''),
                (string)($user['last_login_at'] ?? ''),
                (string)($user['created_at'] ?? ''),
            ];
        }
        return $rows;
    }

    public function usersHeaders(): array
    {
        return ['شناسه', 'نام', 'موبایل (پوشانده‌شده)', 'نوع حساب', 'وضعیت', 'آخرین ورود (UTC)', 'تاریخ ایجاد (UTC)'];
    }

    public function supportRows(array $tickets): array
    {
        $rows = [];
        foreach ($tickets as $ticket) {
            $rows[] = [
                (int)$ticket['id'],
                (string)($ticket['subject'] ?? ''),
                (string)($ticket['status'] ?? ''),
                (string)($ticket['priority'] ?? 'normal'),
                (string)($ticket['event_title'] ?? ''),
                (string)($ticket['created_at'] ?? ''),
                (string)($ticket['first_response_at'] ?? ''),
                (string)($ticket['resolved_at'] ?? ''),
                $ticket['satisfaction_rating'] !== null ? (int)$ticket['satisfaction_rating'] : '',
                (string)($ticket['assigned_name'] ?? ''),
            ];
        }
        return $rows;
    }

    public function supportHeaders(): array
    {
        return ['شناسه', 'موضوع', 'وضعیت', 'اولویت', 'دوره', 'زمان ایجاد (UTC)', 'اولین پاسخ (UTC)', 'زمان حل (UTC)', 'رضایت', 'مسئول'];
    }

    public function checkinRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                (string)($row['event_title'] ?? ''),
                (string)($row['full_name'] ?? ''),
                self::maskPhone((string)($row['phone'] ?? '')),
                (string)($row['public_code'] ?? ''),
                (string)($row['checked_at'] ?? ''),
                (string)($row['checked_by_name'] ?? ''),
                (string)($row['source'] ?? 'online'),
            ];
        }
        return $result;
    }

    public function checkinHeaders(): array
    {
        return ['دوره', 'شرکت‌کننده', 'موبایل (پوشانده‌شده)', 'کد بلیت', 'زمان ورود (UTC)', 'اپراتور', 'منبع'];
    }

    public function employeesRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                (string)($row['national_id'] ?? ''),
                (string)($row['municipality'] ?? ''),
                (string)($row['created_at'] ?? ''),
                (int)($row['batch_id'] ?? 0),
            ];
        }
        return $result;
    }

    public function employeesHeaders(): array
    {
        return ['کد ملی', 'شهرداری', 'تاریخ ثبت (UTC)', 'شناسهٔ دستهٔ ورود'];
    }

    public function financeRows(array $payments): array
    {
        $rows = [];
        foreach ($payments as $payment) {
            $rows[] = [
                (int)$payment['id'],
                (string)($payment['reference_code'] ?? ''),
                (string)($payment['event_title'] ?? ''),
                (string)($payment['full_name'] ?? ''),
                (int)($payment['base_toman'] ?? 0),
                (int)($payment['tax_toman'] ?? 0),
                (int)($payment['total_toman'] ?? 0),
                (string)($payment['tax_payer'] ?? ''),
                (string)($payment['status'] ?? ''),
                (string)($payment['created_at'] ?? ''),
                (string)($payment['paid_at'] ?? ''),
            ];
        }
        return $rows;
    }

    public function financeHeaders(): array
    {
        return ['شناسه سند', 'کد پیگیری', 'دوره', 'کاربر', 'مبلغ پایه (تومان)', 'مالیات (تومان)', 'جمع (تومان)', 'پرداخت‌کنندهٔ مالیات', 'وضعیت', 'زمان ایجاد (UTC)', 'زمان پرداخت (UTC)'];
    }

    /** جلوگیری از فرمول‌های اجرایی در فایل خروجی */
    public static function safeCell(string $value): string
    {
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/u', $value)) {
            return "'" . $value;
        }
        return $value;
    }

    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 4) {
            return '***';
        }
        return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    private function modeLabel(string $mode): string
    {
        if ($mode === 'online') {
            return 'آنلاین';
        }
        if ($mode === 'hybrid') {
            return 'ترکیبی';
        }
        return 'حضوری';
    }

    private function taxPayerLabel(string $payer): string
    {
        return $payer === 'organizer' ? 'برگزارکننده' : 'فراگیر';
    }
}
