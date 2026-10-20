# Administrator guide

For the person who installs and configures the School module. Installation steps are in [INSTALL.md](../INSTALL.md); this guide explains what is configured, what the module does behind the scenes, and what to check when something goes wrong.

## Settings

Everything is on **Home > Setup > Modules/Applications > School (wrench icon)**.

| Setting | Constant | Notes |
|---|---|---|
| Chamilo portal URL | `SCHOOL_CHAMILO_URL` | Address of the portal without `/api`. Must start with `http://` or `https://`. A trailing slash or `/api` is removed. |
| External API key | `SCHOOL_CHAMILO_API_KEY` | Stored encrypted with Dolibarr's own encryption. The field stays empty after saving: leaving it empty keeps the stored key. |
| Allow a local address | `SCHOOL_CHAMILO_ALLOW_LOCAL` | Off by default. When off, the module refuses to call a private or loopback address, as protection against requests to internal services. Turn it on on a development machine only. |
| Chamilo extra field ids | `SCHOOL_EF_USER_DOLIBARR_ID`, `SCHOOL_EF_COURSE_DOLIBARR_ID`, `SCHOOL_EF_COURSE_PRICE`, `SCHOOL_EF_SESSION_DOLIBARR_ID`, `SCHOOL_EF_SESSION_PRICE` | Filled by **Create the extra fields**. Shown at the bottom of the page. |
| Send the new-account message | `SCHOOL_TEACHER_SEND_EMAIL` | On unless set to `0`. When on, Chamilo emails a newly created teacher a link to choose a password. Set it with **Home > Setup > Other setup**, or in the `llx_const` table. |
| Student email | `SCHOOL_STUDENT_EMAIL_DOMAIN` | Domain of the placeholder address (`username@domain`) given to a student who has no email and no parent with one. Leave it empty to refuse such students instead. It does not need to receive mail unless you want it to. |
| Send the new-account message to students and parents | `SCHOOL_STUDENT_SEND_EMAIL`, `SCHOOL_PARENT_SEND_EMAIL` | On unless set to `0`. A student's message goes to the address of the account, which is normally a parent's mailbox. |
| Chamilo portal id | `SCHOOL_CHAMILO_ACCESS_URL_ID` | Optional. Without it the module finds the portal whose address has the same host and port as the configured URL. Set it if that detection cannot work, for example behind a proxy that changes the host. |

The three buttons of **Prepare Chamilo** can be pressed again at any time; none of them duplicates anything.

## Rights

Assign them in **Home > Setup > Users and groups**.

| Right | Allows |
|---|---|
| Read school data (`read`) | See the School menu, the course and session lists |
| Create and update school data (`write`) | Create sessions |
| Delete school data (`delete`) | Reserved for the delete features |
| Run and inspect the Chamilo synchronization (`sync`) | Import from Chamilo, **Synchronize now** |
| Configure the Chamilo connection (`setup`) | The setup page and its buttons |
| Manage teachers and their Chamilo accounts (`teacher`) | Enable a Dolibarr employee as a teacher (this creates or links a Chamilo account), choose the teachers of courses and the coaches of sessions. Kept apart from `write` so that not everyone who can edit school data can create Chamilo accounts for staff. |
| Manage classes, announcements and class activities (`class`) | Create and edit classes, members and sessions, prepare announcements, create and delete the activities of a class |
| Invoice enrolments and class activities to the parents (`invoice`) | Use **School > Billing**. Dolibarr's own right to create invoices is required as well. |

Dolibarr administrators hold every right.

## The Chamilo service account

The key belongs to a Chamilo account, and the module acts with that account's permissions. It must be an **administrator**: creating sessions and extra fields, changing courses and managing class subscriptions are administrator operations in Chamilo's API. Use a dedicated account (for example `dolibarr-sync`), not a person's account, so that revoking the key or deactivating the account stops the synchronization without affecting anyone.

To revoke access: `php bin/console chamilo:security:generate-external-api-key <username> --access-url-id=1 --revoke`, or turn off `security.external_api_enabled` in Chamilo, which stops every external key at once.

