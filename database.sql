CREATE DATABASE IF NOT EXISTS ccst_student CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ccst_student;
CREATE TABLE users(
 id INT AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(150) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE,
 password VARCHAR(255) NOT NULL, role ENUM('registrar','teacher','student') NOT NULL DEFAULT 'student',
 is_verified TINYINT(1) NOT NULL DEFAULT 0, status ENUM('active','inactive') DEFAULT 'active',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE email_verifications(
 id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,used_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE password_resets(
 id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,token_hash VARCHAR(255) NOT NULL UNIQUE,
 expires_at DATETIME NOT NULL,used_at DATETIME NULL,attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE courses(
 id INT AUTO_INCREMENT PRIMARY KEY,code VARCHAR(20) NOT NULL UNIQUE,name VARCHAR(150) NOT NULL,description TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE sections(
 id INT AUTO_INCREMENT PRIMARY KEY,course_id INT NOT NULL,year_level TINYINT NOT NULL,section_name VARCHAR(30) NOT NULL,
 UNIQUE(course_id,year_level,section_name),FOREIGN KEY(course_id) REFERENCES courses(id)
) ENGINE=InnoDB;
CREATE TABLE teachers(
 id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NULL UNIQUE,employee_no VARCHAR(40) NOT NULL UNIQUE,first_name VARCHAR(80) NOT NULL,last_name VARCHAR(80) NOT NULL,email VARCHAR(190) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE students(
 id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NULL UNIQUE,student_code VARCHAR(40) NOT NULL UNIQUE,first_name VARCHAR(80) NOT NULL,middle_name VARCHAR(80) NULL,last_name VARCHAR(80) NOT NULL,gender VARCHAR(20) NULL,birth_date DATE NULL,email VARCHAR(190) NULL,phone VARCHAR(30) NULL,course_id INT NULL,section_id INT NULL,year_level TINYINT NULL,photo VARCHAR(255) NULL,status ENUM('active','inactive','graduated') DEFAULT 'active',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,FOREIGN KEY(course_id) REFERENCES courses(id) ON DELETE SET NULL,FOREIGN KEY(section_id) REFERENCES sections(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE subjects(
 id INT AUTO_INCREMENT PRIMARY KEY,subject_code VARCHAR(30) NOT NULL UNIQUE,subject_name VARCHAR(150) NOT NULL,units TINYINT DEFAULT 3,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE teacher_subjects(
 id INT AUTO_INCREMENT PRIMARY KEY,teacher_id INT NOT NULL,subject_id INT NOT NULL,section_id INT NOT NULL,schedule VARCHAR(120) NULL,
 UNIQUE(teacher_id,subject_id,section_id),FOREIGN KEY(teacher_id) REFERENCES teachers(id),FOREIGN KEY(subject_id) REFERENCES subjects(id),FOREIGN KEY(section_id) REFERENCES sections(id)
) ENGINE=InnoDB;
CREATE TABLE enrollments(
 id INT AUTO_INCREMENT PRIMARY KEY,student_id INT NOT NULL,section_id INT NOT NULL,school_year VARCHAR(20) NOT NULL,semester VARCHAR(30) NOT NULL,status ENUM('pending','approved','rejected') DEFAULT 'approved',enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(student_id,school_year,semester),FOREIGN KEY(student_id) REFERENCES students(id),FOREIGN KEY(section_id) REFERENCES sections(id)
) ENGINE=InnoDB;
CREATE TABLE attendance(
 id BIGINT AUTO_INCREMENT PRIMARY KEY,student_id INT NOT NULL,teacher_id INT NULL,subject_id INT NULL,section_id INT NULL,attendance_date DATE NOT NULL,time_in TIME NOT NULL,status ENUM('present','late','absent') DEFAULT 'present',remarks VARCHAR(255) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(student_id,subject_id,attendance_date),FOREIGN KEY(student_id) REFERENCES students(id),FOREIGN KEY(teacher_id) REFERENCES teachers(id) ON DELETE SET NULL,FOREIGN KEY(subject_id) REFERENCES subjects(id) ON DELETE SET NULL,FOREIGN KEY(section_id) REFERENCES sections(id) ON DELETE SET NULL,
 INDEX idx_attendance_date(attendance_date),INDEX idx_attendance_student(student_id)
) ENGINE=InnoDB;
CREATE TABLE activity_logs(
 id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id INT NULL,action VARCHAR(100) NOT NULL,details TEXT NULL,ip_address VARCHAR(45) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,INDEX idx_log_date(created_at)
) ENGINE=InnoDB;
INSERT INTO courses(code,name) VALUES('BSIT','Bachelor of Science in Information Technology'),('BSCS','Bachelor of Science in Computer Science'),('BSA','Bachelor of Science in Accountancy');
INSERT INTO subjects(subject_code,subject_name,units) VALUES('IT101','Introduction to IT',3),('IT102','Computer Programming',3),('IT201','Web Systems',3);
