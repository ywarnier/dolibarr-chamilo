-- School external module. Tables are created on enable and kept on disable.

-- Course catalogue entry: one Dolibarr service product <-> one Chamilo course.
CREATE TABLE llx_school_course (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_product integer NOT NULL,
	chamilo_id integer,
	code varchar(40),
	language varchar(10),
	tutor_name varchar(255),
	sync_status smallint DEFAULT 0 NOT NULL,
	last_sync datetime,
	last_error text,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer,
	UNIQUE KEY uk_school_course_product (entity, fk_product),
	KEY idx_school_course_chamilo (chamilo_id)
) ENGINE=innodb;

-- Session: one Dolibarr service product <-> one Chamilo session.
CREATE TABLE llx_school_session (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_product integer NOT NULL,
	chamilo_id integer,
	display_start datetime,
	display_end datetime,
	access_start datetime,
	access_end datetime,
	coach_access_start datetime,
	coach_access_end datetime,
	seats integer,
	language varchar(10),
	sync_status smallint DEFAULT 0 NOT NULL,
	last_sync datetime,
	last_error text,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer,
	UNIQUE KEY uk_school_session_product (entity, fk_product),
	KEY idx_school_session_chamilo (chamilo_id)
) ENGINE=innodb;

-- Courses that belong to a session.
CREATE TABLE llx_school_session_course (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	fk_session integer NOT NULL,
	fk_course integer NOT NULL,
	chamilo_link_id integer,
	UNIQUE KEY uk_school_session_course (fk_session, fk_course)
) ENGINE=innodb;

-- Student. Not a third party: parents are the customers.
CREATE TABLE llx_school_student (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	ref varchar(32) NOT NULL,
	firstname varchar(100) NOT NULL,
	lastname varchar(100) NOT NULL,
	birthdate date,
	email varchar(255),
	chamilo_user_id integer,
	chamilo_username varchar(100),
	status smallint DEFAULT 1 NOT NULL,
	sync_status smallint DEFAULT 0 NOT NULL,
	last_sync datetime,
	last_error text,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer,
	UNIQUE KEY uk_school_student_ref (entity, ref),
	KEY idx_school_student_chamilo (chamilo_user_id)
) ENGINE=innodb;

-- A parent who follows children in Chamilo has a Chamilo account of their own (a human resources manager). It belongs
-- to the person, a contact of the third party, and is shared by all their children.
CREATE TABLE llx_school_guardian (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_socpeople integer NOT NULL,
	chamilo_user_id integer,
	chamilo_username varchar(100),
	sync_status smallint DEFAULT 0 NOT NULL,
	last_sync datetime,
	last_error text,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uk_school_guardian_contact (entity, fk_socpeople),
	KEY idx_school_guardian_chamilo (chamilo_user_id)
) ENGINE=innodb;

-- A student has one or more parents (third parties); a parent has several children.
-- chamilo_follow: 0 does not follow the child in Chamilo, 1 follows, 2 stopped following and the row is deleted once
-- Chamilo has been told.
CREATE TABLE llx_school_student_parent (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	fk_student integer NOT NULL,
	fk_soc integer NOT NULL,
	fk_socpeople integer,
	relation varchar(16) DEFAULT 'parent' NOT NULL,
	is_payer smallint DEFAULT 0 NOT NULL,
	payer_share double(5,2) DEFAULT 0 NOT NULL,
	chamilo_follow smallint DEFAULT 0 NOT NULL,
	datec datetime NOT NULL,
	UNIQUE KEY uk_school_student_parent (fk_student, fk_soc),
	KEY idx_school_parent_soc (fk_soc)
) ENGINE=innodb;

-- Individual enrolment of a student in a session or in a course. status: 0 to enrol in Chamilo, 1 enrolled, 3 to remove
-- (the row is deleted once removed). A student who is a member of a class is not listed
-- here for the class's sessions: Chamilo enrols the members of a class itself when the class is linked to a session.
CREATE TABLE llx_school_enrolment (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_student integer NOT NULL,
	fk_session integer,
	fk_course integer,
	status smallint DEFAULT 0 NOT NULL,
	chamilo_link_id integer,
	sync_status smallint DEFAULT 0 NOT NULL,
	last_sync datetime,
	last_error text,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer,
	KEY idx_school_enrolment_student (fk_student),
	KEY idx_school_enrolment_session (fk_session),
	KEY idx_school_enrolment_course (fk_course)
) ENGINE=innodb;

