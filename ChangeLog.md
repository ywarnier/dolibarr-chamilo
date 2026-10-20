# ChangeLog

All notable changes of the School module. The format follows [Keep a Changelog](https://keepachangelog.com), and the module follows semantic versioning once it reaches 1.0.

## Unreleased

### Added
- Attendance read back from Chamilo. Teachers record who was present in Chamilo; the scheduled job now reads that for the dates that have taken place and stores it in `llx_school_presence` (present, late, absent, absent with a reason). A date is read again every 30 minutes until Chamilo says its attendance was taken, then never again; a student who is taken off the sheet disappears here too, and a reading that fails keeps what was stored. Only students known to Dolibarr are kept.
- The schedule shows the totals of each date, and the card of a student has an **Attendance** section with the totals and the latest dates.
- Columns `attendance_done` and `last_pull` on `llx_school_occurrence`, and table `llx_school_presence`. **Existing installations**: add the two columns and create the table by hand, as the module reload does not alter existing tables.
- Rooms and schedule. A room is a resource of Dolibarr's Resources module that the school uses, with a capacity (the resource's own "max users") and a floor; **School > Rooms** adopts a resource as a room, imports the rooms that already exist in Chamilo and links them by name. Rooms are mirrored in Chamilo (`/api/rooms`), in the branch chosen on the setup page (automatic when Chamilo has only one).
- **School > Schedule** shows the dates of a session or of a course on its own, and the dates to come for everything, optionally for one room. A series of dates is added from a first day, an optional end day, weekdays, a number of weeks between two dates, a start time and a duration. Each date is a Dolibarr agenda event with the room booked as a busy resource and the teachers of the course (or of the session) assigned, so the room's planning and the agenda stay right.
- A room cannot be booked twice at the same time (back-to-back is fine), nor over its capacity; a series can refuse everything or skip the busy dates and say which. A teacher who already has a lesson at that time is only warned.
- Each date becomes a date of the attendance sheet of the course in the session (or of the course alone) in Chamilo, with its room. Moving a date or changing its room is sent as a change; a cancelled date is removed. A date for which attendance was already taken in Chamilo is never moved or removed there, and only its room can change. If the attendance sheet or a date was deleted in Chamilo, it is created again.
- Tables `llx_school_room`, `llx_school_attendance`, `llx_school_occurrence`; setting `SCHOOL_CHAMILO_BRANCH_ID`; job types `room` and `occurrence` in the queue.
- Billing. **School > Billing** invoices the enrolments that are not invoiced yet, and the card of a class has **Activities** (a museum visit, a trip) that are invoiced to the parents of the children who took part. In both cases each paying parent gets a **draft** invoice with one line per child, so a family with two children gets one invoice, grouped by third party; nothing is validated for you. A session or course line carries the dates of the session, and the line is linked to the product.
- The share each paying parent owes is decided when invoicing (a child with two payers is proposed 50/50 the first time), checked to add up to 100, then kept on the link between the child and the parent and proposed again next time. The split is made to the cent so that the parts add up exactly, the odd cent going to the largest share.
- What was invoiced is recorded (`llx_school_enrolment_invoice`, `llx_school_class_activity_invoice`), so nothing is invoiced twice. An item counts as invoiced when the shares invoiced add up to 100 on invoices that still exist and are not abandoned: a deleted or abandoned draft makes its items billable again, and if only one of two payers' invoices is missing, only that part is offered.
- Class activities: a label, a day, a price per student (or the price of a service) and the participants, who are the members of the class on that day and can be unticked. An activity is marked invoiced once every participant is.
- The right **Invoice enrolments and class activities to the parents** (`invoice`), required in addition to Dolibarr's right to create invoices. The module now depends on the Invoices module.
- Classes. A class groups students who follow the same sessions for a teaching cycle, with an optional level, an optional cycle (start and end dates, which need not be a school year), an optional homeroom teacher, and an archived state. A class has members, each with a start date, and follows one or several sessions. A student who leaves keeps the row, ended the day before, so past announcements and invoices stay traceable, and can join again later.
- The class is mirrored in Chamilo as a class (a user group of the "normal class" type): its name, the sessions it follows and its current members, replaced as a whole through Chamilo's class routes. Chamilo enrols the members of a class in the sessions linked to it and takes a student out of them when they leave the class, so Dolibarr keeps no class-derived enrolment. A member without a Chamilo account yet is left out with a warning, and the class is updated again by itself when the account is created.
- Announcements to the parents of a class. A recipient selector for Dolibarr's Emailing module, "Parents of the students of a class", adds one address per parent of the current members: a parent of two children in the class gets one message, the contact's address is used before the third party's, and addresses that unsubscribed are marked by Emailing. The class card has a form that prepares a campaign (a draft) with these recipients, which is reviewed and sent in the Emailing module.
- Students, in their own table: first name, last name, birth date, an optional email, and an active flag. A student gets a Chamilo account with the student role. The username is built from the name with accents folded (`elodie.francois`), with a number added when taken. An inactive student has a deactivated account.
- The email of a student's account. Chamilo requires one and it must be unique, while a child often has none. Without an address of their own, the account gets the address of the first parent that has one with the username after a plus sign (`parent+leo.doe@example.org`), so the parent receives Chamilo's message to choose a password. When there is no parent address either, `SCHOOL_STUDENT_EMAIL_DOMAIN` gives a placeholder (`leo.doe@school.example`); without it, the student is reported as an error that says how to fix it.
- Parents. The parents of a student are Dolibarr third parties, with an optional contact, a relation (parent, guardian, other), a payer flag and the share of the cost each pays. A third party's card has a **Children** tab.
- Parents who follow their children in Chamilo. A parent who is ticked "Follows in Chamilo" gets a Chamilo account of their own, created from a contact with an email, with the human resources manager role, and the relation that makes them follow the child. The account belongs to the contact and is shared by all their children. Stopping the following removes the relation and keeps the account.
- Enrolment of a student in a course or a session, and its removal, from the student's card.
- `dolibarr_id` on Chamilo users now holds a typed reference: `user:7` for a teacher, `student:12`, `contact:30` for a parent. A bare number would be ambiguous between the three tables.
- Settings `SCHOOL_STUDENT_SEND_EMAIL`, `SCHOOL_PARENT_SEND_EMAIL` (default on) and `SCHOOL_STUDENT_EMAIL_DOMAIN`.
- Table `llx_school_guardian` (the Chamilo account of a parent who follows children).
- Teachers. A Dolibarr user flagged as an employee can be enabled as a teacher: the module creates the account in Chamilo with the teacher role and nothing else, or links the existing account that has the same email without changing its data or role. The Chamilo username is the Dolibarr login when it is free, and a numbered variant (`jdoe.2`) when it is not. The account follows the Dolibarr user: name, email, phone, and deactivation when the user is disabled. A new `dolibarr_id` extra field on Chamilo users holds the link.
- Assignment of teachers to courses, and of coaches to the courses of a session, with the removal of teachers who are no longer assigned. Chamilo is read first, because it refuses to subscribe someone who is already there.
- A trigger that queues the update of a teacher's account when the Dolibarr user is modified, enabled or disabled.
- Settings `SCHOOL_TEACHER_SEND_EMAIL` (default on: Chamilo sends the new teacher its "choose your password" message) and `SCHOOL_CHAMILO_ACCESS_URL_ID` (the portal id, found from the URL when not set).
- "Prepare Chamilo" block on the setup page: connection test, creation of the Chamilo extra fields, and sending of the Dolibarr ids to Chamilo, so none of these needs a script.
- README, INSTALL, administrator guide and user guide.

