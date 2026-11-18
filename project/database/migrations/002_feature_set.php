<?php
declare(strict_types=1);

/**
 * مهاجرت ۰۰۲ — افزودن قابلیت‌های گستردهٔ سامانه (پوستر، مالیات و درگاه آزمایشی،
 * اعلان‌ها، گواهی، بازخورد، مجوزها، نشست‌ها، ۲FA، گزارش‌ها، پشتیبان‌گیری و ...).
 *
 * این مهاجرت «افزایشی» است: روی دیتابیس نصب‌شده اجرا می‌شود و داده‌های موجود را حذف نمی‌کند.
 * runner هر گام را با بررسی وضعیت (guard) اجرا می‌کند تا اجرای دوباره بی‌خطر باشد.
 *
 * @return array<string,mixed>
 */
if (!class_exists(\Eroydad\Security\Permissions::class, false)) {
    require_once dirname(__DIR__, 2) . '/backend/src/Security/Permissions.php';
}

return [
    'version' => '002_feature_set',
    'title' => 'قابلیت‌های گسترده: پوستر، مالیات آزمایشی، اعلان، گواهی، مجوز و گزارش',
    'steps' => [
        // ---------- تنظیمات کاربر ----------
        ['sql' => 'CREATE TABLE IF NOT EXISTS user_preferences (
  user_id BIGINT UNSIGNED NOT NULL,
  theme ENUM(\'light\',\'dark\',\'auto\') NOT NULL DEFAULT \'auto\',
  font_scale TINYINT UNSIGNED NOT NULL DEFAULT 100,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_prefs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        // ---------- اعلان‌های درون‌برنامه‌ای ----------
        ['sql' => 'CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(60) NOT NULL DEFAULT \'general\',
  title VARCHAR(190) NOT NULL,
  body VARCHAR(500) NOT NULL DEFAULT \'\',
  link VARCHAR(255) NULL,
  dedupe_key VARCHAR(120) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notifications_dedupe (dedupe_key),
  KEY ix_notifications_user_recent (user_id, is_read, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        // ---------- مجوزها ----------
        ['sql' => 'CREATE TABLE IF NOT EXISTS permissions (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(80) NOT NULL,
  label_fa VARCHAR(160) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        ['sql' => 'CREATE TABLE IF NOT EXISTS role_permissions (
  role_id SMALLINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_perm_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_perm_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        // ---------- نشست‌های کاربر ----------
        ['sql' => 'CREATE TABLE IF NOT EXISTS user_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT \'\',
  user_agent VARCHAR(255) NOT NULL DEFAULT \'\',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_sessions_hash (session_hash),
  KEY ix_user_sessions_user (user_id, revoked_at),
  CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        // ---------- بازیابی رمز ----------
        ['sql' => 'CREATE TABLE IF NOT EXISTS password_resets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_resets_hash (code_hash),
  KEY ix_password_resets_user (user_id, expires_at),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_password_resets_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        // ---------- احراز هویت دومرحله‌ای ----------
        ['sql' => 'CREATE TABLE IF NOT EXISTS two_factor (
  user_id BIGINT UNSIGNED NOT NULL,
  secret VARCHAR(64) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  recovery_codes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_two_factor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        // ---------- جلسات چندگانهٔ دوره ----------
        ['sql' => 'CREATE TABLE IF NOT EXISTS event_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  starts_at_utc DATETIME NOT NULL,
  ends_at_utc DATETIME NOT NULL,
  venue VARCHAR(240) NOT NULL DEFAULT \'\',
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_event_sessions_event (event_id, sort_order),
  CONSTRAINT fk_event_sessions_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        // ---------- برچسب‌ها ----------
        ['sql' => 'CREATE TABLE IF NOT EXISTS event_tags (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  tag VARCHAR(60) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_tags_unique (event_id, tag),
  KEY ix_event_tags_tag (tag),
  CONSTRAINT fk_event_tags_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        // ---------- پیوست‌ها ----------
        ['sql' => 'CREATE TABLE IF NOT EXISTS event_attachments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  stored_name VARCHAR(190) NOT NULL,
  original_name VARCHAR(190) NOT NULL,
  mime VARCHAR(120) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_event_attachments_event (event_id),
  CONSTRAINT fk_event_attachments_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_attachments_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        // ---------- گواهی پایان دوره ----------
        ['sql' => 'CREATE TABLE IF NOT EXISTS certificates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  registration_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  code CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  issued_by BIGINT UNSIGNED NULL,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_certificates_registration (registration_id),
  UNIQUE KEY uq_certificates_code (code),
  CONSTRAINT fk_certificates_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
  CONSTRAINT fk_certificates_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_certificates_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_certificates_issuer FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        // ---------- بازخورد ----------
        ['sql' => 'CREATE TABLE IF NOT EXISTS feedbacks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  registration_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment VARCHAR(800) NOT NULL DEFAULT \'\',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_feedbacks_registration (registration_id),
  KEY ix_feedbacks_event (event_id),
  CONSTRAINT fk_feedbacks_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
  CONSTRAINT fk_feedbacks_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_feedbacks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        // ---------- پروفایل سازمانی ----------
        ['sql' => 'CREATE TABLE IF NOT EXISTS organization_profiles (
  user_id BIGINT UNSIGNED NOT NULL,
  organization_name VARCHAR(190) NOT NULL,
  national_id VARCHAR(20) NOT NULL DEFAULT \'\',
  address VARCHAR(240) NOT NULL DEFAULT \'\',
  contact_email VARCHAR(160) NOT NULL DEFAULT \'\',
  contact_phone VARCHAR(20) NOT NULL DEFAULT \'\',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_org_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        // ---------- پرداخت آزمایشی ----------
        ['sql' => 'CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  registration_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(40) NOT NULL DEFAULT \'sandbox\',
  is_sandbox TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM(\'pending\',\'paid\',\'failed\',\'cancelled\',\'refunded\') NOT NULL DEFAULT \'pending\',
  base_toman BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tax_toman BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_toman BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tax_payer ENUM(\'participant\',\'organizer\') NOT NULL DEFAULT \'participant\',
  scenario VARCHAR(40) NOT NULL DEFAULT \'success\',
  reference_code VARCHAR(64) NOT NULL,
  failure_reason VARCHAR(190) NOT NULL DEFAULT \'\',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL DEFAULT NULL,
  refunded_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_reference (reference_code),
  KEY ix_payments_event_status (event_id, status, created_at),
  KEY ix_payments_user (user_id, created_at),
  CONSTRAINT fk_payments_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
  CONSTRAINT fk_payments_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        ['sql' => 'CREATE TABLE IF NOT EXISTS payment_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  entry_type ENUM(\'charge\',\'refund\',\'adjustment\') NOT NULL,
  amount_toman BIGINT NOT NULL DEFAULT 0,
  note VARCHAR(255) NOT NULL DEFAULT \'\',
  actor_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_ledger_event (event_id, created_at),
  KEY ix_ledger_payment (payment_id),
  CONSTRAINT fk_ledger_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  CONSTRAINT fk_ledger_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_ledger_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        // ---------- پشتیبانی: برچسب، اولویت، SLA ----------
        ['sql' => 'CREATE TABLE IF NOT EXISTS support_labels (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(60) NOT NULL,
  color VARCHAR(20) NOT NULL DEFAULT \'#23764e\',
  PRIMARY KEY (id),
  UNIQUE KEY uq_support_labels_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        ['sql' => 'CREATE TABLE IF NOT EXISTS support_ticket_labels (
  ticket_id BIGINT UNSIGNED NOT NULL,
  label_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (ticket_id, label_id),
  CONSTRAINT fk_ticket_labels_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_labels_label FOREIGN KEY (label_id) REFERENCES support_labels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        ['sql' => 'CREATE TABLE IF NOT EXISTS canned_responses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  role_code VARCHAR(40) NOT NULL DEFAULT \'support\',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_canned_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        // ---------- تاریخچهٔ مهاجرت و پشتیبان ----------
        ['sql' => 'CREATE TABLE IF NOT EXISTS migration_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version VARCHAR(80) NOT NULL,
  status ENUM(\'running\',\'success\',\'failed\') NOT NULL DEFAULT \'running\',
  message TEXT NULL,
  steps_total INT UNSIGNED NOT NULL DEFAULT 0,
  steps_applied INT UNSIGNED NOT NULL DEFAULT 0,
  steps_skipped INT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_migration_runs_recent (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        ['sql' => 'CREATE TABLE IF NOT EXISTS backup_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_name VARCHAR(190) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tables_count INT UNSIGNED NOT NULL DEFAULT 0,
  rows_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'\',
  status ENUM(\'ok\',\'failed\') NOT NULL DEFAULT \'ok\',
  message VARCHAR(255) NOT NULL DEFAULT \'\',
  verified_at DATETIME NULL DEFAULT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_backup_runs_recent (created_at),
  CONSTRAINT fk_backup_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        // ---------- تأیید عملیات حساس ----------
        ['sql' => 'CREATE TABLE IF NOT EXISTS admin_confirmations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(60) NOT NULL,
  entity_id VARCHAR(80) NULL,
  token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_confirmations_token (token),
  KEY ix_admin_confirmations_actor (actor_id, confirmed_at),
  CONSTRAINT fk_admin_confirmations_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        ['sql' => 'CREATE TABLE IF NOT EXISTS data_retention_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  policy VARCHAR(80) NOT NULL,
  deleted_rows INT UNSIGNED NOT NULL DEFAULT 0,
  note VARCHAR(255) NOT NULL DEFAULT \'\',
  actor_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_retention_recent (created_at),
  CONSTRAINT fk_retention_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'],

        // ---------- تغییرات ستونی ----------
        ['sql' => 'ALTER TABLE events ADD COLUMN poster_path VARCHAR(255) NULL DEFAULT NULL', 'guard' => 'column:events:poster_path'],
        ['sql' => 'ALTER TABLE events ADD COLUMN poster_alt VARCHAR(255) NULL DEFAULT NULL', 'guard' => 'column:events:poster_alt'],
        ['sql' => 'ALTER TABLE events ADD COLUMN poster_mime VARCHAR(120) NULL DEFAULT NULL', 'guard' => 'column:events:poster_mime'],
        ['sql' => 'ALTER TABLE events ADD COLUMN tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00', 'guard' => 'column:events:tax_percent'],
        ['sql' => 'ALTER TABLE events ADD COLUMN tax_payer ENUM(\'participant\',\'organizer\') NOT NULL DEFAULT \'participant\'', 'guard' => 'column:events:tax_payer'],
        ['sql' => 'ALTER TABLE events ADD COLUMN instructor_name VARCHAR(160) NULL DEFAULT NULL', 'guard' => 'column:events:instructor_name'],
        ['sql' => 'ALTER TABLE events ADD COLUMN instructor_bio TEXT NULL', 'guard' => 'column:events:instructor_bio'],
        ['sql' => 'ALTER TABLE events ADD COLUMN is_multi_session TINYINT(1) NOT NULL DEFAULT 0', 'guard' => 'column:events:is_multi_session'],
        ['sql' => 'ALTER TABLE events ADD COLUMN preview_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL', 'guard' => 'column:events:preview_token'],
        ['sql' => 'ALTER TABLE events ADD UNIQUE KEY uq_events_preview_token (preview_token)', 'guard' => 'index:events:uq_events_preview_token'],

        ['sql' => 'ALTER TABLE registrations MODIFY COLUMN status ENUM(\'confirmed\',\'cancelled\',\'waitlisted\',\'pending_payment\') NOT NULL DEFAULT \'confirmed\'', 'guard' => 'column-enum:registrations:status:waitlisted'],
        ['sql' => 'ALTER TABLE registrations ADD COLUMN waitlist_position INT UNSIGNED NULL DEFAULT NULL', 'guard' => 'column:registrations:waitlist_position'],
        ['sql' => 'ALTER TABLE registrations ADD COLUMN waitlisted_at DATETIME NULL DEFAULT NULL', 'guard' => 'column:registrations:waitlisted_at'],
        ['sql' => 'ALTER TABLE registrations ADD COLUMN amount_base_toman BIGINT UNSIGNED NOT NULL DEFAULT 0', 'guard' => 'column:registrations:amount_base_toman'],
        ['sql' => 'ALTER TABLE registrations ADD COLUMN amount_tax_toman BIGINT UNSIGNED NOT NULL DEFAULT 0', 'guard' => 'column:registrations:amount_tax_toman'],
        ['sql' => 'ALTER TABLE registrations ADD COLUMN amount_total_toman BIGINT UNSIGNED NOT NULL DEFAULT 0', 'guard' => 'column:registrations:amount_total_toman'],
        ['sql' => 'ALTER TABLE registrations ADD COLUMN cancelled_reason VARCHAR(160) NULL DEFAULT NULL', 'guard' => 'column:registrations:cancelled_reason'],
        ['sql' => 'ALTER TABLE registrations ADD COLUMN organisation_completed TINYINT(1) NOT NULL DEFAULT 0', 'guard' => 'column:registrations:organisation_completed'],
        ['sql' => 'ALTER TABLE registrations ADD KEY ix_registrations_status_event (event_id, status, waitlist_position)', 'guard' => 'index:registrations:ix_registrations_status_event'],

        ['sql' => 'ALTER TABLE support_tickets ADD COLUMN priority ENUM(\'low\',\'normal\',\'high\',\'urgent\') NOT NULL DEFAULT \'normal\'', 'guard' => 'column:support_tickets:priority'],
        ['sql' => 'ALTER TABLE support_tickets ADD COLUMN assigned_to BIGINT UNSIGNED NULL DEFAULT NULL', 'guard' => 'column:support_tickets:assigned_to'],
        ['sql' => 'ALTER TABLE support_tickets ADD COLUMN first_response_at DATETIME NULL DEFAULT NULL', 'guard' => 'column:support_tickets:first_response_at'],
        ['sql' => 'ALTER TABLE support_tickets ADD COLUMN sla_due_at DATETIME NULL DEFAULT NULL', 'guard' => 'column:support_tickets:sla_due_at'],
        ['sql' => 'ALTER TABLE support_tickets ADD COLUMN resolved_at DATETIME NULL DEFAULT NULL', 'guard' => 'column:support_tickets:resolved_at'],
        ['sql' => 'ALTER TABLE support_tickets ADD COLUMN satisfaction_rating TINYINT UNSIGNED NULL DEFAULT NULL', 'guard' => 'column:support_tickets:satisfaction_rating'],
        ['sql' => 'ALTER TABLE support_tickets ADD COLUMN satisfaction_comment VARCHAR(255) NULL DEFAULT NULL', 'guard' => 'column:support_tickets:satisfaction_comment'],
        ['sql' => 'ALTER TABLE support_tickets ADD COLUMN updated_by BIGINT UNSIGNED NULL DEFAULT NULL', 'guard' => 'column:support_tickets:updated_by'],

        ['sql' => 'ALTER TABLE tickets ADD COLUMN reissued_from BIGINT UNSIGNED NULL DEFAULT NULL', 'guard' => 'column:tickets:reissued_from'],
        ['sql' => 'ALTER TABLE tickets ADD COLUMN issued_by BIGINT UNSIGNED NULL DEFAULT NULL', 'guard' => 'column:tickets:issued_by'],
        ['sql' => 'ALTER TABLE tickets ADD COLUMN revoked_at DATETIME NULL DEFAULT NULL', 'guard' => 'column:tickets:revoked_at'],
        ['sql' => 'ALTER TABLE tickets ADD COLUMN reissue_count INT UNSIGNED NOT NULL DEFAULT 0', 'guard' => 'column:tickets:reissue_count'],

        ['sql' => 'ALTER TABLE checkins ADD COLUMN source ENUM(\'online\',\'offline_queue\') NOT NULL DEFAULT \'online\'', 'guard' => 'column:checkins:source'],
        ['sql' => 'ALTER TABLE checkins ADD COLUMN client_ref VARCHAR(64) NULL DEFAULT NULL', 'guard' => 'column:checkins:client_ref'],
        ['sql' => 'ALTER TABLE checkins ADD COLUMN synced_at DATETIME NULL DEFAULT NULL', 'guard' => 'column:checkins:synced_at'],

        ['sql' => 'ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0', 'guard' => 'column:users:two_factor_enabled'],
        ['sql' => 'ALTER TABLE users ADD COLUMN font_scale TINYINT UNSIGNED NOT NULL DEFAULT 100', 'guard' => 'column:users:font_scale'],
        ['sql' => 'ALTER TABLE users ADD COLUMN theme ENUM(\'light\',\'dark\',\'auto\') NOT NULL DEFAULT \'auto\'', 'guard' => 'column:users:theme'],

        // ---------- برداشتن قید «فقط رایگان» (پرداخت فقط آزمایشی است) ----------
        [
            'sql' => 'ALTER TABLE events DROP CONSTRAINT chk_events_free_only',
            'alt' => ['ALTER TABLE events DROP CHECK chk_events_free_only', 'ALTER TABLE events DROP INDEX chk_events_free_only'],
            'guard' => 'constraint:events:chk_events_free_only',
        ],
    ],
    'after' => static function (\PDO $db): void {
        // seed مجوزها و نگاشت نقش‌ها
        $catalog = \Eroydad\Security\Permissions::all();
        $insert = $db->prepare('INSERT INTO permissions (code, label_fa) VALUES (?, ?) ON DUPLICATE KEY UPDATE label_fa = VALUES(label_fa)');
        foreach ($catalog as $code => $label) {
            $insert->execute([$code, $label]);
        }
        $permStmt = $db->prepare('SELECT id FROM permissions WHERE code = ?');
        $roleStmt = $db->prepare('SELECT id FROM roles WHERE code = ?');
        $link = $db->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
        foreach (\Eroydad\Security\Permissions::ROLE_DEFAULTS as $roleCode => $permissions) {
            $roleStmt->execute([$roleCode]);
            $roleId = (int)$roleStmt->fetchColumn();
            if ($roleId < 1) {
                continue;
            }
            foreach ($permissions as $permission) {
                $permStmt->execute([$permission]);
                $permissionId = (int)$permStmt->fetchColumn();
                if ($permissionId > 0) {
                    $link->execute([$roleId, $permissionId]);
                }
            }
        }
        // برچسب‌ها و پاسخ‌های آمادهٔ پشتیبانی
        $labels = $db->prepare('INSERT IGNORE INTO support_labels (name, color) VALUES (?, ?)');
        foreach ([['فنی', '#23764e'], ['مالی', '#a4712a'], ['ثبت‌نام', '#2f6f8f'], ['محتوا', '#7a4f8f'], ['انتقاد و پیشنهاد', '#8a5a2a']] as $label) {
            $labels->execute($label);
        }
        $canned = $db->prepare('INSERT INTO canned_responses (title, body, role_code) SELECT ?, ?, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM canned_responses WHERE title = ?)');
        $canned->execute(['خوش‌آمدگویی', 'سلام. درخواست شما دریافت شد و در نخستین فرصت بررسی می‌شود. با سپاس.', 'support', 'خوش‌آمدگویی']);
        $canned->execute(['تکمیل اطلاعات سازمانی', 'برای ثبت‌نام نهایی، لطفاً نام سازمان، شناسهٔ ملی و نشانی را در پنل فراگیر تکمیل کنید.', 'support', 'تکمیل اطلاعات سازمانی']);
        $canned->execute(['رفع خطای ثبت‌نام', 'ثبت‌نام شما انجام شد اما بلیت صادر نشد. لطفاً صفحه را تازه کنید یا با پشتیبانی تماس بگیرید.', 'support', 'رفع خطای ثبت‌نام']);
    },
];