## The synchronization queue

Each change that has to reach Chamilo is a job in `llx_school_syncqueue`:

| Status | Meaning |
|---|---|
| Waiting (0) | Will be sent at the next run |
| Running (3) | Being sent. A job left running for more than 15 minutes (a run that crashed) goes back to Waiting. |
| Done (1) | Sent |
| Failed (2) | Will not be retried: the error is in `last_error` |

The School home page shows the number of jobs per status.

- **When it runs.** The scheduled job `SchoolSyncQueue` runs every 5 minutes if Dolibarr's scheduler is triggered (see INSTALL.md). **Synchronize now** on the Sessions page runs it immediately. Each run sends up to 50 jobs.
- **Retries.** A job that fails because Chamilo cannot be reached, times out, rate-limits (429) or answers with a server error is retried after 1 minute, then 2, 4, 8, and so on, never more than 1 hour apart, up to 8 attempts. After that it becomes Failed.
- **No retry.** Any other error, such as a validation error (422), a refused permission (403) or a missing object (404), would fail again, so the job becomes Failed at once with Chamilo's message.
- **No duplicates.** Two identical waiting jobs are merged. A session's Chamilo id is saved as soon as Chamilo has created it, so a retry after a partial failure carries on instead of creating a second session.
- **A session shows its own state** in the Sessions list: Waiting, Synchronized, or Error (hover to read the message).

**School > Synchronization log** lists the jobs, newest first, with what each is about (a link to the student, class, session...), its status, attempts, next try and message; it can be filtered by status and type. With the `sync` right, a Failed job (or a waiting one that already failed once) can be **tried again** at once, all the failed ones can be retried together, **Synchronize now** runs the queue, and a failed job can be **forgotten** (its object keeps its own error and is sent again when it changes). A running or done job cannot be retried. Jobs done are deleted after `SCHOOL_LOG_KEEP_DAYS` days (30 by default; 0 keeps them all) by the scheduled job.

## What is created in Chamilo

- Two extra fields on **courses** and two on **sessions**: `dolibarr_id` (text, the Dolibarr **product id**) and `price` (decimal). They hold the link between the two systems and let Dolibarr find an object again without relying on its title.
- `price` is the field the Chamilo WordPress storefront plugin reads. It is written only when the Dolibarr price is above zero: for the storefront, an item with no price is not for sale.
- Sessions created from Dolibarr, with their dates and courses.
- **Student accounts**, with the student role (status 5), and **parent accounts** for the parents who follow their children, with the human resources manager role (status 4): this is how Chamilo models "a user who follows other users". The "follows" link is Chamilo's own HR relation between the parent and the child. A parent account is created from a contact of the third party, so the contact needs an email; it is shared by all the children of that parent. If you rename the HR manager role to "parent" in Chamilo, nothing changes for this module.
- **Teacher accounts**, created with the teacher role (status 1).
- On every account the `dolibarr_id` extra field holds a typed reference to Dolibarr: `user:<id>` for a teacher, `student:<id>` for a student, `contact:<id>` for a parent. They are three different tables, so a bare number would be ambiguous. The module never changes the role of an account: an account that already exists is linked as it is, and the teacher list shows a warning icon when that account is not a teacher. Nobody can become an administrator through the synchronization.
- Teachers and coaches of courses and sessions, using Chamilo's own course user actions.
- **Classes**, as Chamilo classes (user groups of the "normal class" type), through `/api/usergroups`. Each push replaces the whole list of members and of sessions, which is how Chamilo's class routes work. Chamilo enrols the members of a class in the sessions linked to it and takes a student out of them when they leave, so a student who is also enrolled on their own in one of those sessions is taken out of it too when leaving the class. A class is never deleted in Chamilo by this module: archiving a class in Dolibarr leaves it as it is.
- Enrolments: a student is added to or removed from a course with Chamilo's course user actions, and from a session with `/api/sessions/{id}/students`, which subscribes the student to every course of the session and keeps the other students. Chamilo is read first each time, and nothing is removed that Dolibarr did not name.
- Accounts that already exist are linked, not changed: by email for a teacher, a parent, and a student who has an own email. An account found by email is never given a role, and a warning icon shows when it does not have the expected one. An email that belongs to a deleted account is refused.

