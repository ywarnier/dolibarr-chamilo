# School for Dolibarr

Manage a school in [Dolibarr](https://www.dolibarr.org) and keep [Chamilo](https://chamilo.org) in sync.

Courses and sessions are Dolibarr service products (with their language, dates and price), students belong to parents who are Dolibarr customers, teachers are Dolibarr employees, and the actions taken in Dolibarr are sent to Chamilo through its REST API.

- Module number: `104802`, technical name `school`, version `0.1.0`
- Requires Dolibarr 24.0 or later, PHP 8.1 or later, MySQL or MariaDB
- License: GPL v3 (see `LICENSE`)

## Status

This is an early version. The plumbing and the catalogue work, the people-related features are still to come.

| Area | State |
|---|---|
| Connection to Chamilo (API key, connection test) | Done |
| Synchronization queue (retries, backoff, cron) | Done |
| Courses and sessions imported from Chamilo as service products | Done |
| `dolibarr_id` and `price` extra fields in Chamilo | Done |
| Creating a session in Dolibarr and in Chamilo | Done |
| Creating or editing a course from Dolibarr | Planned |
| Teachers (Dolibarr employees with a Chamilo account) | In progress |
| Students, parents, enrolments | Planned (tables exist) |
| Classes, class announcements, class activity invoicing | Planned (tables exist) |
| Billing of enrolments | Planned |
| Rooms and attendance occurrences (Dolibarr Resources module) | Planned |
| Synchronization from Chamilo to Dolibarr, sync log screen | Planned |
| MCP tools, so that every feature of the module can be driven through Dolibarr's MCP server | Planned |

See [ChangeLog.md](ChangeLog.md) for what changed in each version.

## How it works

Dolibarr is the place where things are created and edited. Each change that has to reach Chamilo becomes a job in a queue (`llx_school_syncqueue`). A scheduled job, or the **Synchronize now** button, sends the pending jobs. A job that fails because Chamilo is unreachable is retried with a growing delay; a job that fails for a reason that will not go away (a validation error, a refused permission) stops and shows its error.

Chamilo is reached with an **external API key** issued for an administrator service account (`chamilo:security:generate-external-api-key`). No password is stored and no OAuth flow is involved.

Every object that exists on both sides is linked by an id on each side: the Chamilo id in the School tables, and the Dolibarr product id in the `dolibarr_id` extra field in Chamilo. Looking an object up by that id before creating it is what keeps a retry from creating a duplicate.

## Documentation

- [INSTALL.md](INSTALL.md): requirements and installation.
- [doc/ADMIN_GUIDE.md](doc/ADMIN_GUIDE.md): configuration, rights, the sync queue, troubleshooting.
- [doc/USER_GUIDE.md](doc/USER_GUIDE.md): day-to-day use for school staff.

## Chamilo side

The module relies on Chamilo API features that are in Chamilo's `master` branch:

- the external API key authentication;
- `PATCH` and `DELETE` on courses (administrators only);
- the class routes `GET /api/usergroups/{id}/subscriptions` and `PUT /api/usergroups/{id}/users|sessions|courses` (administrators only);
- the correct `nbrCourses` counter when a course is linked to a session through the API.

## Layout

```text
core/modules/modSchool.class.php   Module descriptor: rights, menus, cron job, init and remove
sql/llx_school.sql                 Schema, created on enable and kept on disable
class/                             ChamiloClient, SyncQueue, SchoolCron, SchoolCatalog, ChamiloSetup, SchoolSessionSync
lib/                               school_bootstrap.inc.php (loads Dolibarr), school.lib.php, schoollogic.lib.php (pure rules)
admin/setup.php                    Connection settings and the "Prepare Chamilo" steps
index.php course_list.php          Pages
session_list.php session_card.php
langs/{en_US,fr_FR}/school.lang    Translations
test/phpunit/                      Tests of the pure rules
```

No core file of Dolibarr is changed.

## Tests

The tests of the pure rules (URL handling, backoff, mapping of Chamilo data, prices, usernames) do not load Dolibarr:

```bash
phpunit --no-configuration --do-not-cache-result test/phpunit/SchoolLogicTest.php
```

PHPUnit 13 needs PHP 8.4 or later.
