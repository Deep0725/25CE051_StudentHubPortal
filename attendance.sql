-- ==========================================================================
-- StudentHub - Attendance Table (Attendance Management Module)
-- File: attendance.sql
--
-- Safe to run on an EXISTING database:
--   * CREATE TABLE IF NOT EXISTS never touches data that already exists
--   * nothing is deleted, dropped or overwritten
--
-- How to import (phpMyAdmin):
--   1. Select the  studenthub  database on the left
--   2. Click the  Import  tab at the top
--   3. Choose this file (attendance.sql) and press  Go
-- ==========================================================================

USE studenthub;

-- ---------------------------------------------------------------------------
-- TABLE: attendance
-- One row = one attendance record for one student, on one date, for one
-- subject.
--
-- Column notes:
--   * student_id  -> references students.id (the PRIMARY KEY of students),
--                    exactly the same convention as registrations.student_id.
--                    The students table has no 'department' column - the
--                    department is the 'course' column and the academic year
--                    is the 'year' column. The attendance table does NOT
--                    duplicate them: the admin page always reads the
--                    student's course/year live from the students table.
--   * status      -> only 'Present' or 'Absent' are allowed (ENUM).
--   * UNIQUE key  -> prevents duplicate records for the same student,
--                    date and subject. It also lets manage_attendance.php
--                    UPDATE an existing record by re-saving the sheet with
--                    INSERT ... ON DUPLICATE KEY UPDATE.
--   * ON DELETE CASCADE -> if a student account is deleted, their attendance
--                    records are removed with it (same as registrations).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id  INT UNSIGNED NOT NULL,
    attend_date DATE         NOT NULL,
    subject     VARCHAR(50)  NOT NULL,
    status      ENUM('Present','Absent') NOT NULL,
    marked_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    -- One record per student per date per subject only
    UNIQUE KEY uq_attendance_student_date_subject (student_id, attend_date, subject),

    CONSTRAINT fk_attendance_student
        FOREIGN KEY (student_id) REFERENCES students(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