The Chamilo API cannot delete extra fields or their values. If you delete a session or a course in Chamilo, its `dolibarr_id` and `price` values stay in the database, unused.

## Data model

Created by the module and kept when it is disabled:

| Table | Content |
|---|---|
| `llx_school_course`, `llx_school_session`, `llx_school_session_course` | The Dolibarr side of Chamilo courses and sessions (Dolibarr product, Chamilo id, language, dates, sync state) |
| `llx_school_teacher`, `llx_school_course_teacher`, `llx_school_session_teacher` | Teachers (Dolibarr users) and what they teach or coach |
| `llx_school_student`, `llx_school_student_parent` | Students, and their parents (Dolibarr third parties) with the share of the cost each parent pays |
| `llx_school_enrolment` | Individual enrolments of a student in a course or session |
| `llx_school_class`, `llx_school_class_student`, `llx_school_class_session` | Classes, their members and the sessions they follow |
| `llx_school_class_activity`, `llx_school_class_activity_invoice` | Extra activities of a class and what was invoiced to which parent |
| `llx_school_syncqueue` | The synchronization queue |

Teachers are Dolibarr users flagged as employees (**Home > Users and groups**): there is no separate teacher record to maintain.

Sessions and courses are service products, with the reference `SCH-C-<course code>` for an imported course, `SCH-S-<Chamilo id>` for an imported session and `SCH-SD-<n>` for a session created in Dolibarr. The module never changes the label, price or status of a product after creating it: those belong to Dolibarr.

A class is mirrored in Chamilo as a class (user group). Chamilo itself enrols the members of a class in the sessions linked to it, so Dolibarr does not create class enrolments of its own.

## Billing

**School > Billing** turns enrolments and class activities into **draft invoices** in Dolibarr's Invoices module (which the module needs and enables). Nothing is validated or sent: the drafts are reviewed and validated in Dolibarr.

- **What is invoiced.** Enrolments that are not being removed, in a session or course whose product has a price, and the participants of a class activity. The amount excl. tax is the price of the product (a price kept with tax is brought back to excl. tax), or, for an activity, its own price per student, or the price of the service chosen.
- **Who is invoiced.** The parents ticked **Payer** on the student's card. A student with no payer cannot be invoiced and is listed with a warning. Each paying third party gets **one invoice per run** with one line per child, whatever the number of children.
- **The shares.** A child with several payers is invoiced to each in proportion to their share. The shares are asked for when invoicing, must add up to 100, and are then kept on the student's parent link and proposed again next time. They are split to the cent so that the parts add up exactly.
- **Lines.** A line is a service line linked to the product, with the date of the session (start and end) for an enrolment and the day of the activity for an activity; the description names the child and, when several parents pay, the share.
- **VAT and terms.** The VAT rate is Dolibarr's default for the product, the seller company and the third party, and the payment terms and mode are those of the third party. Set up your company in Dolibarr first (see INSTALL.md).
- **Nothing is invoiced twice.** What was invoiced is recorded. A recorded invoice that has been **deleted** or **abandoned** no longer counts, so its items can be invoiced again. If only one of two payers' invoices is deleted, only that payer's part is offered again, and the other is not invoiced twice.
- **Not done.** Orders are not created, only invoices; multi-currency and per-customer prices are not handled; a price discount is entered by editing the draft.

The activities of a class are kept in `llx_school_class_activity` and what was invoiced for each in `llx_school_class_activity_invoice`; for enrolments, in `llx_school_enrolment_invoice`.

## Reconciliation

**School > Reconciliation** answers "does Chamilo still say what Dolibarr says?". It only reads: it never writes to Chamilo, and the report is not stored.

