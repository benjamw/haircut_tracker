-- DEV demo seed (single users table). Runs against the selected DB.
-- NOTE: demo data + weak logins — for PRODUCTION use db/prod_seed.sql instead
-- and create your admin with bin/create_admin.php.

-- Carriers (email-to-SMS gateways).
INSERT INTO carriers (name, sms_gateway_domain) VALUES
  ('Verizon',   'vtext.com'),
  ('AT&T',      'txt.att.net'),
  ('T-Mobile',  'tmomail.net'),
  ('Sprint',    'messaging.sprintpcs.com'),
  ('Boost',     'sms.myboostmobile.com'),
  ('Cricket',   'sms.cricketwireless.net'),
  ('US Cellular','email.uscc.net'),
  ('Google Fi', 'msg.fi.google.com');

-- Availability: Sat & Sun, 10:00–16:00, 1-hour slots.
INSERT INTO availability (weekday, start_time, end_time, slot_minutes, active) VALUES
  (0, '10:00:00', '16:00:00', 60, 1),
  (6, '10:00:00', '16:00:00', 60, 1);

-- Users. Login columns are NULL for clients without an account.
--   admin  / admin123   (role=admin)   |   jayden / secret123  (customer)
INSERT INTO users
  (user_id, display_name, email, phone, carrier_id, preferred_channel, inactive, last_contacted_at,
   phone_verified_at, notes, username, password_hash, role, status) VALUES
  (1, 'Barber (Admin)', 'barber@example.com', NULL, NULL, 'email', 0, NULL, NULL,
     'Shop owner', 'admin', '$2y$10$l6rVn4x4eAUxlaZGgnqZkOp4doruudWb19y44jzijeq8D0vx5GEeO', 'admin', 'active'),
  (2, 'Marcus Lee', 'marcus@example.com', '5555550111', 1, 'sms', 0, NULL, NULL,
     'Regular fade ~3 wks', NULL, NULL, 'user', 'active'),
  (3, 'Jayden Brooks', 'jayden@example.com', '5555550122', 3, 'sms', 0, NULL, NOW(),
     'Line-up, ~monthly', 'jayden', '$2y$10$EYxXtvWaccMv3bTrpGqWTuuATl18tF7kDtBGNoFHhFD.uylNTejSS', 'user', 'active'),
  (4, 'Devon Carter', 'devon@example.com', '5555550133', 2, 'sms', 0, NULL, NULL,
     'New client', NULL, NULL, 'user', 'active'),
  (5, 'Aisha Khan', 'aisha@example.com', '5555550144', 1, 'sms', 0, DATE_SUB(NOW(), INTERVAL 2 DAY), NULL,
     'Texted 2 days ago', NULL, NULL, 'user', 'active'),
  (6, 'Sam Rivera', 'sam@example.com', '5555550177', 1, 'sms', 1, NULL, NULL,
     'Moved away', NULL, NULL, 'user', 'active');

-- Haircut history (relative dates).
INSERT INTO haircuts (user_id, haircut_date, amount_cents, notes, created_by) VALUES
  -- Marcus: ~21d cadence, last 27d ago -> overdue
  (2, DATE_SUB(CURDATE(), INTERVAL 90 DAY), 3000, 'Fade', 'seed'),
  (2, DATE_SUB(CURDATE(), INTERVAL 69 DAY), 3000, 'Fade', 'seed'),
  (2, DATE_SUB(CURDATE(), INTERVAL 48 DAY), 3500, 'Fade + beard', 'seed'),
  (2, DATE_SUB(CURDATE(), INTERVAL 27 DAY), 3000, 'Fade', 'seed'),
  -- Jayden: ~30d cadence, last 24d ago -> due soon
  (3, DATE_SUB(CURDATE(), INTERVAL 84 DAY), 2500, 'Line-up', 'seed'),
  (3, DATE_SUB(CURDATE(), INTERVAL 54 DAY), 2500, 'Line-up', 'seed'),
  (3, DATE_SUB(CURDATE(), INTERVAL 24 DAY), 2500, 'Line-up', 'seed'),
  -- Devon: single cut
  (4, DATE_SUB(CURDATE(), INTERVAL 10 DAY), 4000, 'First cut', 'seed'),
  -- Aisha: overdue (but contacted recently -> hidden from reach-out)
  (5, DATE_SUB(CURDATE(), INTERVAL 90 DAY), 3200, 'Cut', 'seed'),
  (5, DATE_SUB(CURDATE(), INTERVAL 62 DAY), 3200, 'Cut', 'seed'),
  (5, DATE_SUB(CURDATE(), INTERVAL 34 DAY), 3200, 'Cut', 'seed'),
  -- Sam: would be overdue, but inactive
  (6, DATE_SUB(CURDATE(), INTERVAL 100 DAY), 3000, 'Cut', 'seed'),
  (6, DATE_SUB(CURDATE(), INTERVAL 70 DAY),  3000, 'Cut', 'seed'),
  (6, DATE_SUB(CURDATE(), INTERVAL 40 DAY),  3000, 'Cut', 'seed');

-- A past confirmed appointment awaiting recording (Appts -> "Needs recording").
INSERT INTO appointments (user_id, slot_start, slot_end, status, contact_name, contact_phone, contact_carrier_id, notify_channel) VALUES
  (5, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_ADD(DATE_SUB(NOW(), INTERVAL 2 DAY), INTERVAL 1 HOUR), 'confirmed', 'Aisha Khan', '5555550144', 1, 'sms');