### Changed
- `llx_school_enrolment` no longer has `fk_commande` and `fk_facture`: an enrolment can give several invoices (one per paying parent), which `llx_school_enrolment_invoice` records. A database created with an earlier build can be updated by hand: `ALTER TABLE llx_school_enrolment DROP COLUMN fk_commande, DROP COLUMN fk_facture;`. Enabling the module again creates the new table.
- When a student's Chamilo account is created, the classes the student is a member of are updated.
- A student's enrolment in a session that is not on the Chamilo portal this module talks to now says so, instead of asking to update Chamilo.
- An email that belongs to a deleted Chamilo account is no longer linked: the account is unusable and keeps its email, so the module reports it instead of linking a dead account.
- The value of `dolibarr_id` written for teachers is now `user:<id>`. A database that already holds plain ids from an earlier build is corrected by sending the teachers again from the teacher list.
- `llx_school_course_teacher` and `llx_school_session_teacher` have a `sync_status` column (0 to add in Chamilo, 1 in Chamilo, 3 to remove) instead of `chamilo_link_id`. A database created with an earlier build can be updated by hand:

  ```sql
  ALTER TABLE llx_school_course_teacher DROP COLUMN chamilo_link_id, ADD COLUMN sync_status smallint DEFAULT 0 NOT NULL;
  ALTER TABLE llx_school_session_teacher DROP COLUMN chamilo_link_id, ADD COLUMN sync_status smallint DEFAULT 0 NOT NULL;
  ```

  Run "Create the extra fields" again on the setup page to create the new `dolibarr_id` field of Chamilo users. Enabling the module again creates the new `llx_school_guardian` table; the statuses of `llx_school_enrolment.status` (0 to enrol, 1 enrolled, 3 to remove) and of `llx_school_student_parent.chamilo_follow` (0, 1, 2 to stop) are documented in `sql/llx_school.sql`.
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
