# MCP tools

The School module adds its tools to Dolibarr's MCP server (the hook `addMcpTools` of the context `aimcp`), so that everything the screens do can be done by an AI assistant or any MCP client, with the same rules: the tools call the same classes as the pages, and nothing is done that a screen would refuse.

## Set up

Setting the MCP server up (modules, the user the assistant acts as, its rights, the tool access page, registering the tools, checks and troubleshooting) is in the [administrator guide](ADMIN_GUIDE.md#mcp-tools). Using the tools, with examples, is in the [user guide](USER_GUIDE.md#using-an-assistant-mcp). This page is the reference of the tools.

## Rules the tools follow

- **Names.** Verb first with `school` after it (`create_school_student`). A tool that writes starts with `create`, `update`, `delete`, `add`, `remove`, `change` or `send`, and a tool that only reads does not: Dolibarr's tool access screen uses that to tell "Modify" from "View only". `test/cli/mcp_tools_check.php` checks it.
- **Confirmation.** On Dolibarr 25 a tool that writes does not run at the first call: the client gets a sentence saying what would be done and must confirm. On 24.0 there is no such step.
- **Queued, not instant.** What changes Chamilo is queued as in the screens (see the synchronization log); the tool answers `queued: true`. `send_school_changes_to_chamilo` runs the queue at once.
- **Nothing is sent by e-mail.** The announcement tool only prepares a draft campaign in the Emailing module. Invoices are drafts, never validated.
- **What is deleted in Chamilo is not recreated** by a tool, as in the screens; the reconciliation tools only report it.
- **Errors** come back as `{"error": "..."}` in the language of the user, never as an exception.
- **Lists** return at most 200 rows (`limit`), as arrays of plain values; ids are the School ids (not the Chamilo ones, which are shown beside them).

### Catalogue and teachers

| Tool | Kind | Right | What it does |
|---|---|---|---|
| `list_school_courses` | read | `school/read` | List the courses of the school (Chamilo courses, kept as Dolibarr service products), with the Chamilo id, price, language and synchronization state. |
| `list_school_sessions` | read | `school/read` | List the sessions of the school with their dates, courses and synchronization state. |
| `create_school_session` | write | `school/write` | Create a session in Dolibarr (a service product) and queue its creation in Chamilo, with the courses it contains. |
| `update_school_catalog_from_chamilo` | write | `school/sync` | Read the courses and sessions of Chamilo and create or link the matching service products in Dolibarr. |
| `list_school_teachers` | read | `school/read` | List the teachers (Dolibarr employees with a Chamilo account). |
| `add_school_teacher` | write | `school/teacher` | Make a Dolibarr employee a teacher: queues the creation of the Chamilo account (teacher role only), or links the account with the same email. |
| `update_school_teacher_assignments` | write | `school/teacher` | Set the teachers of a course, or the coaches of the courses of a session. |

### Students, parents, enrolments, attendance

| Tool | Kind | Right | What it does |
|---|---|---|---|
| `list_school_students` | read | `school/read` | List students with their parents (third parties, payer flag and share), Chamilo account and synchronization state. |
| `create_school_student` | write | `school/write` | Create a student and queue their Chamilo account (student role). |
| `update_school_student` | write | `school/write` | Change a student. |
| `add_school_student_parent` | write | `school/write` | Link a parent (a Dolibarr third party, optionally one of its contacts) to a student. |
| `update_school_student_parent` | write | `school/write` | Change a parent link of a student (from list_school_students: parents[].link_id). |
| `remove_school_student_parent` | write | `school/write` | Remove a parent link. |
| `add_school_enrolment` | write | `school/write` | Enrol a student in a session, or in a course on its own (give exactly one of session_id and course_id). |
| `remove_school_enrolment` | write | `school/write` | Remove an enrolment (from list_school_students: enrolments[].id). |
| `list_school_attendance` | read | `school/read` | Attendance read back from Chamilo: for a student, the presence at each date (present, late, absent, absent with a reason), with totals. |

### Classes and announcements

| Tool | Kind | Right | What it does |
|---|---|---|---|
| `list_school_classes` | read | `school/read` | List the classes of students. |
| `create_school_class` | write | `school/class` | Create a class: a group of students who follow the same sessions during a cycle (not necessarily a school year). |
| `update_school_class` | write | `school/class` | Change a class. |
| `add_school_class_student` | write | `school/class` | Add a student to a class from a day (today by default). |
| `remove_school_class_student` | write | `school/class` | End a student's membership of a class (members[].member_id from list_school_classes with id). |
| `add_school_class_session` | write | `school/class` | Make a class follow a session. |
| `remove_school_class_session` | write | `school/class` | Stop a class following a session. |
| `create_school_class_announcement_draft` | write | `school/class` + `mailing/creer` | Prepare a DRAFT campaign in Dolibarr's Emailing module addressed to the parents of the current members of a class (one message per address). |
| `create_school_class_activity` | write | `school/class` | Record an extra activity done by a class (museum visit, trip) that will be invoiced to the parents of the participants. |
| `delete_school_class_activity` | write | `school/class` | Delete an activity that was not invoiced. |

### Billing

| Tool | Kind | Right | What it does |
|---|---|---|---|
| `list_school_billable` | read | `school/invoice` | List what can be invoiced: enrolments that are not invoiced yet, or, with activity_id, the participants of a class activity who are not invoiced yet. |
| `create_school_invoices` | write | `school/invoice` + `facture/creer` | Create DRAFT invoices (never validated) for the chosen items: one invoice per paying third party with a line per child. |

### Rooms and schedule

| Tool | Kind | Right | What it does |
|---|---|---|---|
| `list_school_rooms` | read | `school/read` | List the rooms (resources of the Resources module used for lessons) with capacity, floor and Chamilo state. |
| `add_school_room` | write | `school/write` | Use a Resources-module resource as a room. |
| `update_school_room` | write | `school/write` | Change the capacity and floor of a room. |
| `update_school_rooms_from_chamilo` | write | `school/sync` | Read the rooms of Chamilo and create or link the matching resources in Dolibarr (matched by name). |
| `list_school_dates` | read | `school/read` | List the dates (lesson occurrences) of a session's courses (session_id) or of a course on its own (course_id), or, without either, the dates to come, optionally for one room. |
| `create_school_dates` | write | `school/write` | Add one date or a series for a course (in a session, or on its own). |
| `update_school_date` | write | `school/write` | Move a date or change its length or room. |
| `delete_school_date` | write | `school/write` | Cancel a date: it is removed from the agenda, frees the room and is removed from Chamilo unless its attendance was already taken there. |

### Synchronization

| Tool | Kind | Right | What it does |
|---|---|---|---|
| `get_school_sync_status` | read | `school/read` | How many synchronization jobs are waiting, running, failed or done, and whether the Chamilo connection is configured. |
| `list_school_sync_jobs` | read | `school/read` | The synchronization log: jobs sent to Chamilo, newest first, with what each is about, status, attempts, next try and error message. |
| `change_school_sync_jobs_to_waiting` | write | `school/sync` | Put a failed (or already failed waiting) job back in line, or every failed job with all_failed=true. |
| `delete_school_sync_job` | write | `school/sync` | Forget a failed job. |
| `send_school_changes_to_chamilo` | write | `school/sync` | Run the synchronization now: send the due jobs to Chamilo and read back attendance. |
| `check_school_reconciliation` | read | `school/sync` | Compare Dolibarr with Chamilo and list the differences, changing nothing. |
| `create_school_sync_jobs_from_reconciliation` | write | `school/sync` | Queue the jobs that check_school_reconciliation proposed, by their fix value ("type:id:action"). |

## What was verified

The tools were run on Dolibarr 25.0.0-alpha, with the confirmation step, and on a snapshot of the core taken just before the commits that added the per-tool rights and the confirmation step to the MCP server (the closest thing to 24.0.0 that was available here: no release of 24.0 was). That snapshot registers the tools and runs the checks of `test/cli/mcp_tools_check.php`. A real 24.0.0 release should be checked with that script before relying on the tools there.

## Checking the tools

`php test/cli/mcp_tools_check.php /path/to/dolibarr/htdocs` (also with `DOLIBARR_HTDOCS`, and `DOLIBARR_HOST` for the host name) verifies that the tools are registered by the core it runs against, that each declares rights and has a handler, that the write verbs match the tools that write, that every tool answers without arguments with its own error and that a user without rights is refused by all of them. Run it against every core the module supports. It does not call Chamilo and changes nothing.
