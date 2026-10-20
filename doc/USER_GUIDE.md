# User guide

For school staff who use Dolibarr every day. If the module is not connected to Chamilo yet, ask your administrator ([ADMIN_GUIDE.md](ADMIN_GUIDE.md)).

## The idea

Your school's courses and sessions exist in two places: in Chamilo, where students learn, and in Dolibarr, where you manage prices, customers and invoices. The School module keeps them together:

- A **course** or a **session** of Chamilo is a **service product** in Dolibarr. You set its price and decide whether it is sold, like any product.
- What you create in Dolibarr is sent to Chamilo for you.

The **School** entry of the top menu opens the home page. If you do not see it, you lack the School rights: ask your administrator.

## Home

The home page tells you whether Dolibarr is connected to Chamilo, and shows the synchronization queue: how many changes are waiting, running, failed or done. A number above zero under *Failed* means a change could not reach Chamilo: see "When something is not synchronized" below.

## Courses

**School > Courses** lists the Chamilo courses known to Dolibarr, with their reference, label, language, Chamilo id and the date of their last synchronization. A click on the reference opens the product.

**Import from Chamilo** brings in the courses and sessions that Chamilo has and Dolibarr does not know yet. For each one it creates a service product that is **not for sale** and has a **price of 0**. Nothing is changed in Chamilo. If you press it again later, it only adds what is new and refreshes the language and the dates; it never overwrites the label, the price or the sale status you set in Dolibarr.

After importing, set the price of the courses you sell and mark them for sale, from the product card.

## Sessions

**School > Sessions** lists the sessions with their display dates, access dates, number of courses, Chamilo id and synchronization state:

| State | Meaning |
|---|---|
| Waiting | Created in Dolibarr, not sent to Chamilo yet |
| Synchronized | Chamilo has it |
| Error | Chamilo refused it. Point at the word *Error* to read why. |

### Creating a session

1. On the Sessions page, press **New session**.
2. Fill in:
   - **Label**: the name of the session. Required.
   - **Display start** and **Display end**: when the session runs. Both are required, and the end cannot be before the start.
   - **Price (excl. tax)**: leave empty if the session is not for sale.
   - **Courses**: tick the courses the session contains. Only courses that came from Chamilo are listed: import them first if the list is empty.
3. Press **Create**.

The session exists in Dolibarr at once, as a service product. It reaches Chamilo at the next synchronization, within a few minutes, or immediately if you press **Synchronize now** on the Sessions page (this needs the synchronization right). Its state changes to *Synchronized*.

In Chamilo the session has the same dates for display and for access, and the courses you ticked. Students are not enrolled by this step.

## Teachers

**School > Teachers** lists your employees, that is the Dolibarr users flagged as employees. A teacher is simply an employee who has also a Chamilo account.

1. Next to an employee, press **Enable on Chamilo**. The employee needs an email address. The account is created at the next synchronization, with the **teacher** role and nothing else, and Chamilo emails them a link to choose a password.
2. If a Chamilo account with the same email already exists, it is linked instead, and it is left as it is. A warning icon tells you when that account is not a teacher.
3. The Chamilo username is the Dolibarr login when it is free. If it is taken, a number is added (`jdoe.2`). The username that was really used is shown in the list.

Afterwards the Chamilo account follows the Dolibarr user: changing the name, email or phone, or disabling the user, is sent to Chamilo by itself. **Send again** forces an update.

### Choosing the teachers of a course

On **School > Courses**, press the pencil in the *Teachers* column of a course, tick the teachers, and save. They become the teachers of the course in Chamilo at the next synchronization. Unticking someone removes them from the course in Chamilo.

### Choosing the coaches of a session

On **School > Sessions**, press the pencil in the *Coaches* column. For each course of the session, tick who coaches it in this session. The pencil is shown when the session is in Chamilo and has at least one course.

Both pages need the *Manage teachers* right.

## Students and parents

**School > Students** lists the students. A student is not a third party: the **parents** are your customers, and a student is linked to them.

### Creating a student

Press **New student**, give the first and last name (the birth date and an email are optional) and press **Create**. The student's Chamilo account is created at the next synchronization, with the student role. The username is built from the name (`elodie.francois`; a number is added if it is taken).

A student often has no email, and Chamilo needs one for each account. In that case Chamilo is given the email of the first parent that has one, with the username after a plus sign (`parent+elodie.francois@example.org`). It reaches the parent's mailbox, where Chamilo's message to choose a password arrives, so the parent can set the child's password. Give the student an email of their own if you prefer.

### Parents

On the student's card, under **Parents**, pick a third party and press **Add a parent**, then complete its row:

- **Contact**: the person at that third party, used if the parent follows the child in Chamilo.
- **Relation**: parent, guardian or other.
- **Payer** and **Share of the cost**: who pays for this child and how much of the cost. This is kept for the invoicing of the child's activities, and nothing is invoiced yet.
- **Follows in Chamilo**: tick it to give the parent a Chamilo account with which they follow the child (in Chamilo the parent is a human resources manager, the role that follows other users). The contact needs an email. The same parent account follows all their children. Unticking it removes the following and keeps the account.

Press **Save** on the row. A parent can be linked to several children, and a child to several parents. A third party's card has a **Children** tab that lists its children.

### Enrolling a student

On the student's card, under **Enrolments**, pick a session or a course and press **Enrol**. Only sessions and courses that are in Chamilo are offered. Enrolling in a session enrols the student in all its courses. **Remove** takes the student out again. Both reach Chamilo at the next synchronization.

### An inactive student

Unticking **Active** on the card deactivates the student's Chamilo account; ticking it again reactivates it.

## Classes

A **class** is the students who follow the same sessions for a teaching cycle, and the unit you write to and, later, invoice. **School > Classes** lists them; archived classes are hidden unless you ask.

### Creating a class

Press **New class**, give it a name, and optionally a level, the start and end of the cycle (it does not have to be a school year) and a homeroom teacher. It is created in Chamilo as a class at the next synchronization.

### Members and sessions

On the card of a class:

- **Members**: pick a student and a date, then **Add**. **Remove** takes a student out of the class from now on. The row stays, ended the day before, so the announcements and invoices of that student stay traceable, and the student can join again later. Only the current members are in the Chamilo class.
- **Sessions of the class**: pick the sessions the class follows. In Chamilo, every member is enrolled in them, so you do not enrol the students one by one. A student who leaves the class is taken out of these sessions.

A student without a Chamilo account yet is left out of the Chamilo class and listed with a warning; they join by themselves when the account is created.

### Announcing something to all the parents

At the bottom of the card of a class, write a subject and a message and press **Prepare the announcement**. The parents of the current members are the recipients: one message per parent, even with several children in the class, to the contact you chose on the student's card or else to the third party. You land on the campaign in the **Emailing** module, which is a draft: check the recipients and the text, then validate and send it there. The Emailing module has to be enabled; ask your administrator if the form says it is not.

## Billing

**School > Billing** invoices what parents owe. Everything it creates is a **draft invoice**: you check it and validate it in Dolibarr.

### Who pays

On a student's card, tick **Payer** for the parents who pay. Without a payer a student cannot be invoiced. When two parents pay, you give each one's **share** (for example 70 % and 30 %) when invoicing; it is remembered for that child and proposed again next time.

### Invoicing enrolments

1. Open **School > Billing**. It lists the enrolments that are not invoiced yet and whose session or course has a price.
2. Tick the ones to invoice and press **Review**.
3. For children with several payers, check or change the shares, and press **Recalculate** to see the amounts. You see the invoices that will be created: **one per paying parent**, with a line per child, so a family with two children gets a single invoice.
4. Press **Create the draft invoices**, then open them from the result to validate them.

If something is wrong, delete the draft: what it covered is offered again.

### Invoicing an activity of a class

On the card of a class, under **Activities**, give what happened (for example *Museum visit*), the day, and the price per student (or choose a service whose price is used), then **Add the activity**. Press **Invoice** on the activity: the participants are the members of the class that day; untick the children who did not take part, then review and create the drafts as above. An activity is marked *Invoiced* once all its participants are. A late participant can still be invoiced later.

## Rooms and schedule

### Rooms
**School > Rooms** lists the rooms. Create the room in the Resources module (the link is on the page) or pick an existing resource under *Use a resource as a room*, with its capacity and floor. *Import the rooms of Chamilo* brings in the rooms that already exist there. The calendar icon opens the room's planning.

### Dates of a session or a course
In **Sessions** or **Courses**, the calendar icon opens the schedule. Under *Add dates*, choose the course (for a session), the first day and, for a series, the last day, the weekdays and the number of weeks between two dates (1 = every week), then the start time, the duration in minutes and the room.

- If the room is busy on some dates, nothing is created unless you tick *Skip the dates when the room is busy*; the skipped dates are listed.
- Each date can then be changed (day, time, duration, room) with **Save**, or **Cancel**led. The dates reach Chamilo as the attendance dates of the course, with their room.
- A date for which attendance was already taken in Chamilo cannot be moved or cancelled from here; only its room can change.
- **School > Schedule** shows all the dates to come, and can be limited to one room.

## When something is not synchronized

- A change that waits is normal for a few minutes. Press **Synchronize now** to send it at once.
- If Chamilo is unreachable, the module tries again by itself, with longer and longer pauses, for several hours.
- A session in state *Error* was refused by Chamilo: read the message, correct the data and ask your administrator to send it again.
- A number under *Failed* on the home page means the same thing for any other change.

## What is coming

These are planned and are not available yet:

- creating and editing courses from Dolibarr, and changing a session you created;
- reading attendance (who was present) back from Chamilo.