-- What was invoiced for an enrolment: one row per paying parent, with the share applied and the draft invoice. The
-- target is copied (not the enrolment id) so that the history stays when the enrolment is removed. An enrolment counts as
-- invoiced while a row points to an invoice that exists and is not abandoned.
CREATE TABLE llx_school_enrolment_invoice (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	fk_student integer NOT NULL,
	fk_session integer,
	fk_course integer,
	fk_soc integer NOT NULL,
	share double(5,2) NOT NULL,
	amount double(24,8) NOT NULL,
	fk_facture integer NOT NULL,
	datec datetime NOT NULL,
	KEY idx_school_enrolment_invoice_student (fk_student),
	KEY idx_school_enrolment_invoice_facture (fk_facture)
) ENGINE=innodb;

-- A room of the school: a Dolibarr resource (of the Resources module) that is also a room in Chamilo. The capacity is the
-- resource's own maximum number of users; the floor is kept here because a Dolibarr resource has none.
CREATE TABLE llx_school_room (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_resource integer NOT NULL,
	floor_number integer,
	chamilo_room_id integer,
	sync_status smallint DEFAULT 0 NOT NULL,
	last_sync datetime,
	last_error text,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uk_school_room_resource (entity, fk_resource),
	KEY idx_school_room_chamilo (chamilo_room_id)
) ENGINE=innodb;

-- The attendance tool that holds the dates of a course in a session (fk_session = 0 for a course on its own), created in
-- Chamilo the first time a date has to be sent.
CREATE TABLE llx_school_attendance (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_session integer DEFAULT 0 NOT NULL,
	fk_course integer NOT NULL,
	chamilo_attendance_id integer,
	datec datetime NOT NULL,
	UNIQUE KEY uk_school_attendance (entity, fk_session, fk_course)
) ENGINE=innodb;

-- A date of a course (in a session, or on its own), the occurrence of an attendance time. It is an event of the Dolibarr
-- agenda (fk_actioncomm) with the room reserved as a resource, and an entry of the attendance of the course in Chamilo.
-- status: 0 active, 3 to remove from Chamilo (the agenda event is already deleted, so the room is free; the row is deleted
-- once Chamilo has removed the date).
CREATE TABLE llx_school_occurrence (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_session integer DEFAULT 0 NOT NULL,
	fk_course integer NOT NULL,
	fk_actioncomm integer,
	fk_room integer,
	date_start datetime NOT NULL,
	date_end datetime NOT NULL,
	status smallint DEFAULT 0 NOT NULL,
	chamilo_calendar_id integer,
	sync_status smallint DEFAULT 0 NOT NULL,
	last_sync datetime,
	last_error text,
	attendance_done smallint DEFAULT 0 NOT NULL,
	last_pull datetime,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer,
	KEY idx_school_occurrence_course (fk_course, fk_session),
	KEY idx_school_occurrence_start (date_start),
	KEY idx_school_occurrence_room (fk_room)
) ENGINE=innodb;

-- What Chamilo recorded for a date, read back by the module. presence: 0 absent, 1 present, 2 late (< 15 min), 3 late (more), 4 absent with a reason.
CREATE TABLE llx_school_presence (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_occurrence integer NOT NULL,
	fk_student integer NOT NULL,
	presence smallint NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY uk_school_presence (fk_occurrence, fk_student),
	KEY idx_school_presence_student (fk_student)
) ENGINE=innodb;

-- Outgoing sync jobs. status: 0 pending, 1 done, 2 failed for good, 3 running.
CREATE TABLE llx_school_syncqueue (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	object_type varchar(32) NOT NULL,
	object_id integer NOT NULL,
	action varchar(16) NOT NULL,
	payload_hash varchar(40),
	status smallint DEFAULT 0 NOT NULL,
	attempts integer DEFAULT 0 NOT NULL,
	next_attempt datetime NOT NULL,
	last_error text,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	KEY idx_school_syncqueue_due (status, next_attempt),
	KEY idx_school_syncqueue_object (object_type, object_id)
) ENGINE=innodb;

