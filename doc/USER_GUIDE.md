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

## When something is not synchronized

- A change that waits is normal for a few minutes. Press **Synchronize now** to send it at once.
- If Chamilo is unreachable, the module tries again by itself, with longer and longer pauses, for several hours.
- A session in state *Error* was refused by Chamilo: read the message, correct the data and ask your administrator to send it again.
- A number under *Failed* on the home page means the same thing for any other change.

## What is coming

These are planned and are not available yet:

- creating and editing courses from Dolibarr, and changing a session you created;
- teachers: Dolibarr employees who also get a Chamilo account, and assignment to courses and sessions;
- students and their parents (your customers), and enrolling students in courses and sessions;
- classes of students, announcements to all the parents of a class, and invoicing an activity of a class;
- invoicing enrolments;
- rooms and attendance times, using Dolibarr's Resources module.
