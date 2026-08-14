<?php
/**
 * Student Header Component
 * Unified header for all student pages with user info and logout
 */

require_once __DIR__ . '/../classes/utilities.php';
Utilities::validateSession('student');

// Get student information
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/user.php';

$database = new Database();
$db = $database->getConnection();

$user = new User($db);
$user->id = $_SESSION['user_id'];
$user->readOne();

// Get class name
$class_name = 'غير مسند لفصل';
if ($user->class_id) {
    $query = "SELECT name FROM classes WHERE id = ?";
    $stmt_class = $db->prepare($query);
    $stmt_class->bindParam(1, $user->class_id);
    $stmt_class->execute();
    $class_row = $stmt_class->fetch(PDO::FETCH_ASSOC);
    if ($class_row) {
        $class_name = $class_row['name'];
    }
}

$student_name = $_SESSION['name'];
$student_username = $user->username;
?>

<!-- Student Info Bar - Unified Design -->
<div class="student-info-bar">
    <div class="student-info-container">
        <!-- Welcome Section -->
        <div class="student-welcome">
            <div class="student-avatar">
                <i class="fas fa-user-graduate"></i>
            </div>
            <div class="student-details-compact">
                <div class="student-name-primary">
                    مرحباً، <strong><?php echo htmlspecialchars($student_name); ?></strong>
                </div>
                <div class="student-meta">
                    <span class="meta-item">
                        <i class="fas fa-user"></i>
                        <?php echo htmlspecialchars($student_username); ?>
                    </span>
                    <span class="meta-separator">•</span>
                    <span class="meta-item">
                        <i class="fas fa-school"></i>
                        <?php echo htmlspecialchars($class_name); ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Notification & Logout Buttons -->
        <div class="student-actions">
            <a href="#" id="pushNotifBtn" class="logout-btn-unified" title="تفعيل الإشعارات" style="background: rgba(255,255,255,0.15); color: #fff; margin-left: 8px;">
                <i class="fas fa-bell"></i>
            </a>
            <a href="../logout.php" class="logout-btn-unified" title="تسجيل الخروج">
                <i class="fas fa-sign-out-alt"></i>
                <span class="logout-text">تسجيل الخروج</span>
            </a>
        </div>
    </div>
</div>

<!-- Unified Styles for Student Info Bar -->
<style>
    .student-info-bar {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 1rem 0;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        margin-bottom: 1.5rem;
        border-radius: 0 0 15px 15px;
    }
    
    .student-info-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
    }
    
    .student-welcome {
        display: flex;
        align-items: center;
        gap: 1rem;
        flex: 1;
    }
    
    .student-avatar {
        width: 50px;
        height: 50px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        border: 2px solid rgba(255, 255, 255, 0.3);
        flex-shrink: 0;
    }
    
    .student-details-compact {
        display: flex;
        flex-direction: column;
        gap: 0.3rem;
    }
    
    .student-name-primary {
        font-size: 1.1rem;
        font-weight: 400;
        line-height: 1.3;
    }
    
    .student-name-primary strong {
        font-weight: 700;
    }
    
    .student-meta {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.85rem;
        opacity: 0.95;
        flex-wrap: wrap;
    }
    
    .meta-item {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
    }
    
    .meta-item i {
        font-size: 0.75rem;
        opacity: 0.8;
    }
    
    .meta-separator {
        opacity: 0.6;
        font-size: 0.7rem;
    }
    
    .student-actions {
        flex-shrink: 0;
    }
    
    .logout-btn-unified {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: rgba(255, 255, 255, 0.2);
        color: white;
        padding: 0.6rem 1.2rem;
        border-radius: 25px;
        text-decoration: none;
        font-weight: 500;
        transition: all 0.3s ease;
        border: 2px solid rgba(255, 255, 255, 0.3);
        backdrop-filter: blur(10px);
    }
    
    .logout-btn-unified:hover {
        background: rgba(255, 255, 255, 0.3);
        color: white;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        border-color: rgba(255, 255, 255, 0.5);
    }
    
    .logout-btn-unified i {
        font-size: 1rem;
    }
    
    /* Responsive Design */
    @media (max-width: 768px) {
        .student-info-bar {
            padding: 0.8rem 0;
            border-radius: 0;
        }
        
        .student-info-container {
            flex-direction: column;
            gap: 0.8rem;
            padding: 0 0.8rem;
        }
        
        .student-welcome {
            width: 100%;
            justify-content: center;
            text-align: center;
        }
        
        .student-avatar {
            width: 45px;
            height: 45px;
            font-size: 1.3rem;
        }
        
        .student-details-compact {
            align-items: flex-start;
            flex: 1;
        }
        
        .student-name-primary {
            font-size: 1rem;
        }
        
        .student-meta {
            font-size: 0.8rem;
            justify-content: flex-start;
        }
        
        .student-actions {
            width: 100%;
            display: flex;
            justify-content: center;
        }
        
        .logout-btn-unified {
            width: 100%;
            justify-content: center;
            padding: 0.7rem 1rem;
        }
        
        .logout-text {
            display: inline;
        }
    }
    
    @media (max-width: 480px) {
        .student-name-primary {
            font-size: 0.95rem;
        }
        
        .student-meta {
            font-size: 0.75rem;
            gap: 0.4rem;
        }
        
        .meta-item {
            gap: 0.25rem;
        }
        
        .logout-btn-unified {
            padding: 0.6rem 0.9rem;
            font-size: 0.9rem;
        }
    }
    
    /* Dark mode support */
    @media (prefers-color-scheme: dark) {
        .student-info-bar {
            background: linear-gradient(135deg, #1e3a8a 0%, #4c1d95 100%);
        }
    }
</style>
