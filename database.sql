-- ============================================================
--  LSGS — Eswatini College of Technology
--  Import this file ONCE in phpMyAdmin before running the app
-- ============================================================

CREATE DATABASE IF NOT EXISTS lsgs_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE lsgs_db;

-- Admin accounts
CREATE TABLE IF NOT EXISTS admin (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL DEFAULT 'Administrator',
  email      VARCHAR(150) UNIQUE NOT NULL,
  password   VARCHAR(255) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Students
CREATE TABLE IF NOT EXISTS students (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  first_name  VARCHAR(80)  NOT NULL,
  last_name   VARCHAR(80)  NOT NULL,
  student_num VARCHAR(30)  UNIQUE,
  email       VARCHAR(150) UNIQUE NOT NULL,
  programme   VARCHAR(150),
  year        VARCHAR(30),
  password    VARCHAR(255) NOT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Degree programmes
CREATE TABLE IF NOT EXISTS programmes (
  id   INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) UNIQUE NOT NULL
);

-- Subjects
CREATE TABLE IF NOT EXISTS subjects (
  id   INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) UNIQUE NOT NULL
);

-- Study groups
CREATE TABLE IF NOT EXISTS study_groups (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(150) NOT NULL,
  subject      VARCHAR(150),
  skill_level  VARCHAR(30)  DEFAULT 'Intermediate',
  days         VARCHAR(100),
  meeting_time VARCHAR(20),
  location     VARCHAR(150),
  health       INT          DEFAULT 100,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Group members (many-to-many)
CREATE TABLE IF NOT EXISTS group_members (
  group_id   INT NOT NULL,
  student_id INT NOT NULL,
  joined_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id, student_id),
  FOREIGN KEY (group_id)   REFERENCES study_groups(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id)     ON DELETE CASCADE
);

-- Study sessions
CREATE TABLE IF NOT EXISTS sessions (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  title        VARCHAR(200) NOT NULL,
  group_id     INT,
  day          VARCHAR(20),
  session_time VARCHAR(20),
  location     VARCHAR(150),
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (group_id) REFERENCES study_groups(id) ON DELETE CASCADE
);

-- Shared resources / files
CREATE TABLE IF NOT EXISTS resources (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(200) NOT NULL,
  group_id   INT,
  student_id INT,
  category   VARCHAR(50)  DEFAULT 'Notes',
  file_type  VARCHAR(10)  DEFAULT 'pdf',
  size_label VARCHAR(20),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (group_id)   REFERENCES study_groups(id) ON DELETE SET NULL,
  FOREIGN KEY (student_id) REFERENCES students(id)     ON DELETE SET NULL
);

-- Attendance log
CREATE TABLE IF NOT EXISTS attendance (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  session_id INT NOT NULL,
  status     ENUM('Present','Absent') DEFAULT 'Present',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_att (student_id, session_id),
  FOREIGN KEY (student_id) REFERENCES students(id)  ON DELETE CASCADE,
  FOREIGN KEY (session_id) REFERENCES sessions(id)  ON DELETE CASCADE
);

-- Personal reminders
CREATE TABLE IF NOT EXISTS reminders (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  title      VARCHAR(200) NOT NULL,
  remind_at  DATETIME,
  group_name VARCHAR(150),
  location   VARCHAR(150),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- ============================================================
--  SEED DATA
-- ============================================================

-- Default admin  (password: admin1234)
INSERT IGNORE INTO admin (name, email, password) VALUES
('Administrator', 'admin@ecot.ac.sz',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- Subjects
INSERT IGNORE INTO subjects (name) VALUES
('Mathematics'), ('Computer Science'), ('Physics'), ('Chemistry'),
('Biology'), ('Engineering'), ('Statistics'),
('Information Technology'), ('Electronics');

-- Programmes
INSERT IGNORE INTO programmes (name) VALUES
('Diploma in Information Technology'),
('Diploma in Electronics Engineering'),
('Diploma in Civil Engineering'),
('Diploma in Electrical Engineering'),
('Bachelor of Computer Science'),
('Bachelor of Information Systems'),
('Certificate in ICT');

-- Demo study groups
INSERT IGNORE INTO study_groups (id, name, subject, skill_level, days, meeting_time, location, health) VALUES
(1, 'MATH301 Crew',     'Mathematics',      'Intermediate', 'Mon & Wed', '09:00', 'Room 4B', 82),
(2, 'CS404 Night Owls', 'Computer Science', 'Intermediate', 'Tue & Thu', '20:00', 'Online',  91),
(3, 'PHY202 Squad',     'Physics',          'Intermediate', 'Fri',       '16:00', 'Lab 2',   68);

-- Demo sessions
INSERT IGNORE INTO sessions (id, title, group_id, day, session_time, location) VALUES
(1, 'Linear Algebra Drill',      1, 'Mon', '09:00', 'Room 4B'),
(2, 'OS Concepts Review',        2, 'Mon', '13:30', 'Online'),
(3, 'Process Scheduling',        2, 'Tue', '20:00', 'Online'),
(4, 'Eigenvectors Practice',     1, 'Wed', '09:00', 'Room 4B'),
(5, 'Virtual Memory Deep-dive',  2, 'Thu', '20:00', 'Online'),
(6, 'Past Papers — Physics',     3, 'Fri', '16:00', 'Lab 2');
