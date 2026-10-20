# Installation

## Requirements

| | |
|---|---|
| Dolibarr | 24.0 or later (developed against 25.0.0-alpha). The module is meant to be driven through Dolibarr's MCP server as well as through its screens, which sets this minimum. |
| PHP | 8.1 or later, with the `curl` extension |
| Database | MySQL or MariaDB. The schema uses MySQL syntax; PostgreSQL is not supported. |
| Dolibarr modules | Third parties, Products, Services, Agenda (they are enabled with the module if they are off). The Invoices module is enabled with it, because billing needs it. The Emailing module is needed to send announcements to parents, and is not enabled for you. |
| Chamilo | A recent `master` (see "Chamilo side" below) |

## 1. Put the module in Dolibarr

The module is a flat directory: the repository root is the module root. Dolibarr loads external modules from a "custom" directory.

1. Make sure `htdocs/conf/conf.php` declares the custom directory:

   ```php
   $dolibarr_main_url_root_alt = '/custom';
   $dolibarr_main_document_root_alt = '/path/to/dolibarr/htdocs/custom';
   ```

2. Put the module in `htdocs/custom/school`. A symbolic link is the easiest way to keep the repository elsewhere:

   ```bash
   ln -s /path/to/dolibarr-chamilo /path/to/dolibarr/htdocs/custom/school
   ```

   The directory must be called `school`: Dolibarr derives the paths of the module from that name.

The web server must be able to read the module directory through the link.

## 2. Enable the module

1. Log in to Dolibarr as an administrator.
2. Go to **Home > Setup > Modules/Applications**, find **School** and enable it.

Enabling creates the `llx_school_*` tables, registers the rights and the menu, and declares the scheduled job. Disabling the module removes the menu and the rights and **keeps the tables and their data**. The scheduled job stays registered but does nothing while the module is off. Enabling the module again restores everything without duplicating the job.

## 3. Prepare Chamilo

On the Chamilo server:

1. In **Administration > Platform settings > Security**, enable the external API key authentication (setting `security.external_api_enabled`). It is off by default.
2. Choose or create an administrator account that will act as the service account. It must have the administrator role: the synchronization creates and changes sessions, extra fields and class subscriptions, which only administrators may do.
3. Generate the key for that account:

   ```bash
   php bin/console chamilo:security:generate-external-api-key <username> --access-url-id=1
   ```

   Copy the key (it starts with `chamilo_ext_`). It is shown once. `--revoke` revokes it.

A key belongs to one Chamilo portal (access URL). A Dolibarr entity talks to one portal.

## 3b. Set up your company in Dolibarr

Invoices use the seller company of Dolibarr: set it in **Home > Setup > Company/Organization** (name, country, VAT number if you have one) before invoicing, as the VAT rate that goes on the lines depends on it. This is a Dolibarr setting, not a School one.

## 4. Configure the connection

In Dolibarr, open the setup of the module: **Home > Setup > Modules/Applications**, then the wrench icon of **School**. (The School home page also links to it while the connection is not configured.)

1. Enter the **portal URL** without `/api`, for example `https://campus.example.com`.
2. Paste the **external API key**. It is stored encrypted and never displayed again.
3. Leave **Allow a local address** off, except on a development machine where Chamilo runs on a private or loopback address.
4. Save, then press **Test the connection**. A message tells you whether the key was refused, is valid but not an administrator, or works.

Then, in the **Prepare Chamilo** block, run the two steps once:

1. **Create the extra fields**: creates `dolibarr_id` and `price` on courses and sessions in Chamilo. Fields that already exist are kept.
2. **Send Dolibarr ids to Chamilo**: writes the Dolibarr product id on the courses and sessions that are already linked. It does nothing until you have imported something, and it is safe to repeat.

## 5. Import the catalogue

Open **School > Courses** and press **Import from Chamilo**. Courses and sessions come in as service products that are not for sale and have a price of 0, ready for you to set. Run **Send Dolibarr ids to Chamilo** again afterwards so the imported items carry their Dolibarr id in Chamilo.

## 6. Scheduled job

Enabling the module declares the job **SchoolSyncQueue**, every 5 minutes. Dolibarr only runs its scheduled jobs if something triggers them, for example the system cron:

```cron
*/5 * * * * www-data php /path/to/dolibarr/scripts/cron/cron_run_jobs.php <security-key> <dolibarr-admin-login> > /dev/null
```

The security key is in **Setup > Scheduled jobs**. Without it, nothing is lost: pending changes wait in the queue, and **Synchronize now** (on the Sessions page) sends them by hand.

## Upgrading

The module has no update scripts yet. The schema is created when the module is enabled; tables that exist are not altered. Until the first stable release, a change of schema is documented in [ChangeLog.md](ChangeLog.md) with the `ALTER` statements to run by hand.

## Uninstalling

Disable the module. To also remove its data, drop the `llx_school_*` tables yourself. The extra fields and values created in Chamilo are not removed, because the Chamilo API cannot delete them.

## Chamilo side

The module needs these features of Chamilo's `master` branch:

- the external API key authentication;
- `PATCH` and `DELETE` on `/api/courses/{id}`, for administrators;
- `GET /api/usergroups/{id}/subscriptions` and `PUT /api/usergroups/{id}/users`, `/sessions`, `/courses`, for administrators;
- the class routes `GET /api/usergroups/{id}/subscriptions` and `PUT /api/usergroups/{id}/users`, `/sessions`, `/courses`, for administrators: the Chamilo class of a Dolibarr class is kept up to date through them;
- `GET /api/sessions/{id}/students` and `POST /api/sessions/{id}/students/subscribe` and `unsubscribe`, for administrators: enrolment in a session goes through them, because the existing session actions follow a platform setting meant for tutors. Without them, enrolling a student in a session fails with a message asking to update Chamilo; enrolment in a course and everything else work;
- rooms (`/api/rooms`, which needs at least one branch) and attendances (`/api/attendances` with its calendars), for administrators: the schedule goes through them. Nothing else is needed for the schedule; without them the dates stay in Dolibarr and the queue shows the error;
- the fix that keeps a session's course counter right when a course is linked through `/api/session_rel_courses`.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| The School left menu is not shown | Open the page from the **School** entry of the top menu. Dolibarr shows the left menu of the module you came from. |
| A page answers HTTP 500 with an empty body | A PHP error with `display_errors` off. Set `$dolibarr_main_prod = '0'` in `conf.php` on a test instance, or read the PHP error log. |
| "Chamilo refused the key" | The key is wrong, revoked or expired, `security.external_api_enabled` is off, or the Chamilo portal in the URL is not the one the key was issued for. |
| "The key is valid but its account is not an administrator" | Use an administrator service account. |
| Connection fails with "bad hostname IP (private or reserved range)" | The portal is on a private or loopback address. Turn on **Allow a local address** on a development machine only. |
| The menu entry appears but the page says access denied | The user lacks the **School** rights: **Home > Setup > Users and groups**. |
