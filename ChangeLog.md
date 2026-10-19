# ChangeLog

All notable changes of the School module. The format follows [Keep a Changelog](https://keepachangelog.com), and the module follows semantic versioning once it reaches 1.0.

## Unreleased

### Added
- Teachers. A Dolibarr user flagged as an employee can be enabled as a teacher: the module creates the account in Chamilo with the teacher role and nothing else, or links the existing account that has the same email without changing its data or role. The Chamilo username is the Dolibarr login when it is free, and a numbered variant (`jdoe.2`) when it is not. The account follows the Dolibarr user: name, email, phone, and deactivation when the user is disabled. A new `dolibarr_id` extra field on Chamilo users holds the link.
- Assignment of teachers to courses, and of coaches to the courses of a session, with the removal of teachers who are no longer assigned. Chamilo is read first, because it refuses to subscribe someone who is already there.
- A trigger that queues the update of a teacher's account when the Dolibarr user is modified, enabled or disabled.
- Settings `SCHOOL_TEACHER_SEND_EMAIL` (default on: Chamilo sends the new teacher its "choose your password" message) and `SCHOOL_CHAMILO_ACCESS_URL_ID` (the portal id, found from the URL when not set).
- "Prepare Chamilo" block on the setup page: connection test, creation of the Chamilo extra fields, and sending of the Dolibarr ids to Chamilo, so none of these needs a script.
- README, INSTALL, administrator guide and user guide.

### Changed
- `llx_school_course_teacher` and `llx_school_session_teacher` have a `sync_status` column (0 to add in Chamilo, 1 in Chamilo, 3 to remove) instead of `chamilo_link_id`. A database created with an earlier build can be updated by hand:

  ```sql
  ALTER TABLE llx_school_course_teacher DROP COLUMN chamilo_link_id, ADD COLUMN sync_status smallint DEFAULT 0 NOT NULL;
  ALTER TABLE llx_school_session_teacher DROP COLUMN chamilo_link_id, ADD COLUMN sync_status smallint DEFAULT 0 NOT NULL;
  ```

  Run "Create the extra fields" again on the setup page to create the new `dolibarr_id` field of Chamilo users.
- Minimum Dolibarr version is now 24.0. The module is meant to be driven through Dolibarr's MCP server as well as through its screens.
- `llx_school_enrolment.fk_class` is removed. Chamilo enrols the members of a class itself when the class is linked to a session, so Dolibarr keeps no class-derived enrolments. A database created with an earlier build can drop the column by hand:

  ```sql
  ALTER TABLE llx_school_enrolment DROP COLUMN fk_class;
  ```

### Fixed
- The test of `schoolWantedUsername()` expected the wrong result for a login with an accented letter (`j doe@école` gives `jdoecole`).

## 0.1.0 - 2026-10-19

First version.

### Added
- Module descriptor (number 104802), rights (read, write, delete, sync, setup, teacher, class), menu and a scheduled job that sends the pending changes to Chamilo every 5 minutes.
- Connection to Chamilo with an external API key (stored encrypted), a setup page with a connection test that tells a refused key from a key whose account is not an administrator, and a client that does not follow redirects and refuses private addresses unless allowed.
- Synchronization queue: identical waiting jobs are merged, a job that cannot succeed fails at once with Chamilo's message, a job that can succeed later is retried with a growing delay up to 8 attempts, and a job left running by a crashed run is released after 15 minutes.
- Import of Chamilo courses and sessions as service products (not for sale, price 0, existing products adopted, labels and prices never overwritten by a re-import).
- `dolibarr_id` and `price` extra fields on Chamilo courses and sessions, and the sending of the Dolibarr product id and price. `price` is the field the Chamilo WordPress storefront plugin reads.
- Creation of a session in Dolibarr (service product, dates, price, courses) and its creation in Chamilo through the queue, safe to repeat after a partial failure.
- Course and session lists, with the synchronization state of each session and a **Synchronize now** button.
- Schema of 15 tables, ready for teachers, students, parents (with the share of the cost each pays), classes, class activities and their invoicing, and enrolments.
- English and French translations.
- Tests of the pure rules.
