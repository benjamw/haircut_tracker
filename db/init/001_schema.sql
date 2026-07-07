-- Haircut Tracker schema (v3 — table-prefixed primary keys).
-- Every table's PK is <singular_table>_id; FK columns are named after the PK
-- they reference. Money is integer cents. Runs against the selected database.

CREATE TABLE carriers (
  carrier_id         INT AUTO_INCREMENT PRIMARY KEY,
  name               VARCHAR(64)  NOT NULL,
  sms_gateway_domain VARCHAR(128) NOT NULL,
  UNIQUE KEY uq_carrier_name (name)
) ENGINE=InnoDB;

-- Everyone the barber tracks IS a user. Login columns are OPTIONAL.
CREATE TABLE users (
  user_id            INT AUTO_INCREMENT PRIMARY KEY,
  display_name       VARCHAR(128) NOT NULL,
  email              VARCHAR(255) NULL,
  phone              VARCHAR(32)  NULL,
  carrier_id         INT          NULL,
  usual_cadence_days INT          NULL,
  preferred_channel  ENUM('email','sms') NOT NULL DEFAULT 'sms',
  notify_opt_out     TINYINT(1)   NOT NULL DEFAULT 0,
  inactive           TINYINT(1)   NOT NULL DEFAULT 0,
  email_verified_at  DATETIME     NULL,
  phone_verified_at  DATETIME     NULL,
  last_contacted_at  DATETIME     NULL,
  notes              TEXT         NULL,
  merged_into_id     INT          NULL,        -- -> users.user_id
  username           VARCHAR(64)  NULL,
  password_hash      VARCHAR(255) NULL,
  webauthn_challenge VARCHAR(255) NULL,
  role               ENUM('user','admin') NOT NULL DEFAULT 'user',
  status             ENUM('active','blocked') NOT NULL DEFAULT 'active',
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_carrier FOREIGN KEY (carrier_id) REFERENCES carriers(carrier_id) ON DELETE SET NULL,
  CONSTRAINT fk_user_merged  FOREIGN KEY (merged_into_id) REFERENCES users(user_id) ON DELETE SET NULL,
  UNIQUE KEY uq_username (username),
  KEY idx_user_email (email),
  KEY idx_user_phone (phone)
) ENGINE=InnoDB;

CREATE TABLE haircuts (
  haircut_id   INT AUTO_INCREMENT PRIMARY KEY,
  user_id      INT  NOT NULL,
  haircut_date DATE NOT NULL,
  haircut_time TIME NULL,
  amount_cents INT  NOT NULL DEFAULT 0,
  notes        TEXT NULL,
  created_by   VARCHAR(64) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_haircut_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  KEY idx_haircut_user_date (user_id, haircut_date)
) ENGINE=InnoDB;

-- WebAuthn / passkey credentials. The authenticator's credential id is
-- webauthn_credential_id (distinct from the table PK credential_id).
CREATE TABLE credentials (
  credential_id          INT AUTO_INCREMENT PRIMARY KEY,
  user_id                INT NOT NULL,
  webauthn_credential_id VARBINARY(255) NOT NULL,
  public_key             TEXT   NOT NULL,
  sign_count             BIGINT NOT NULL DEFAULT 0,
  transports             VARCHAR(255) NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_webauthn_credential_id (webauthn_credential_id),
  CONSTRAINT fk_credential_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE availability (
  availability_id INT AUTO_INCREMENT PRIMARY KEY,
  weekday         TINYINT NOT NULL,
  start_time      TIME NOT NULL,
  end_time        TIME NOT NULL,
  slot_minutes    INT  NOT NULL DEFAULT 60,
  active          TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE schedule_exceptions (
  schedule_exception_id INT AUTO_INCREMENT PRIMARY KEY,
  kind         ENUM('block','custom') NOT NULL,
  start_date   DATE NOT NULL,
  end_date     DATE NOT NULL,
  all_day      TINYINT(1) NOT NULL DEFAULT 1,
  start_time   TIME NULL,
  end_time     TIME NULL,
  slot_minutes INT  NULL,
  note         VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_exc_range (start_date, end_date)
) ENGINE=InnoDB;

CREATE TABLE appointments (
  appointment_id     INT AUTO_INCREMENT PRIMARY KEY,
  user_id            INT NULL,
  slot_start         DATETIME NOT NULL,
  slot_end           DATETIME NOT NULL,
  status             ENUM('held','pending_verify','confirmed','cancelled','completed') NOT NULL,
  hold_expires_at    DATETIME NULL,
  contact_name       VARCHAR(128) NULL,
  contact_email      VARCHAR(255) NULL,
  contact_phone      VARCHAR(32)  NULL,
  contact_carrier_id INT NULL,
  notify_channel     ENUM('email','sms') NULL,
  created_by_user_id INT NULL,
  reminder_sent_at   DATETIME NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_appt_user    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_appt_carrier FOREIGN KEY (contact_carrier_id) REFERENCES carriers(carrier_id) ON DELETE SET NULL,
  CONSTRAINT fk_appt_creator FOREIGN KEY (created_by_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
  KEY idx_appt_status (status),
  KEY idx_appt_slot (slot_start)
) ENGINE=InnoDB;

CREATE TABLE verification_tokens (
  verification_token_id INT AUTO_INCREMENT PRIMARY KEY,
  purpose        ENUM('booking','claim') NOT NULL DEFAULT 'booking',
  appointment_id INT NULL,
  user_id        INT NULL,
  code_hash      VARCHAR(255) NOT NULL,
  contact        VARCHAR(255) NOT NULL,
  channel        ENUM('email','sms') NOT NULL,
  expires_at     DATETIME NOT NULL,
  attempts       INT NOT NULL DEFAULT 0,
  verified_at    DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vt_appt FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE,
  CONSTRAINT fk_vt_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE rate_limits (
  rate_limit_id INT AUTO_INCREMENT PRIMARY KEY,
  rate_key      VARCHAR(160) NOT NULL,
  window_start  DATETIME NOT NULL,
  hits          INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_rate (rate_key, window_start)
) ENGINE=InnoDB;

CREATE TABLE reminders (
  reminder_id    INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  appointment_id INT NULL,
  channel        ENUM('email','sms') NOT NULL,
  sent_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rem_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_rem_appt FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE SET NULL
) ENGINE=InnoDB;
