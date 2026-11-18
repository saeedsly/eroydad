-- ای‌رویداد — schema v1 for MariaDB 10.4 / MySQL 8.
-- Import into a NEW utf8mb4 database. No payment or SMS tables/endpoints in this release.
-- Times are stored as UTC DATETIME. Money is integer Toman; paid price is blocked in v1.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone VARCHAR(20) NOT NULL,
  full_name VARCHAR(160) NOT NULL,
  municipality VARCHAR(160) NULL,
  password_hash VARCHAR(255) NOT NULL,
  account_type ENUM('public','municipal') NOT NULL DEFAULT 'public',
  phone_verified_at DATETIME NULL DEFAULT NULL,
  employee_verified_at DATETIME NULL DEFAULT NULL,
  last_login_at DATETIME NULL DEFAULT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
  font_scale TINYINT UNSIGNED NOT NULL DEFAULT 100,
  theme ENUM('light','dark','auto') NOT NULL DEFAULT 'auto',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_phone (phone),
  KEY ix_users_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) NOT NULL,
  label_fa VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id SMALLINT UNSIGNED NOT NULL,
  granted_by BIGINT UNSIGNED NULL,
  granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, role_id),
  KEY ix_user_roles_role (role_id, user_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_user_roles_grantor FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_import_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_by BIGINT UNSIGNED NOT NULL,
  source_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  accepted_rows INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_rows INT UNSIGNED NOT NULL DEFAULT 0,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_employee_batches_recent (imported_at),
  CONSTRAINT fk_employee_batch_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS employee_allowlist (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  national_id_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  municipality VARCHAR(160) NOT NULL,
  import_batch_id BIGINT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_national_hmac (national_id_hmac),
  KEY ix_employee_municipality_active (municipality, is_active),
  CONSTRAINT fk_employee_allowlist_batch FOREIGN KEY (import_batch_id) REFERENCES employee_import_batches(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(180) NOT NULL,
  title VARCHAR(220) NOT NULL,
  summary VARCHAR(500) NOT NULL,
  description MEDIUMTEXT NOT NULL,
  category VARCHAR(100) NOT NULL,
  delivery_mode ENUM('online','in_person','hybrid') NOT NULL DEFAULT 'in_person',
  venue VARCHAR(240) NOT NULL DEFAULT '',
  starts_at_utc DATETIME NOT NULL,
  ends_at_utc DATETIME NOT NULL,
  capacity INT UNSIGNED NOT NULL,
  price_toman BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tax_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  tax_payer ENUM('participant','organizer') NOT NULL DEFAULT 'participant',
  poster_path VARCHAR(255) NULL DEFAULT NULL,
  poster_alt VARCHAR(255) NULL DEFAULT NULL,
  poster_mime VARCHAR(120) NULL DEFAULT NULL,
  instructor_name VARCHAR(160) NULL DEFAULT NULL,
  instructor_bio TEXT NULL,
  is_multi_session TINYINT(1) NOT NULL DEFAULT 0,
  preview_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL,
  status ENUM('draft','published','cancelled','completed') NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NOT NULL,
  published_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_events_slug (slug),
  UNIQUE KEY uq_events_preview_token (preview_token),
  KEY ix_events_catalog (status, starts_at_utc, category),
  KEY ix_events_creator_status (created_by, status),
  CONSTRAINT fk_events_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_events_dates CHECK (ends_at_utc > starts_at_utc),
  CONSTRAINT chk_events_capacity CHECK (capacity > 0),
  CONSTRAINT chk_events_published CHECK (status <> 'published' OR published_at IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  account_type ENUM('public','municipal') NOT NULL,
  status ENUM('confirmed','cancelled','waitlisted','pending_payment') NOT NULL DEFAULT 'confirmed',
  idempotency_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cancelled_at DATETIME NULL DEFAULT NULL,
  cancelled_reason VARCHAR(160) NULL DEFAULT NULL,
  waitlist_position INT UNSIGNED NULL DEFAULT NULL,
  waitlisted_at DATETIME NULL DEFAULT NULL,
  amount_base_toman BIGINT UNSIGNED NOT NULL DEFAULT 0,
  amount_tax_toman BIGINT UNSIGNED NOT NULL DEFAULT 0,
  amount_total_toman BIGINT UNSIGNED NOT NULL DEFAULT 0,
  organisation_completed TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_registration_once (event_id, user_id),
  UNIQUE KEY uq_registration_idempotency (idempotency_key),
  KEY ix_registrations_event_status (event_id, status, registered_at),
  KEY ix_registrations_status_event (event_id, status, waitlist_position),
  KEY ix_registrations_user_recent (user_id, registered_at),
  CONSTRAINT fk_registrations_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_registrations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  registration_id BIGINT UNSIGNED NOT NULL,
  public_code CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('issued','revoked') NOT NULL DEFAULT 'issued',
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  issued_by BIGINT UNSIGNED NULL DEFAULT NULL,
  revoked_at DATETIME NULL DEFAULT NULL,
  reissued_from BIGINT UNSIGNED NULL DEFAULT NULL,
  reissue_count INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_registration (registration_id),
  UNIQUE KEY uq_ticket_public_code (public_code),
  CONSTRAINT fk_tickets_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checkins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id BIGINT UNSIGNED NOT NULL,
  checked_by BIGINT UNSIGNED NOT NULL,
  checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  source ENUM('online','offline_queue') NOT NULL DEFAULT 'online',
  client_ref VARCHAR(64) NULL DEFAULT NULL,
  synced_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_checkins_once (ticket_id),
  CONSTRAINT fk_checkins_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_checkins_user FOREIGN KEY (checked_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_tickets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NULL,
  subject VARCHAR(180) NOT NULL,
  status ENUM('open','in_progress','answered','closed') NOT NULL DEFAULT 'open',
  priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  assigned_to BIGINT UNSIGNED NULL DEFAULT NULL,
  first_response_at DATETIME NULL DEFAULT NULL,
  sla_due_at DATETIME NULL DEFAULT NULL,
  resolved_at DATETIME NULL DEFAULT NULL,
  satisfaction_rating TINYINT UNSIGNED NULL DEFAULT NULL,
  satisfaction_comment VARCHAR(255) NULL DEFAULT NULL,
  updated_by BIGINT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_support_user_recent (user_id, created_at),
  KEY ix_support_status_recent (status, updated_at),
  CONSTRAINT fk_support_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_support_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id BIGINT UNSIGNED NOT NULL,
  author_id BIGINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_support_messages_ticket (ticket_id, created_at),
  CONSTRAINT fk_support_message_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_support_message_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ip_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  was_successful TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_login_attempt_phone_time (phone_hmac, attempted_at),
  KEY ix_login_attempt_ip_time (ip_hmac, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS registration_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ip_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_registration_attempt_phone_time (phone_hmac, attempted_at),
  KEY ix_registration_attempt_ip_time (ip_hmac, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(60) NOT NULL,
  entity_id VARCHAR(80) NULL,
  request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  safe_details TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_audit_recent (created_at),
  KEY ix_audit_actor_recent (actor_id, created_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(80) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('001_initial_schema');

INSERT IGNORE INTO roles (code, label_fa) VALUES
 ('attendee','فراگیر'),
 ('event_admin','مدیر رویداد'),
 ('support','پشتیبان'),
 ('system_admin','مدیر سامانه');

-- ===== افزوده‌های نسخهٔ ۲ (مهاجرت ۰۰۲) =====
CREATE TABLE IF NOT EXISTS user_preferences (
  user_id BIGINT UNSIGNED NOT NULL,
  theme ENUM('light','dark','auto') NOT NULL DEFAULT 'auto',
  font_scale TINYINT UNSIGNED NOT NULL DEFAULT 100,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_prefs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(60) NOT NULL DEFAULT 'general',
  title VARCHAR(190) NOT NULL,
  body VARCHAR(500) NOT NULL DEFAULT '',
  link VARCHAR(255) NULL,
  dedupe_key VARCHAR(120) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notifications_dedupe (dedupe_key),
  KEY ix_notifications_user_recent (user_id, is_read, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(80) NOT NULL,
  label_fa VARCHAR(160) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id SMALLINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_perm_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_perm_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_sessions_hash (session_hash),
  KEY ix_user_sessions_user (user_id, revoked_at),
  CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS two_factor (
  user_id BIGINT UNSIGNED NOT NULL,
  secret VARCHAR(64) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  recovery_codes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_two_factor_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  starts_at_utc DATETIME NOT NULL,
  ends_at_utc DATETIME NOT NULL,
  venue VARCHAR(240) NOT NULL DEFAULT '',
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_event_sessions_event (event_id, sort_order),
  CONSTRAINT fk_event_sessions_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_tags (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id BIGINT UNSIGNED NOT NULL,
  tag VARCHAR(60) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_tags_unique (event_id, tag),
  KEY ix_event_tags_tag (tag),
  CONSTRAINT fk_event_tags_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_attachments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS certificates (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feedbacks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  registration_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment VARCHAR(800) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_feedbacks_registration (registration_id),
  KEY ix_feedbacks_event (event_id),
  CONSTRAINT fk_feedbacks_registration FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
  CONSTRAINT fk_feedbacks_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_feedbacks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organization_profiles (
  user_id BIGINT UNSIGNED NOT NULL,
  organization_name VARCHAR(190) NOT NULL,
  national_id VARCHAR(20) NOT NULL DEFAULT '',
  address VARCHAR(240) NOT NULL DEFAULT '',
  contact_email VARCHAR(160) NOT NULL DEFAULT '',
  contact_phone VARCHAR(20) NOT NULL DEFAULT '',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_org_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  registration_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(40) NOT NULL DEFAULT 'sandbox',
  is_sandbox TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('pending','paid','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  base_toman BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tax_toman BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_toman BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tax_payer ENUM('participant','organizer') NOT NULL DEFAULT 'participant',
  scenario VARCHAR(40) NOT NULL DEFAULT 'success',
  reference_code VARCHAR(64) NOT NULL,
  failure_reason VARCHAR(190) NOT NULL DEFAULT '',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  entry_type ENUM('charge','refund','adjustment') NOT NULL,
  amount_toman BIGINT NOT NULL DEFAULT 0,
  note VARCHAR(255) NOT NULL DEFAULT '',
  actor_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_ledger_event (event_id, created_at),
  KEY ix_ledger_payment (payment_id),
  CONSTRAINT fk_ledger_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  CONSTRAINT fk_ledger_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_ledger_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_labels (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(60) NOT NULL,
  color VARCHAR(20) NOT NULL DEFAULT '#23764e',
  PRIMARY KEY (id),
  UNIQUE KEY uq_support_labels_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_ticket_labels (
  ticket_id BIGINT UNSIGNED NOT NULL,
  label_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (ticket_id, label_id),
  CONSTRAINT fk_ticket_labels_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_labels_label FOREIGN KEY (label_id) REFERENCES support_labels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS canned_responses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  role_code VARCHAR(40) NOT NULL DEFAULT 'support',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_canned_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS migration_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version VARCHAR(80) NOT NULL,
  status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
  message TEXT NULL,
  steps_total INT UNSIGNED NOT NULL DEFAULT 0,
  steps_applied INT UNSIGNED NOT NULL DEFAULT 0,
  steps_skipped INT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY ix_migration_runs_recent (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_name VARCHAR(190) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tables_count INT UNSIGNED NOT NULL DEFAULT 0,
  rows_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  status ENUM('ok','failed') NOT NULL DEFAULT 'ok',
  message VARCHAR(255) NOT NULL DEFAULT '',
  verified_at DATETIME NULL DEFAULT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_backup_runs_recent (created_at),
  CONSTRAINT fk_backup_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_confirmations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_retention_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  policy VARCHAR(80) NOT NULL,
  deleted_rows INT UNSIGNED NOT NULL DEFAULT 0,
  note VARCHAR(255) NOT NULL DEFAULT '',
  actor_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_retention_recent (created_at),
  CONSTRAINT fk_retention_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('002_feature_set');

INSERT IGNORE INTO permissions (code, label_fa) VALUES
 ('events.view_admin','مشاهدهٔ فهرست رویدادها'),
 ('events.create','ساخت رویداد'),
 ('events.edit','ویرایش رویداد'),
 ('events.publish','انتشار رویداد'),
 ('events.cancel','لغو رویداد'),
 ('events.manage_content','مدیریت پوستر، جلسات، برچسب و پیوست'),
 ('attendees.view','مشاهدهٔ فهرست شرکت‌کنندگان'),
 ('attendees.manage','ثبت‌نام دستی و لغو شرکت‌کننده'),
 ('tickets.checkin','کنترل ورود'),
 ('tickets.reissue','بازصدور بلیت'),
 ('certificates.issue','صدور گواهی'),
 ('payments.view','مشاهدهٔ اسناد مالی آزمایشی'),
 ('payments.refund','استرداد آزمایشی'),
 ('reports.view','مشاهدهٔ گزارش‌های مدیریتی'),
 ('reports.export','خروجی Excel گزارش‌ها'),
 ('import.employees','ورود فهرست کارکنان'),
 ('import.attendees','ورود فهرست شرکت‌کنندگان'),
 ('support.manage','مدیریت تیکت‌های پشتیبانی'),
 ('users.manage','مدیریت کاربران و نقش‌ها'),
 ('audit.view','مشاهدهٔ گزارش فعالیت سامانه'),
 ('system.health','بررسی سلامت سامانه و پشتیبان‌گیری'),
 ('settings.manage','تنظیمات سامانه و نگهداری داده');

INSERT IGNORE INTO support_labels (name, color) VALUES
 ('فنی','#23764e'),('مالی','#a4712a'),('ثبت‌نام','#2f6f8f'),('محتوا','#7a4f8f'),('انتقاد و پیشنهاد','#8a5a2a');

INSERT IGNORE INTO canned_responses (title, body, role_code) VALUES
 ('خوش‌آمدگویی','سلام. درخواست شما دریافت شد و در نخستین فرصت بررسی می‌شود. با سپاس.','support'),
 ('تکمیل اطلاعات سازمانی','برای ثبت‌نام نهایی، لطفاً نام سازمان، شناسهٔ ملی و نشانی را در پنل فراگیر تکمیل کنید.','support'),
 ('رفع خطای ثبت‌نام','ثبت‌نام شما انجام شد اما بلیت صادر نشد. لطفاً صفحه را تازه کنید یا با پشتیبانی تماس بگیرید.','support');

-- نگاشت پیش‌فرض نقش‌ها به مجوزها
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code = 'event_admin' AND p.code IN
 ('events.view_admin','events.create','events.edit','events.publish','events.cancel','events.manage_content','attendees.view','attendees.manage','tickets.checkin','tickets.reissue','certificates.issue','payments.view','reports.view','reports.export','import.attendees');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code = 'support' AND p.code IN
 ('support.manage','attendees.view','events.view_admin','reports.view');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p WHERE r.code = 'system_admin';
