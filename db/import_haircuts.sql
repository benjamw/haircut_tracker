-- One-time import of historical haircuts from docs/import.txt.
--
-- Pricing rules applied:
--   * Aranya Banerjee and Brayden Welker: $0 on every cut.
--   * Everyone else: cuts dated 2026-06-01 or later = $20 (2000 cents);
--     cuts on/before 2026-05-31 = $0 (free practice period).
--
-- Creates a user row per client (haircuts require a user_id), then their
-- cuts. Run ONCE against the haircut_tracker database, e.g.:
--   docker compose exec -T db mysql -uhaircut -phaircut haircut_tracker < db/import_haircuts.sql
-- (or import via phpMyAdmin with the database selected).

START TRANSACTION;

-- Aranya Banerjee (always $0)
INSERT INTO users (display_name) VALUES ('Aranya Banerjee'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2025-08-17',0,'import'),(@p,'2025-09-10',0,'import'),(@p,'2025-09-24',0,'import'),
  (@p,'2025-10-26',0,'import'),(@p,'2025-11-29',0,'import'),(@p,'2025-12-14',0,'import'),
  (@p,'2026-01-24',0,'import'),(@p,'2026-03-07',0,'import'),(@p,'2026-04-25',0,'import'),
  (@p,'2026-05-17',0,'import'),(@p,'2026-06-16',0,'import'),(@p,'2026-07-06',0,'import');

-- Isaac Calmes
INSERT INTO users (display_name) VALUES ('Isaac Calmes'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2025-09-01',0,'import'),(@p,'2025-09-24',0,'import'),(@p,'2025-11-06',0,'import'),
  (@p,'2025-11-29',0,'import'),(@p,'2026-01-31',0,'import'),(@p,'2026-04-14',0,'import'),
  (@p,'2026-06-16',2000,'import');

-- Zach Richardson
INSERT INTO users (display_name) VALUES ('Zach Richardson'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2025-08-24',0,'import'),(@p,'2025-09-10',0,'import'),(@p,'2025-10-12',0,'import'),
  (@p,'2025-10-26',0,'import'),(@p,'2026-04-18',0,'import'),(@p,'2026-05-16',0,'import'),
  (@p,'2026-06-03',2000,'import'),(@p,'2026-07-06',2000,'import');

-- Asher Jones
INSERT INTO users (display_name) VALUES ('Asher Jones'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2025-09-10',0,'import'),(@p,'2025-09-24',0,'import'),(@p,'2025-10-26',0,'import'),
  (@p,'2025-12-12',0,'import'),(@p,'2026-01-31',0,'import'),(@p,'2026-03-07',0,'import'),
  (@p,'2026-04-26',0,'import'),(@p,'2026-06-03',2000,'import'),(@p,'2026-06-29',2000,'import');

-- Landon Guymon
INSERT INTO users (display_name) VALUES ('Landon Guymon'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2025-09-10',0,'import'),(@p,'2025-09-22',0,'import'),(@p,'2025-10-10',0,'import'),
  (@p,'2026-05-25',0,'import'),(@p,'2026-06-11',2000,'import'),(@p,'2026-06-29',2000,'import');

-- Truman Cecil
INSERT INTO users (display_name) VALUES ('Truman Cecil'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2025-09-21',0,'import'),(@p,'2025-10-10',0,'import'),(@p,'2025-10-26',0,'import'),
  (@p,'2025-11-30',0,'import'),(@p,'2026-01-31',0,'import'),(@p,'2026-03-22',0,'import'),
  (@p,'2026-05-16',0,'import');

-- Flint Childs
INSERT INTO users (display_name) VALUES ('Flint Childs'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2025-09-21',0,'import'),(@p,'2025-10-27',0,'import'),(@p,'2025-12-12',0,'import'),
  (@p,'2026-01-24',0,'import'),(@p,'2026-05-23',0,'import');

-- Townsend Leeman
INSERT INTO users (display_name) VALUES ('Townsend Leeman'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2025-09-21',0,'import'),(@p,'2025-10-28',0,'import'),(@p,'2025-12-12',0,'import'),
  (@p,'2026-01-24',0,'import'),(@p,'2026-06-19',2000,'import');

-- Alex Butrum
INSERT INTO users (display_name) VALUES ('Alex Butrum'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2025-09-21',0,'import'),(@p,'2026-04-25',0,'import'),(@p,'2026-06-16',2000,'import');

-- Nathee Chaiyakunapruk
INSERT INTO users (display_name) VALUES ('Nathee Chaiyakunapruk'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2025-09-22',0,'import'),(@p,'2025-11-29',0,'import'),(@p,'2026-05-25',0,'import');

-- Killian Sjostrom
INSERT INTO users (display_name) VALUES ('Killian Sjostrom'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2025-10-26',0,'import'),(@p,'2026-01-31',0,'import'),(@p,'2026-03-14',0,'import'),
  (@p,'2026-04-26',0,'import'),(@p,'2026-05-25',0,'import'),(@p,'2026-06-16',2000,'import');

-- Asher Beck
INSERT INTO users (display_name) VALUES ('Asher Beck'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2025-10-28',0,'import'),(@p,'2025-11-29',0,'import'),(@p,'2026-01-24',0,'import'),
  (@p,'2026-05-28',0,'import');

-- Stew Wagner
INSERT INTO users (display_name) VALUES ('Stew Wagner'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2025-11-08',0,'import');

-- Ruhaan Banerjee
INSERT INTO users (display_name) VALUES ('Ruhaan Banerjee'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2025-11-29',0,'import'),(@p,'2025-12-13',0,'import'),(@p,'2026-01-24',0,'import'),
  (@p,'2026-03-04',0,'import'),(@p,'2026-05-17',0,'import'),(@p,'2026-06-23',2000,'import');

-- Eli Gatrell
INSERT INTO users (display_name) VALUES ('Eli Gatrell'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-01-24',0,'import'),(@p,'2026-04-25',0,'import'),(@p,'2026-05-28',0,'import'),
  (@p,'2026-07-02',2000,'import');

-- Om Twari
INSERT INTO users (display_name) VALUES ('Om Tiwari'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-01-24',0,'import'),(@p,'2026-02-07',0,'import'),(@p,'2026-04-25',0,'import'),
  (@p,'2026-05-24',0,'import');

-- Ronnie Kinsley
INSERT INTO users (display_name) VALUES ('Ronnie Kinsley'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-01-24',0,'import');

-- Conner
INSERT INTO users (display_name) VALUES ('Conner'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-01-31',0,'import'),(@p,'2026-03-22',0,'import'),(@p,'2026-05-12',0,'import'),
  (@p,'2026-06-04',2000,'import');

-- Eddie Kawk
INSERT INTO users (display_name) VALUES ('Eddie Kawk'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-02-07',0,'import');

-- Andrew Divricean
INSERT INTO users (display_name) VALUES ('Andrew Divricean'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-02-07',0,'import'),(@p,'2026-05-31',0,'import'),(@p,'2026-06-26',2000,'import');

-- Patrick Divricean
INSERT INTO users (display_name) VALUES ('Patrick Divricean'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-05-31',0,'import'),(@p,'2026-06-26',2000,'import');

-- Max Call
INSERT INTO users (display_name) VALUES ('Max Call'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-03-07',0,'import'),(@p,'2026-04-26',0,'import'),(@p,'2026-05-31',0,'import');

-- Franco Windes
INSERT INTO users (display_name) VALUES ('Franco Windes'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-05-02',0,'import'),(@p,'2026-05-27',0,'import'),(@p,'2026-06-18',2000,'import');

-- Isaac Ison
INSERT INTO users (display_name) VALUES ('Isaac Ison'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-05-02',0,'import'),(@p,'2026-05-28',0,'import'),(@p,'2026-07-04',2000,'import');

-- Oliver Brooks
INSERT INTO users (display_name) VALUES ('Oliver Brooks'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-03-27',0,'import'),(@p,'2026-05-27',0,'import'),(@p,'2026-06-12',2000,'import'),
  (@p,'2026-07-01',2000,'import');

-- Makai Tu
INSERT INTO users (display_name) VALUES ('Makai Tu'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-04-04',0,'import'),(@p,'2026-05-01',0,'import'),(@p,'2026-05-31',0,'import'),
  (@p,'2026-06-18',2000,'import');

-- Bode Linford
INSERT INTO users (display_name) VALUES ('Bode Linford'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-05-10',0,'import'),(@p,'2026-06-11',2000,'import');

-- Brayden Welker (always $0)
INSERT INTO users (display_name) VALUES ('Brayden Welker'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-05-08',0,'import');

-- Sean Carrey
INSERT INTO users (display_name) VALUES ('Sean Carrey'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-05-02',0,'import'),(@p,'2026-06-03',2000,'import'),(@p,'2026-07-06',2000,'import');

-- John Vaughn
INSERT INTO users (display_name) VALUES ('John Vaughn'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-05-28',0,'import'),(@p,'2026-06-30',2000,'import');

-- Luke Cole
INSERT INTO users (display_name) VALUES ('Luke Cole'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-05-28',0,'import'),(@p,'2026-06-16',2000,'import'),(@p,'2026-07-06',2000,'import');

-- Ryder Linford
INSERT INTO users (display_name) VALUES ('Ryder Linford'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-05-28',0,'import'),(@p,'2026-07-06',2000,'import');

-- Luke Featherstone
INSERT INTO users (display_name) VALUES ('Luke Featherstone'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-05-28',0,'import');

-- Quinn Williams
INSERT INTO users (display_name) VALUES ('Quinn Williams'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-05-28',0,'import'),(@p,'2026-06-29',2000,'import');

-- Declan Carrera
INSERT INTO users (display_name) VALUES ('Declan Carrera'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-05-31',0,'import'),(@p,'2026-07-02',2000,'import');

-- Noah Hockin
INSERT INTO users (display_name) VALUES ('Noah Hockin'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-06-03',2000,'import');

-- Andre Nelson
INSERT INTO users (display_name) VALUES ('Andre Nelson'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-06-04',2000,'import');

-- Bentley Boren
INSERT INTO users (display_name) VALUES ('Bentley Boren'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-06-04',2000,'import'),(@p,'2026-07-08',2000,'import');

-- Bridger Swenson
INSERT INTO users (display_name) VALUES ('Bridger Swenson'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-06-09',2000,'import'),(@p,'2026-07-06',2000,'import');

-- Devin Brown
INSERT INTO users (display_name) VALUES ('Devin Brown'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-06-10',2000,'import');

-- Henry Ratford
INSERT INTO users (display_name) VALUES ('Henry Ratford'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-06-12',2000,'import'),(@p,'2026-07-02',2000,'import');

-- Mason Durham
INSERT INTO users (display_name) VALUES ('Mason Durham'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-06-16',2000,'import');

-- AJ Casey
INSERT INTO users (display_name) VALUES ('AJ Casey'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-06-16',2000,'import');

-- Crosby Kircher
INSERT INTO users (display_name) VALUES ('Crosby Kircher'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-06-29',2000,'import');

-- Baron Bleazard
INSERT INTO users (display_name) VALUES ('Baron Bleazard'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-03-21',0,'import'),(@p,'2026-06-29',2000,'import');

-- Holden Warren
INSERT INTO users (display_name) VALUES ('Holden Warren'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-06-29',2000,'import');

-- Todd Christensen
INSERT INTO users (display_name) VALUES ('Todd Christensen'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-07-01',2000,'import');

-- Burton Klc
INSERT INTO users (display_name) VALUES ('Burton Klc'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-07-01',2000,'import');

-- San Nydegger
INSERT INTO users (display_name) VALUES ('Sam Nydegger'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-07-06',2000,'import');

-- Sam Cole
INSERT INTO users (display_name) VALUES ('Sam Cole'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-07-07',2000,'import');

-- Will Nydegger
INSERT INTO users (display_name) VALUES ('Will Nydegger'); SET @p := LAST_INSERT_ID();
INSERT INTO haircuts (user_id, haircut_date, amount_cents, created_by) VALUES
  (@p,'2026-07-08',2000,'import');

COMMIT;
