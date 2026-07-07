-- Production seed: the minimum reference data, with NO demo clients or logins.
-- Import after db/init/001_schema.sql (select your database first), then create
-- an admin with bin/create_admin.php and import your real data (import_haircuts.sql).

-- Carrier -> email-to-SMS gateway lookup (needed for SMS verification/reminders).
INSERT INTO carriers (name, sms_gateway_domain) VALUES
  ('Verizon',    'vtext.com'),
  ('AT&T',       'txt.att.net'),
  ('T-Mobile',   'tmomail.net'),
  ('Sprint',     'messaging.sprintpcs.com'),
  ('Boost',      'sms.myboostmobile.com'),
  ('Cricket',    'sms.cricketwireless.net'),
  ('US Cellular','email.uscc.net'),
  ('Google Fi',  'msg.fi.google.com');

-- Starter availability (edit in-app under Hours). Sat & Sun, 10:00–16:00, 1-hour slots.
INSERT INTO availability (weekday, start_time, end_time, slot_minutes, active) VALUES
  (0, '10:00:00', '16:00:00', 60, 1),
  (6, '10:00:00', '16:00:00', 60, 1);
