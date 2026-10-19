# Administrator guide

For the person who installs and configures the School module. Installation steps are in [INSTALL.md](../INSTALL.md); this guide explains what is configured, what the module does behind the scenes, and what to check when something goes wrong.

## Settings

Everything is on **Home > Setup > Modules/Applications > School (wrench icon)**.

| Setting | Constant | Notes |
|---|---|---|
| Chamilo portal URL | `SCHOOL_CHAMILO_URL` | Address of the portal without `/api`. Must start with `http://` or `https://`. A trailing slash or `/api` is removed. |
| External API key | `SCHOOL_CHAMILO_API_KEY` | Stored encrypted with Dolibarr's own encryption. The field stays empty after saving: leaving it empty keeps the stored key. |
| Allow a local address | `SCHOOL_CHAMILO_ALLOW_LOCAL` | Off by default. When off, the module refuses to call a private or loopback address, as protection against requests to internal services. Turn it on on a development machine only. |
| Chamilo extra field ids | `SCHOOL_EF_COURSE_DOLIBARR_ID`, `SCHOOL_EF_COURSE_PRICE`, `SCHOOL_EF_SESSION_DOLIBARR_ID`, `SCHOOL_EF_SESSION_PRICE` | Filled by **Create the extra fields**. Shown at the bottom of the page. |

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
| Manage teachers and their Chamilo accounts (`teacher`) | Reserved for the teacher features, so that not everyone who can edit school data can create Chamilo accounts for staff |
| Manage classes, announcements and class activity invoicing (`class`) | Reserved for the class features |

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

To retry a Failed job after fixing the cause, set its status back to 0 (a screen for this is planned):

```sql
UPDATE llx_school_syncqueue SET status = 0, attempts = 0, next_attempt = NOW() WHERE rowid = <id>;
```

## What is created in Chamilo

- Two extra fields on **courses** and two on **sessions**: `dolibarr_id` (text, the Dolibarr **product id**) and `price` (decimal). They hold the link between the two systems and let Dolibarr find an object again without relying on its title.
- `price` is the field the Chamilo WordPress storefront plugin reads. It is written only when the Dolibarr price is above zero: for the storefront, an item with no price is not for sale.
- Sessions created from Dolibarr, with their dates and courses.

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

Sessions and courses are service products, with the reference `SCH-C-<course code>` for an imported course, `SCH-S-<Chamilo id>` for an imported session and `SCH-SD-<n>` for a session created in Dolibarr. The module never changes the label, price or status of a product after creating it: those belong to Dolibarr.

A class is mirrored in Chamilo as a class (user group). Chamilo itself enrols the members of a class in the sessions linked to it, so Dolibarr does not create class enrolments of its own.

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
| After re-enabling the module, the menu is missing | Log out and in again, and open the module from the top menu |
