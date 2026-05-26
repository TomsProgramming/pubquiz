-- PubQuiz Database Schema
-- Importeer dit bestand in je MySQL database

-- Create database
CREATE DATABASE IF NOT EXISTS pubquiz;
USE pubquiz;

-- Teams table
CREATE TABLE IF NOT EXISTS teams (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    score INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Questions table
CREATE TABLE IF NOT EXISTS questions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    week INT NOT NULL,
    question_number INT NOT NULL,
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    category VARCHAR(50) DEFAULT 'Algemeen',
    points INT DEFAULT 1,
    question_type ENUM('text','multiple_choice','video','audio','photo') NOT NULL DEFAULT 'text',
    display_order INT NOT NULL DEFAULT 0,
    media_path VARCHAR(255) NULL,
    video_source ENUM('upload','youtube') NULL,
    video_youtube_id VARCHAR(32) NULL,
    video_start INT NULL,
    video_end INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_week_number (week, question_number),
    INDEX idx_week_order (week, display_order)
);

-- Answer options table (voor multiple choice vragen)
CREATE TABLE IF NOT EXISTS answer_options (
    id INT PRIMARY KEY AUTO_INCREMENT,
    question_id INT NOT NULL,
    option_text TEXT NULL,
    option_image_path VARCHAR(255) NULL,
    is_correct BOOLEAN DEFAULT FALSE,
    display_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);

-- Team week scores table (punten per team per week)
CREATE TABLE IF NOT EXISTS team_week_scores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    team_id INT NOT NULL,
    week INT NOT NULL,
    points INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    UNIQUE KEY unique_team_week (team_id, week),
    INDEX idx_week (week)
);

-- Answers table (tracking van antwoorden per team)
CREATE TABLE IF NOT EXISTS answers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    team_id INT NOT NULL,
    question_id INT NOT NULL,
    answer TEXT,
    is_correct BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_team_question (team_id, question_id)
);

-- Insert sample data
INSERT INTO teams (name, score) VALUES ('Team A', 0);
INSERT INTO teams (name, score) VALUES ('Team B', 0);
INSERT INTO teams (name, score) VALUES ('Team C', 0);
