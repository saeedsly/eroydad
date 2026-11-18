<?php
declare(strict_types=1);

namespace Eroydad\Employees;

use Eroydad\Security\IranianNationalId;
use PDO;

final class EmployeeImportService
{
    private PDO $db;
    private string $hmacKey;

    public function __construct(PDO $db, string $hmacKey)
    {
        $this->db=$db;
        $this->hmacKey=$hmacKey;
    }

    public function import(string $tmpPath, int $adminId): array
    {
        if (!is_file($tmpPath) || filesize($tmpPath) > 2_000_000) throw new \DomainException('فایل CSV نامعتبر است یا از ۲ مگابایت بزرگ‌تر است.');
        $handle = fopen($tmpPath, 'rb');
        if (!$handle) throw new \DomainException('فایل CSV خوانده نشد.');
        $fingerprint = hash_file('sha256', $tmpPath) ?: hash('sha256', random_bytes(32));
        $accepted = 0; $rejected = 0; $line = 0;
        try {
            $header = fgetcsv($handle);
            if (!is_array($header)) throw new \DomainException('فایل خالی است.');
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
            $header = array_map(static fn($v) => strtolower(trim((string)$v)), $header);
            $idIndex = array_search('national_id', $header, true);
            $municipalityIndex = array_search('municipality', $header, true);
            if (count($header)!==2 || $idIndex === false || $municipalityIndex === false) throw new \DomainException('فایل CSV باید دقیقاً دو ستون national_id و municipality داشته باشد.');

            $this->db->beginTransaction();
            $batch = $this->db->prepare('INSERT INTO employee_import_batches (created_by,source_fingerprint,accepted_rows,rejected_rows) VALUES (?,?,0,0)');
            $batch->execute([$adminId,$fingerprint]);
            $batchId = (int)$this->db->lastInsertId();
            $insert = $this->db->prepare('INSERT INTO employee_allowlist (national_id_hmac,municipality,import_batch_id,is_active) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE municipality=VALUES(municipality),import_batch_id=VALUES(import_batch_id),is_active=1');
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if ($line > 20000) throw new \DomainException('حداکثر ۲۰۰۰۰ ردیف در هر بارگذاری مجاز است.');
                if (!isset($row[$idIndex], $row[$municipalityIndex])) { $rejected++; continue; }
                $nationalId = IranianNationalId::normalize((string)$row[$idIndex]);
                $municipality = trim((string)$row[$municipalityIndex]);
                if (!IranianNationalId::isValid($nationalId) || $municipality === '' || mb_strlen($municipality,'UTF-8') > 160) { $rejected++; continue; }
                $insert->execute([IranianNationalId::hmac($nationalId,$this->hmacKey),$municipality,$batchId]);
                $accepted++;
            }
            if ($accepted === 0) throw new \DomainException('هیچ ردیف معتبری برای ورود پیدا نشد.');
            $upd = $this->db->prepare('UPDATE employee_import_batches SET accepted_rows=?,rejected_rows=? WHERE id=?');
            $upd->execute([$accepted,$rejected,$batchId]);
            \auditLog($adminId, 'employee.allowlist.import', 'employee_import_batch', (string)$batchId, ['accepted_rows'=>$accepted,'rejected_rows'=>$rejected,'source_fingerprint'=>$fingerprint]);
            $this->db->commit();
            return ['batch_id'=>$batchId,'accepted'=>$accepted,'rejected'=>$rejected];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        } finally {
            fclose($handle);
            // PHP normally removes upload tmp files at request end; explicitly unlink our temp file too.
            if (is_uploaded_file($tmpPath)) @unlink($tmpPath);
        }
    }
}