| Area | What is compared |
|---|---|
| Courses, sessions | Still exist in Chamilo; courses linked to a session; Chamilo items that were never imported |
| Students, teachers | Chamilo account exists and is not deleted; first name, last name, active flag |
| Enrolments | The student is in the Chamilo session or course; a student managed by Dolibarr who is in the Chamilo session without being enrolled or in a class that follows it |
| Classes | Members (current ones) and sessions against the Chamilo class |
| Rooms | Title, capacity, floor |
| Dates to come | The date exists in the attendance in Chamilo, with the same start and length |

Each finding is *missing in Chamilo*, *different*, *only in Chamilo* or *could not be checked*. Findings that a new push would fix carry the job that does it (tick boxes, on by default); the others are only information: an account, session, class or room deleted in Chamilo is not created again by itself, an item that exists only in Chamilo is imported from the Courses or Sessions pages, and a date whose attendance was taken in Chamilo is not moved. The jobs are queued, not run: they go through the synchronization queue and appear in the log. Students of a Chamilo session that Dolibarr does not manage are counted, not listed. A session that is not on the portal this module talks to cannot be read and is reported as such. The details of a finding are in English.

Cost: roughly one request per student, teacher and session checked, so use the area boxes on a big school; each area is limited to 300 objects per run.

## Rooms and schedule

Rooms are rows of **Dolibarr's Resources module** (which the module depends on), flagged as rooms in `llx_school_room` with a floor; the capacity is the resource's own `max_users`. A date of the schedule is an agenda event (`llx_actioncomm`, type *Other*) with the room booked in `llx_element_resources` as busy and the teachers assigned, linked to `llx_school_occurrence`. Deleting an occurrence deletes the event and its bookings.

- **Conflicts.** The room check is strict at the ends (a lesson may start when the previous one ends) and ignores cancelled dates. Capacity is only checked when set. Teacher conflicts are warnings.
- **In Chamilo.** One attendance sheet is kept per session and course (`llx_school_attendance`); each date is identified there by its exact start. A date is created, moved (start, end and room) or removed through the queue (job types `room` and `occurrence`). If Chamilo is not ready (session or course not yet created, no branch), the job waits and tries again; a missing sheet is recreated.
- **Taken attendance is protected.** If Chamilo says attendance was done for a date, the module does not move or remove it there; the room alone may change. The Dolibarr date then stays marked as waiting and shows the reason.
- **Branch.** A Chamilo room needs a branch. The setup page offers the branches of Chamilo; *Automatic* picks the only one and fails with a clear message when there are several.
- **Attendance read back.** Chamilo owns the results. At the end of each synchronization run, up to 20 dates that have taken place are read (`GET /api/c_attendance_calendars/{id}/sheet`), the least recently read first, and stored in `llx_school_presence` by student (Chamilo user id to `llx_school_student.chamilo_user_id`; other users such as teachers are ignored). A date is read again after 30 minutes until Chamilo reports its attendance as taken (`attendance_done`), then it is left alone. Presence codes are Chamilo's: 0 absent, 1 present, 2 late by less than 15 minutes, 3 late by more, 4 absent with a reason. A date that Chamilo no longer has, or an answer that is not understood, is skipped and read again later; what was stored stays. **Synchronize now** also reads attendance.
- **Upgrading an existing database.** `ALTER TABLE llx_school_occurrence ADD COLUMN attendance_done smallint DEFAULT 0 NOT NULL, ADD COLUMN last_pull datetime;` and create `llx_school_presence` from `sql/llx_school.sql`.

## Announcements to parents

The announcement uses Dolibarr's own **Emailing** module: sending, history, templates and unsubscription are its. Enable it in **Home > Setup > Modules** (it is not enabled by this module, because not every school sends announcements from Dolibarr), and give the people who send announcements its rights. The sender is the Emailing module's sender address (`MAILING_EMAIL_FROM`), else `MAIN_MAIL_EMAIL_FROM`, else the email of the user.

