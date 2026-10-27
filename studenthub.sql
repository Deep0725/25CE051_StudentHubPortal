-- ==========================================================================
-- StudentHub Database
-- File: studenthub.sql
-- Import this file in phpMyAdmin (or run it from the MySQL command line).
-- It creates:
--   1. database  -> studenthub
--   2. table     -> students
--   3. table     -> events
--   4. table     -> registrations  (links students and events)
-- ==========================================================================

-- ---------------------------------------------------------------------------
-- 1. CREATE THE DATABASE
-- utf8mb4 stores any language / emoji correctly
-- ---------------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS studenthub
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

-- Always select the database before creating tables
USE studenthub;

-- ---------------------------------------------------------------------------
-- 2. TABLE: students
-- Stores registered students.
-- The 'password' column holds the HASH made by password_hash() in PHP.
-- A plain-text password must NEVER be stored here.
-- ---------------------------------------------------------------------------
CREATE TABLE students (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id  VARCHAR(20)  NOT NULL,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(100) NOT NULL,
    mobile      VARCHAR(10)  NOT NULL,
    date_of_birth DATE       DEFAULT NULL,
    password    VARCHAR(255) NOT NULL,
    course      VARCHAR(50)  NOT NULL,
    year        VARCHAR(20)  NOT NULL,
    gender      ENUM('Male','Female','Other') NOT NULL,
    role        VARCHAR(20)  NOT NULL DEFAULT 'student',
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Every row needs a unique, auto-increasing primary key
    PRIMARY KEY (id),

    -- student_id and email must be different for every student
    UNIQUE KEY uq_students_student_id (student_id),
    UNIQUE KEY uq_students_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. TABLE: events
-- Stores college events. A few sample rows are added at the end.
--
-- UPDATED by the Event Management Module (manage_events.php):
--   * category   -> shown in the public Events page filter
--   * poster_path-> nullable relative path of the uploaded poster image,
--                  e.g. 'uploads/event_posters/8f3a....jpg' (NULL = no poster)
-- Databases created with the OLDER version of this file can add both
-- columns with the migration statements in section 6 below.
-- ---------------------------------------------------------------------------
CREATE TABLE events (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title       VARCHAR(150) NOT NULL,
    description TEXT         NOT NULL,
    category    VARCHAR(50)  NOT NULL DEFAULT 'Academic',
    event_date  DATE         NOT NULL,
    location    VARCHAR(150) NOT NULL,
    poster_path VARCHAR(255) DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. TABLE: registrations
-- One row = one student registering for one event.
-- student_id points to students.id  (foreign key)
-- event_id  points to events.id     (foreign key)
-- The UNIQUE key stops the same student registering twice for the same event.
-- ---------------------------------------------------------------------------
CREATE TABLE registrations (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id   INT UNSIGNED NOT NULL,
    event_id     INT UNSIGNED NOT NULL,
    registered_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- One registration per student per event only
    UNIQUE KEY uq_registrations_student_event (student_id, event_id),

    -- If a student is deleted, remove their event registrations too
    CONSTRAINT fk_registrations_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE,

    -- If an event is deleted, remove registrations for that event too
    CONSTRAINT fk_registrations_event
        FOREIGN KEY (event_id) REFERENCES events(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. SAMPLE EVENTS
-- Same events that already appear in events.json, now with their categories
-- (no student accounts are inserted here - students are added through the
-- registration form).
-- ---------------------------------------------------------------------------
INSERT INTO events (title, description, category, event_date, location) VALUES
('Web Development Workshop', 'Hands-on training session covering modern responsive web design using HTML5, CSS Grid, and Flexbox.', 'Workshop', '2026-09-28', 'Computer Lab'),
('Code Challenge 2026', 'Annual department-level competitive programming and algorithm problem-solving contest.', 'Competition', '2026-10-02', 'Programming Lab'),
('Technical Quiz Competition', 'Interactive technical quiz testing core concepts of computer networks, data structures, and database systems.', 'Academic', '2026-10-05', 'Seminar Hall'),
('Annual Project Exhibition', 'Showcase of innovative software and hardware projects created by final-year students.', 'Academic', '2026-10-10', 'Main Auditorium'),
('Student Hackathon 2026', '24-hour hackathon challenging students to build working prototypes for smart campus solutions.', 'Competition', '2026-10-15', 'Innovation Lab'),
('AI & Machine Learning Seminar', 'Guest lecture by industry experts exploring artificial intelligence applications and career paths.', 'Seminar', '2026-10-20', 'Seminar Hall'),
('Cyber Security Workshop', 'Practical session on web security, ethical hacking fundamentals, and preventing common cyber threats.', 'Workshop', '2026-10-25', 'Computer Lab'),
('Cultural Festival Inauguration', 'Grand opening ceremony of the annual inter-college cultural festival with music and performances.', 'Cultural', '2026-11-01', 'Main Auditorium'),
('Cloud Computing Bootcamp', 'Intensive bootcamp introducing cloud architecture, containerization, and virtual servers.', 'Workshop', '2026-11-08', 'Innovation Lab'),
('Robotics & IoT Showcase', 'Live demonstration of student-built IoT devices, autonomous robots, and drone technology.', 'Technical', '2026-11-15', 'Central Ground');

-- ---------------------------------------------------------------------------
-- 6. MIGRATION FOR EXISTING DATABASES (Event Management Module)
-- Run this section ONCE, and ONLY if your events table still has the OLD
-- structure (no 'category' and no 'poster_path' column).
--
-- How to check in phpMyAdmin:
--     SELECT * FROM events;   -> if it errors with "Unknown column
--     'category'" you need this migration.
--
-- To run it: uncomment everything between BEGIN MIGRATION and END
-- MIGRATION and import it once. Do NOT run it on a database that was
-- created from the updated CREATE TABLE above (the columns already exist).
--
-- BEGIN MIGRATION ---------------------------------------------------------
-- ALTER TABLE events
--     ADD COLUMN category   VARCHAR(50)  NOT NULL DEFAULT 'Academic' AFTER description,
--     ADD COLUMN poster_path VARCHAR(255) NULL AFTER location;
--
-- UPDATE events SET category = 'Workshop'    WHERE id = 1;
-- UPDATE events SET category = 'Competition' WHERE id = 2;
-- UPDATE events SET category = 'Academic'    WHERE id = 3;
-- UPDATE events SET category = 'Academic'    WHERE id = 4;
--
-- INSERT INTO events (title, description, category, event_date, location) VALUES
-- ('Student Hackathon 2026', '24-hour hackathon challenging students to build working prototypes for smart campus solutions.', 'Competition', '2026-10-15', 'Innovation Lab'),
-- ('AI & Machine Learning Seminar', 'Guest lecture by industry experts exploring artificial intelligence applications and career paths.', 'Seminar', '2026-10-20', 'Seminar Hall'),
-- ('Cyber Security Workshop', 'Practical session on web security, ethical hacking fundamentals, and preventing common cyber threats.', 'Workshop', '2026-10-25', 'Computer Lab'),
-- ('Cultural Festival Inauguration', 'Grand opening ceremony of the annual inter-college cultural festival with music and performances.', 'Cultural', '2026-11-01', 'Main Auditorium'),
-- ('Cloud Computing Bootcamp', 'Intensive bootcamp introducing cloud architecture, containerization, and virtual servers.', 'Workshop', '2026-11-08', 'Innovation Lab'),
-- ('Robotics & IoT Showcase', 'Live demonstration of student-built IoT devices, autonomous robots, and drone technology.', 'Technical', '2026-11-15', 'Central Ground');
-- END MIGRATION -----------------------------------------------------------
