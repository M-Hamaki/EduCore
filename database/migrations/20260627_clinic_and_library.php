<?php

return static function (PDO $db): void {
    $tableExists = static function (string $table) use ($db): bool {
        $stmt = $db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    };

    if (!$tableExists('student_clinic_visits')) {
        $db->exec("CREATE TABLE student_clinic_visits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            visit_at DATETIME NOT NULL,
            health_condition TEXT NULL,
            treatment_taken TEXT NULL,
            action_taken TEXT NULL,
            notes TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_clinic_student (student_id),
            KEY idx_clinic_visit_at (visit_at),
            CONSTRAINT fk_clinic_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('library_books')) {
        $db->exec("CREATE TABLE library_books (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            author VARCHAR(255) NULL,
            category VARCHAR(150) NULL,
            isbn VARCHAR(80) NULL,
            copies_total INT NOT NULL DEFAULT 1,
            copies_available INT NOT NULL DEFAULT 1,
            location VARCHAR(150) NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_library_books_title (title),
            KEY idx_library_books_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('library_loans')) {
        $db->exec("CREATE TABLE library_loans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            book_id INT NOT NULL,
            student_id INT NOT NULL,
            borrowed_at DATE NOT NULL,
            due_at DATE NULL,
            returned_at DATE NULL,
            status ENUM('borrowed','returned','late') NOT NULL DEFAULT 'borrowed',
            notes TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_library_loans_book (book_id),
            KEY idx_library_loans_student (student_id),
            KEY idx_library_loans_status (status),
            CONSTRAINT fk_library_loan_book FOREIGN KEY (book_id) REFERENCES library_books(id) ON DELETE CASCADE,
            CONSTRAINT fk_library_loan_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!$tableExists('library_fines')) {
        $db->exec("CREATE TABLE library_fines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            loan_id INT NULL,
            student_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            reason VARCHAR(255) NULL,
            paid TINYINT(1) NOT NULL DEFAULT 0,
            paid_at DATE NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_library_fines_student (student_id),
            KEY idx_library_fines_paid (paid),
            CONSTRAINT fk_library_fine_loan FOREIGN KEY (loan_id) REFERENCES library_loans(id) ON DELETE SET NULL,
            CONSTRAINT fk_library_fine_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    echo "Clinic and library tables are ready.\n";
};