-- Teacher: a Dolibarr user (employee) that also has an account on Chamilo.
CREATE TABLE llx_school_teacher (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_user integer NOT NULL,
	chamilo_user_id integer,
	chamilo_username varchar(100),
	sync_status smallint DEFAULT 0 NOT NULL,
	last_sync datetime,
	last_error text,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer,
	UNIQUE KEY uk_school_teacher_user (entity, fk_user),
	KEY idx_school_teacher_chamilo (chamilo_user_id)
) ENGINE=innodb;

-- Teachers of a course. sync_status: 0 to add in Chamilo, 1 in Chamilo, 3 to remove from Chamilo (the row is deleted once removed).
CREATE TABLE llx_school_course_teacher (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	fk_course integer NOT NULL,
	fk_teacher integer NOT NULL,
	sync_status smallint DEFAULT 0 NOT NULL,
	UNIQUE KEY uk_school_course_teacher (fk_course, fk_teacher)
) ENGINE=innodb;

-- Coaches of a course inside a session. sync_status as for llx_school_course_teacher.
CREATE TABLE llx_school_session_teacher (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	fk_session integer NOT NULL,
	fk_course integer NOT NULL,
	fk_teacher integer NOT NULL,
	sync_status smallint DEFAULT 0 NOT NULL,
	UNIQUE KEY uk_school_session_teacher (fk_session, fk_course, fk_teacher)
) ENGINE=innodb;

-- Class: students who follow the same sessions for a cycle. Mirrored to a Chamilo user group (NORMAL_CLASS).
CREATE TABLE llx_school_class (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	ref varchar(32) NOT NULL,
	label varchar(255) NOT NULL,
	level varchar(64),
	date_start date,
	date_end date,
	fk_homeroom_teacher integer,
	status smallint DEFAULT 1 NOT NULL,
	chamilo_id integer,
	sync_status smallint DEFAULT 0 NOT NULL,
	last_sync datetime,
	last_error text,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer,
	UNIQUE KEY uk_school_class_ref (entity, ref),
	KEY idx_school_class_chamilo (chamilo_id)
) ENGINE=innodb;

-- Class members. A student who leaves keeps the row with date_end, so past invoices and announcements stay traceable.
CREATE TABLE llx_school_class_student (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	fk_class integer NOT NULL,
	fk_student integer NOT NULL,
	date_start date NOT NULL,
	date_end date,
	KEY idx_school_class_student_class (fk_class),
	KEY idx_school_class_student_student (fk_student)
) ENGINE=innodb;

-- A class follows several sessions.
CREATE TABLE llx_school_class_session (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	fk_class integer NOT NULL,
	fk_session integer NOT NULL,
	UNIQUE KEY uk_school_class_session (fk_class, fk_session)
) ENGINE=innodb;

-- Extra activity done by a class (museum visit...) that is invoiced to the parents. status: 0 draft, 1 invoiced.
CREATE TABLE llx_school_class_activity (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_class integer NOT NULL,
	label varchar(255) NOT NULL,
	date_activity date,
	fk_product integer,
	unit_price double(24,8) DEFAULT 0 NOT NULL,
	status smallint DEFAULT 0 NOT NULL,
	datec datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer,
	KEY idx_school_class_activity_class (fk_class)
) ENGINE=innodb;

-- Who was invoiced what for an activity: one row per child and paying parent, with the share applied.
CREATE TABLE llx_school_class_activity_invoice (
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	fk_activity integer NOT NULL,
	fk_student integer NOT NULL,
	fk_soc integer NOT NULL,
	share double(5,2) NOT NULL,
	amount double(24,8) NOT NULL,
	fk_facture integer,
	KEY idx_school_activity_invoice_activity (fk_activity),
	KEY idx_school_activity_invoice_facture (fk_facture)
) ENGINE=innodb;
