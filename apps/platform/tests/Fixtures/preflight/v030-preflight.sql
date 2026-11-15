-- Flow Life Commons: read-only pre-flight for the v0.3.0 production schema (G6 WP3-10, WP3-12).
-- Run it against the v0.3.0 database BEFORE the migrations of the next release. It only reads.
-- The text, the expected output and what to do about it are in docs/runbooks/g10-release-readiness.md.
-- Every result starts with a `check_name` column. Hex is authoritative: the text columns can hide case, spaces and accents.
-- Exact comparisons use HEX(role_key) = HEX('...'). Plain comparisons (role_key = '...') follow the column's collation, as MariaDB's unique key does.

SET SESSION TRANSACTION READ ONLY;

SELECT '1 server and database' AS check_name,
       DATABASE() AS db,
       @@version AS server_version,
       s.default_character_set_name AS db_charset,
       s.default_collation_name AS db_collation
FROM information_schema.schemata s
WHERE s.schema_name = DATABASE();

SELECT '2 role keys by exact bytes' AS check_name,
       k.key_hex,
       k.key_text,
       k.kind,
       COUNT(*) AS assignments,
       COUNT(DISTINCT k.person_id) AS persons
FROM (
    SELECT person_id,
           HEX(role_key) AS key_hex,
           role_key AS key_text,
           CASE
               WHEN HEX(role_key) = HEX('guardian') THEN 'exact guardian'
               WHEN HEX(role_key) = HEX('guardian-full') THEN 'exact guardian-full'
               WHEN HEX(role_key) = HEX('platform_administrator') THEN 'exact platform_administrator'
               WHEN role_key = 'guardian' THEN 'variant of guardian'
               WHEN role_key = 'guardian-full' THEN 'variant of guardian-full'
               WHEN role_key = 'platform_administrator' THEN 'variant of platform_administrator'
               ELSE 'other'
           END AS kind
    FROM role_assignments
) k
GROUP BY k.key_hex, k.key_text, k.kind
ORDER BY k.key_hex;

SELECT '3 same-Person conflicts' AS check_name,
       g.person_id,
       g.id AS guardian_assignment_id,
       f.id AS other_assignment_id,
       HEX(f.role_key) AS other_key_hex,
       CASE
           WHEN HEX(f.role_key) = HEX('guardian-full') THEN 'exact guardian-full: the migration refuses to run'
           ELSE 'collation-equal variant: MariaDB rejects the rename with error 1062'
       END AS consequence
FROM role_assignments g
JOIN role_assignments f ON f.person_id = g.person_id AND f.id <> g.id AND f.role_key = 'guardian-full'
WHERE HEX(g.role_key) = HEX('guardian')
ORDER BY g.person_id, f.id;

SELECT '4 exact guardian-full on anyone' AS check_name,
       id AS assignment_id,
       person_id,
       granted_at
FROM role_assignments
WHERE HEX(role_key) = HEX('guardian-full')
ORDER BY id;

SELECT '5 guardian-role holders by Account status' AS check_name,
       h.key_kind,
       h.account_status,
       COUNT(DISTINCT h.person_id) AS persons,
       SUM(h.is_administrator) AS also_platform_administrator
FROM (
    SELECT ra.person_id,
           CASE
               WHEN HEX(ra.role_key) = HEX('guardian') THEN 'exact guardian: renamed by the migration'
               ELSE 'variant: not renamed, grants nothing'
           END AS key_kind,
           COALESCE(a.status, 'no Account') AS account_status,
           EXISTS (
               SELECT 1 FROM role_assignments x
               WHERE x.person_id = ra.person_id AND HEX(x.role_key) = HEX('platform_administrator')
           ) AS is_administrator
    FROM role_assignments ra
    LEFT JOIN accounts a ON a.person_id = ra.person_id
    WHERE ra.role_key = 'guardian'
) h
GROUP BY h.key_kind, h.account_status
ORDER BY h.key_kind, h.account_status;

SELECT '6 guardian-role holders' AS check_name,
       ra.person_id,
       ra.id AS assignment_id,
       HEX(ra.role_key) AS key_hex,
       COALESCE(a.status, 'no Account') AS account_status,
       ra.granted_at
FROM role_assignments ra
LEFT JOIN accounts a ON a.person_id = ra.person_id
WHERE ra.role_key = 'guardian'
ORDER BY ra.person_id;

SELECT '7a table collations' AS check_name,
       table_name,
       engine,
       table_collation
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
ORDER BY table_name;

SELECT '7b column character sets and collations' AS check_name,
       character_set_name,
       collation_name,
       COUNT(*) AS column_count
FROM information_schema.columns
WHERE table_schema = DATABASE() AND collation_name IS NOT NULL
GROUP BY character_set_name, collation_name
ORDER BY character_set_name, collation_name;

SELECT '7c columns the new foreign keys and the rename depend on' AS check_name,
       table_name,
       column_name,
       column_type,
       character_set_name,
       collation_name
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND (
      (table_name = 'people' AND column_name = 'id')
      OR (table_name = 'accounts' AND column_name IN ('id', 'person_id'))
      OR (table_name = 'role_assignments' AND column_name IN ('person_id', 'role_key'))
      OR (table_name = 'membership_grants' AND column_name = 'person_id')
  )
ORDER BY table_name, column_name;
