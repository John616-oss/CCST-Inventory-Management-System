-- Verified empty in ccst_inventory on 2026-11-11 before applying.
-- Courses and subjects are removed separately by remove_unused_course_subject_tables.sql.
DROP TABLE IF EXISTS attendance;
DROP TABLE IF EXISTS enrollments;
DROP TABLE IF EXISTS teacher_subjects;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS teachers;
DROP TABLE IF EXISTS sections;