From the card of a class, the form prepares a **draft campaign**; nothing is sent until someone validates and sends it in the Emailing module. The recipients are one address per parent of the current members: the contact chosen on the student's card, else the third party's address, without duplicates (compared without regard to case). Parents without an address are skipped, and so are third parties that are disabled. The message typed in the form is plain text: HTML is removed when it is typed.

The same selector, **Parents of the students of a class**, is also offered when you choose recipients in any Emailing campaign.

## Security notes

- The key is encrypted at rest and never printed back.
- Every form carries Dolibarr's CSRF token; requests without it are refused.
- Redirects from Chamilo are not followed, so the key cannot be sent to another host.
- Private and loopback addresses are refused unless **Allow a local address** is on.
- Rights are checked on every page and every action.

## Troubleshooting

Start with the connection test on the setup page, then the School home page (queue counts), then the **Error** state of a session (hover for the message), then `last_error` of the job in `llx_school_syncqueue`.

| Message or symptom | Meaning |
|---|---|
| "Chamilo refused the key" | Wrong, revoked or expired key; `security.external_api_enabled` is off in Chamilo; or the URL points to another portal than the one the key was issued for |
| "The key is valid but its account is not an administrator" | Switch to an administrator service account |
| "Create session: ..." with a validation message | Chamilo rejected the data. The message is Chamilo's own; fix the data in Dolibarr and set the job back to Waiting. |
| Jobs stay Waiting | The scheduler is not running: check **Setup > Scheduled jobs** and the system cron, or press **Synchronize now** |
| A course or session you imported has no `dolibarr_id` in Chamilo | Press **Send Dolibarr ids to Chamilo** |
| A student stays in *Error*: "No email address can be built for this student" | The student has no email, no parent linked has one, and `SCHOOL_STUDENT_EMAIL_DOMAIN` is empty. Give the student an email, add a parent with an email, or set the domain. |
| A parent row shows "The parent ... has no contact with an email address" | Following a child in Chamilo needs a contact of the third party with an email, because the Chamilo account is created from it |
| "This Chamilo has no /api/sessions/{id}/students route: update Chamilo" | Your Chamilo does not have the administrator routes for the students of a session yet (see INSTALL.md). Enrolment in courses still works. |
| "Chamilo cannot enrol this account in a session" | The account is deleted, anonymous or a human resources manager: a parent cannot be enrolled as a student |
| Billing says a student "has no paying parent" | No parent of the student is ticked **Payer**: do it on the student's card |
| Billing refuses the shares | The shares of the paying parents of a child must add up to 100 |
| An enrolment is not offered for invoicing | Its product has no price, it is being removed, or it is invoiced already (check the invoices of the parents) |
| A class shows "N session(s) of the class are not in Chamilo yet" | A session linked to the class is not in Chamilo: it is retried automatically. Check that the session was created and synchronized. |
| A class shows "this Chamilo has no /api/usergroups/{id}/... class routes: update Chamilo" | Your Chamilo lacks the administrator routes for classes (see INSTALL.md) |
| "The Chamilo class no longer exists" | The class was deleted in Chamilo. The module does not create it again by itself. |
| The announcement form says the Emailing module is off | Enable the Emailing module in the module list |
| A teacher stays in state *Error*: "The Dolibarr user has no email address" | Add an email to the Dolibarr user, then use **Send again** on the teacher list |
| "All the usernames tried are taken" | The login and its numbered variants (`jdoe`, `jdoe.2`... up to 5) already exist in Chamilo. Change the Dolibarr login or create the Chamilo account by hand with the teacher's email, then use **Send again**: an account with the same email is linked. |
| "The Chamilo account no longer exists" | The account was deleted in Chamilo. The module does not create it again by itself, so that a deletion is not silently undone: remove the teacher's link or recreate the account. |
| A teacher is linked but shown with a warning | The existing Chamilo account found by email is not a teacher. The module does not change roles: change it in Chamilo if that is wanted. |
| After re-enabling the module, the menu is missing | Log out and in again, and open the module from the top menu |
