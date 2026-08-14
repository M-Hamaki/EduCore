<?php
// تحميل إعدادات الجلسة
require_once '../includes/session_config.php';
require_once '../classes/utilities.php';
require_once '../includes/notifications_helper.php';

// التحقق من تسجيل الدخول - السماح للمعلمين والمشرفين في وضع المعلم
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}
if ($_SESSION['role'] !== 'teacher' && !(Utilities::isSupervisor() && ($_SESSION['active_mode'] ?? '') === 'teacher')) {
    header('Location: ../index.php');
    exit;
}

// الحصول على اسم المعلم
$teacher_name = isset($_SESSION['name']) ? $_SESSION['name'] : 'المعلم';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>البوابة الرئيسية - DMLS</title>

    <!-- Prevent caching issues -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    
    <!-- Bootstrap RTL CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.rtl.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/student-header-fixes.css">
    <link rel="stylesheet" href="styles.css?v=1.3">
    
    <style>
        /* تعديل الـ body للتوافق مع المكون الموحد */
        body {
            padding-top: 0 !important;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        /* Dark Mode Support */
        body.dark-mode {
            background: linear-gradient(135deg, #1e3a8a 0%, #4c1d95 100%);
        }
        
        /* School Logo and Title Section */
        .portal-logo-section {
            text-align: center;
            padding: 1.25rem 1rem 0.25rem;
        }
        
        .portal-school-logo {
            max-width: 140px;
            height: auto;
            filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.3));
            animation: fadeInDown 0.8s ease-out;
            margin-bottom: 0.75rem;
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Portal Title - Below Logo, Outside Card */
        .portal-title-text {
            text-align: center;
            padding: 0 1rem 1rem;
        }
        
        .portal-main-title-text {
            font-size: 2rem;
            font-weight: 800;
            color: #1e293b;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin: 0 0 0.25rem 0;
            letter-spacing: 1px;
            filter: none; /* إبقاء الإيموجي بلونه الطبيعي */
        }
        
        .portal-subtitle-text {
            font-size: 1.15rem;
            color: #334155;
            font-weight: 500;
            margin: 0;
        }
        
        /* Dark Mode for Title Text */
        body.dark-mode .portal-main-title-text {
            color: #f1f5f9;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }
        
        body.dark-mode .portal-subtitle-text {
            color: #cbd5e1;
        }
        
        /* Top Fixed Buttons Container */
        .top-fixed-buttons {
            position: fixed;
            top: 20px;
            left: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 1000;
        }

        /* Logout Button - Top Left */
        .logout-button-top {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(231, 76, 60, 0.4);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .logout-button-top:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(231, 76, 60, 0.5);
            color: white;
        }
        
        /* Dark Mode for Logout Button */
        body.dark-mode .logout-button-top {
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.5);
        }
        
        body.dark-mode .logout-button-top:hover {
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.6);
        }

        /* Theme Toggle Button */
        .theme-toggle {
            position: fixed;
            bottom: 20px;
            left: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: none;
            background: #ffffff;
            color: #667eea;
            font-size: 1.3rem;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        body.dark-mode .theme-toggle {
            background: #334155;
            color: #fbbf24;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
        }

        .theme-toggle:hover {
            transform: scale(1.1);
        }

        /* Supervisor Switch Button */
        .supervisor-switch-btn {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.4);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }
        .supervisor-switch-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.5);
            color: white;
        }
        body.dark-mode .supervisor-switch-btn {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            box-shadow: 0 4px 15px rgba(124, 58, 237, 0.5);
        }
        
        /* Footer Styling */
        .portal-footer {
            background: #1e293b;
            color: white;
            padding: 1.5rem 0 1.5rem 0;
            margin-top: 3rem;
            border-top: 3px solid rgba(102, 126, 234, 0.3);
        }
        
        .portal-footer .container {
            padding-bottom: 0 !important;
            margin-bottom: 0 !important;
        }
        
        body.dark-mode .portal-footer {
            background: #0f172a;
            border-top: 3px solid rgba(100, 116, 139, 0.3);
        }
        
        .portal-footer p {
            margin: 0 0 1rem 0;
            color: rgba(255, 255, 255, 0.9);
            font-size: 1rem;
        }
        
        body.dark-mode .portal-footer p {
            color: #cbd5e1;
        }
        
        /* Social Media Icons in Footer */
        .social-media-footer {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 1.5rem;
            margin-bottom: 0.5rem;
        }
        
        .social-footer-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            font-size: 1.3rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        
        .social-footer-icon.facebook {
            background: linear-gradient(135deg, #1877f2 0%, #0c63d4 100%);
        }
        
        .social-footer-icon.whatsapp {
            background: linear-gradient(135deg, #25d366 0%, #128c7e 100%);
        }
        
        .social-footer-icon.instagram {
            background: linear-gradient(135deg, #e1306c 0%, #c13584 50%, #833ab4 100%);
        }
        
        .social-footer-icon:hover {
            transform: translateY(-4px) scale(1.1);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.4);
        }
        
        .social-footer-icon.facebook:hover {
            box-shadow: 0 8px 20px rgba(24, 119, 242, 0.6);
        }
        
        .social-footer-icon.whatsapp:hover {
            box-shadow: 0 8px 20px rgba(37, 211, 102, 0.6);
        }
        
        .social-footer-icon.instagram:hover {
            box-shadow: 0 8px 20px rgba(225, 48, 108, 0.6);
        }
        
        /* Dark Mode for Social Icons */
        body.dark-mode .social-footer-icon {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
        }
        
        body.dark-mode .social-footer-icon:hover {
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.6);
        }
        
        /* Main Container with padding for logout button */
        body {
            padding-top: 0 !important;
        }
        
        /* Welcome Card - Sleek, low-profile and compact */
        .portal-welcome-card {
            background: white;
            color: #1e293b;
            padding: 1.25rem 1.5rem;
            border-radius: 14px;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 
                0 4px 15px rgba(0, 0, 0, 0.08),
                0 2px 6px rgba(0, 0, 0, 0.05);
            text-align: center;
            margin: 0 auto 1.25rem;
            max-width: 860px;
            position: relative;
            overflow: hidden;
            border: 1.5px solid rgba(102, 126, 234, 0.25);
        }
        
        .portal-welcome-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transform: scaleX(0);
            transition: transform 0.3s ease;
            transform-origin: left;
        }
        
        .portal-welcome-card:hover::before {
            transform: scaleX(1);
        }
        
        /* Dark Mode for Welcome Card */
        body.dark-mode .portal-welcome-card {
            background: #1e293b;
            color: #f1f5f9;
            border: 1px solid #334155;
            box-shadow: 
                0 4px 15px rgba(0, 0, 0, 0.3),
                0 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        .portal-welcome-title {
            font-size: 1.35rem;
            color: #1e293b;
            margin: 0 0 0.25rem 0;
            font-weight: 700;
            transition: all 0.3s ease;
        }
        
        .portal-welcome-icon {
            margin-left: 0.4rem;
            font-size: 1.35rem;
            color: #FFC107;
            animation: wave 2s ease-in-out infinite;
            transition: all 0.3s ease;
            filter: drop-shadow(0 2px 4px rgba(255, 193, 7, 0.3));
        }
        
        .portal-welcome-card:hover .portal-welcome-icon {
            transform: scale(1.1);
            color: #FFB300;
        }
        
        @keyframes wave {
            0%, 100% { transform: rotate(0deg); }
            25% { transform: rotate(20deg); }
            75% { transform: rotate(-20deg); }
        }
        
        .portal-student-name {
            font-size: 1.15rem;
            font-weight: 600;
            color: #1e293b;
            margin: 0 0 0.85rem 0;
            padding-bottom: 0.65rem;
            border-bottom: 1px solid rgba(102, 126, 234, 0.15);
        }
        
        /* Dark Mode for Welcome Card Text */
        body.dark-mode .portal-welcome-title {
            color: #f1f5f9;
        }
        
        body.dark-mode .portal-welcome-icon {
            color: #FFC107;
            filter: drop-shadow(0 2px 6px rgba(255, 193, 7, 0.4));
        }
        
        body.dark-mode .portal-welcome-card:hover .portal-welcome-icon {
            color: #FFD54F;
        }
        
        body.dark-mode .portal-student-name {
            color: #f1f5f9;
            border-bottom-color: rgba(96, 165, 250, 0.25);
        }
        
        .student-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.85rem;
            margin-top: 0;
        }
        
        .student-info-item {
            text-align: center;
            padding: 0.75rem 0.65rem;
            background: rgba(102, 126, 234, 0.04);
            border-radius: 10px;
            border: 1px solid rgba(102, 126, 234, 0.08);
            transition: none;
        }
        
        .student-info-item > i {
            font-size: 1.35rem;
            margin-bottom: 0.35rem;
            color: #667eea;
            transition: none;
        }
        
        .student-info-label {
            font-size: 0.82rem;
            color: #64748b;
            margin-bottom: 0.3rem;
            font-weight: 500;
        }
        
        .student-info-value {
            font-size: 1.05rem;
            font-weight: 600;
            color: #1e293b;
            word-break: break-all;
        }
        
        /* Classes badges display */
        .classes-badges-container {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            justify-content: center;
            align-items: center;
            margin-top: 4px;
            max-height: 52px;
            overflow: hidden;
            transition: max-height 0.3s ease;
            width: 100%;
        }
        .classes-badges-container.classes-expanded {
            max-height: 140px;
            overflow-y: auto;
            padding: 2px 4px;
        }
        .classes-badges-container::-webkit-scrollbar {
            width: 5px;
        }
        .classes-badges-container::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.04);
            border-radius: 4px;
        }
        .classes-badges-container::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        .classes-badges-container::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        body.dark-mode .classes-badges-container::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
        }
        body.dark-mode .classes-badges-container::-webkit-scrollbar-thumb {
            background: #475569;
        }
        .class-badge {
            display: inline-block;
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #e2e8f0;
            padding: 0.2rem 0.55rem;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            white-space: nowrap;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
            user-select: text;
        }
        .class-badge-hidden {
            display: none;
        }
        .classes-expanded .class-badge-hidden {
            display: inline-block;
        }
        body.dark-mode .class-badge {
            background: rgba(148, 163, 184, 0.12);
            color: #cbd5e1;
            border-color: rgba(148, 163, 184, 0.2);
            box-shadow: none;
        }
        .class-toggle-btn {
            margin-top: 0.35rem;
        }

        .value-with-copy {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            flex-direction: column;
        }
        
        .value-text {
            font-size: 1.15rem;
            font-weight: 600;
            color: #1e293b;
            direction: ltr;
        }

        .portal-password-input {
            font-size: 1.15rem !important;
            font-weight: 600 !important;
            color: #1e293b !important;
            background: transparent !important;
            border: none !important;
            text-align: center !important;
            outline: none !important;
            width: 100% !important;
            max-width: 180px !important;
            padding: 0 !important;
            margin-bottom: 0.1rem !important;
            box-shadow: none !important;
            letter-spacing: 1px;
            direction: ltr;
        }
        
        body.dark-mode .portal-password-input {
            color: #f1f5f9 !important;
        }
        
        .portal-password-actions {
            display: flex;
            gap: 0.4rem;
            justify-content: center;
            align-items: center;
        }
        
        .copy-btn {
            background: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            padding: 0.28rem 0.65rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.78rem;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(37, 99, 235, 0.1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            font-weight: 600;
            line-height: 1;
            vertical-align: middle;
        }
        
        .copy-btn:hover {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(37, 99, 235, 0.25);
        }
        
        .copy-btn:active {
            transform: translateY(0);
        }
        
        .copy-btn.copied {
            background: #ecfdf5;
            color: #059669;
            border-color: #a7f3d0;
            box-shadow: 0 1px 3px rgba(5, 150, 105, 0.1);
        }
        
        .copy-btn.copied:hover {
            background: #059669;
            color: #ffffff;
            border-color: #059669;
        }
        
        .copy-btn i {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            font-size: 0.78rem !important;
            line-height: 1 !important;
            margin: 0 !important;
            padding: 0 !important;
            vertical-align: middle !important;
            color: inherit !important;
        }
        
        .copy-btn-text {
            display: inline-flex;
            align-items: center;
            font-size: 0.78rem;
            line-height: 1;
            margin: 0;
            padding: 0;
            color: inherit;
        }
        
        /* Dark Mode for Student Info */
        body.dark-mode .student-info-item {
            background: rgba(96, 165, 250, 0.1);
        }
        
        body.dark-mode .value-text {
            color: #f1f5f9;
        }
        
        body.dark-mode .copy-btn {
            background: rgba(59, 130, 246, 0.15);
            color: #93c5fd;
            border-color: rgba(96, 165, 250, 0.3);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
        }
        
        body.dark-mode .copy-btn:hover {
            background: #3b82f6;
            color: #ffffff;
            border-color: #3b82f6;
            box-shadow: 0 3px 8px rgba(59, 130, 246, 0.35);
        }
        
        body.dark-mode .copy-btn.copied {
            background: #10b981;
            box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);
        }
        
        body.dark-mode .copy-btn.copied:hover {
            background: #059669;
            box-shadow: 0 4px 10px rgba(5, 150, 105, 0.4);
        }
        
        body.dark-mode .student-info-item > i {
            color: #60a5fa;
        }
        
        body.dark-mode .student-info-label {
            color: #94a3b8;
        }
        
        body.dark-mode .student-info-value {
            color: #f1f5f9;
        }

        
        /* Adjust container */
        .container {
            max-width: 1200px;
            margin: 0 auto 1.25rem;
            padding: 0 1rem;
            position: relative;
            z-index: 10;
        }
        
        .nav-grid {
            margin-top: 0 !important;
        }
        
        /* Mobile Responsiveness */
        @media (max-width: 768px) {
            .portal-school-logo {
                max-width: 80px;
                margin-bottom: 0.25rem;
            }
            
            .portal-logo-section {
                padding: 0.5rem 1rem 0.15rem;
            }
            
            .portal-title-text {
                padding: 0 1rem 0.4rem;
            }
            
            .portal-main-title-text {
                font-size: 1.35rem;
                margin-bottom: 0.1rem;
            }
            
            .portal-subtitle-text {
                font-size: 0.9rem;
            }
            
            .top-fixed-buttons {
                top: 10px;
                left: 10px;
                gap: 6px;
            }
            
            .logout-button-top {
                padding: 6px 12px;
                font-size: 0.78rem;
                border-radius: 8px;
            }

            .supervisor-switch-btn {
                padding: 6px;
                border-radius: 8px;
                width: 32px;
                height: 32px;
                font-size: 0.78rem;
                justify-content: center;
            }
            .supervisor-switch-btn span {
                display: none;
            }
            
            .portal-welcome-card {
                margin: 0 auto 0.75rem;
                padding: 0.75rem 0.65rem;
                border-radius: 12px;
            }
            
            .portal-welcome-title {
                font-size: 1.1rem;
                margin-bottom: 0.15rem;
            }
            
            .portal-welcome-icon {
                font-size: 1.1rem;
            }
            
            .portal-student-name {
                font-size: 1rem;
                margin-bottom: 0.5rem;
                padding-bottom: 0.4rem;
            }
            
            .student-info-grid {
                grid-template-columns: 1fr 1fr;
                gap: 0.45rem;
            }
            
            .student-info-item {
                padding: 0.45rem 0.35rem;
                border-radius: 8px;
                min-width: 0;
            }
            
            .student-info-item.classes-info-item,
            .student-info-item:nth-child(3):last-child {
                grid-column: 1 / -1;
            }
            
            .student-info-item > i {
                font-size: 1.15rem;
                margin-bottom: 0.15rem;
            }
            
            .student-info-label {
                font-size: 0.72rem;
                margin-bottom: 0.15rem;
                color: #64748b;
                font-weight: 600;
            }
            
            .value-with-copy {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 0.25rem;
                width: 100%;
                box-sizing: border-box;
            }
            
            .classes-badges-container {
                display: flex;
                flex-wrap: wrap;
                gap: 4px;
                justify-content: center;
                margin-top: 0.2rem;
                width: 100%;
            }
            
            .class-badge {
                font-size: 0.72rem;
                padding: 2px 7px;
                border-radius: 5px;
            }
            
            .value-text {
                font-size: 0.9rem;
            }
            
            .portal-password-input {
                text-align: center !important;
                margin-bottom: 0 !important;
                font-size: 0.9rem !important;
                width: 100% !important;
                max-width: 110px !important;
            }
            
            .portal-password-actions {
                margin-top: 0.1rem !important;
                display: flex;
                gap: 0.25rem;
                justify-content: center;
            }
            
            .copy-btn {
                padding: 0.2rem 0.45rem;
                font-size: 0.7rem;
                gap: 0.25rem;
                border-radius: 6px;
                white-space: nowrap;
            }
            
            .copy-btn i {
                font-size: 0.7rem !important;
            }
            
            .copy-btn-text {
                font-size: 0.7rem;
            }
            
            .container {
                margin: 0 auto 0.75rem;
                padding: 0 0.5rem;
            }
            
            .nav-grid {
                grid-template-columns: 1fr;
                gap: 0.65rem;
            }
            
            .nav-button {
                padding: 1.1rem 0.85rem;
            }
        }
        
        @media (max-width: 480px) {
            .portal-school-logo {
                max-width: 75px;
            }
            
            .portal-main-title-text {
                font-size: 1.2rem;
            }
            
            .portal-subtitle-text {
                font-size: 0.82rem;
            }
            
            .portal-welcome-card {
                padding: 0.6rem 0.5rem;
            }
            
            .student-info-grid {
                gap: 0.35rem;
            }
            
            .student-info-item {
                padding: 0.4rem 0.25rem;
            }
        }
    </style>
</head>

<body>
    <!-- Particles Background -->
    <div id="particles-js"></div>

    <!-- Top Fixed Buttons -->
    <div class="top-fixed-buttons">
        <a href="../logout.php" class="logout-button-top">
            <i class="fas fa-sign-out-alt"></i>
            <span>خروج</span>
        </a>
        <?php if (count((array)($_SESSION['available_roles'] ?? [])) > 1): ?>
        <a href="../select_role.php" class="supervisor-switch-btn" title="تبديل الدور النشط">
            <i class="fas fa-repeat"></i>
            <span>تبديل الدور</span>
        </a>
        <?php endif; ?>
        <?php if (Utilities::isSupervisor()): ?>
        <a href="../supervisor/select_mode.php?switch=supervisor&amp;csrf_token=<?php echo urlencode($_SESSION['csrf_token'] ?? ''); ?>" class="supervisor-switch-btn" title="التبديل إلى وضع المشرف">
            <i class="fas fa-user-shield"></i>
            <span>وضع المشرف</span>
        </a>
        <?php endif; ?>
        <button class="theme-toggle" id="themeToggle" title="تبديل الوضع المظلم/الفاتح">
            <i class="fas fa-moon"></i>
        </button>
    </div>

    <!-- School Logo -->
    <div class="portal-logo-section">
        <?php if (!function_exists('get_school_logo')) { require_once __DIR__ . '/../includes/template_helper.php'; } ?>
        <img src="<?php echo get_school_logo('../'); ?>" alt="شعار المدرسة" class="portal-school-logo">
    </div>

    <!-- Portal Title - Below Logo -->
    <div class="portal-title-text">
        <h1 class="portal-main-title-text">👨‍🏫 بوابة المعلم</h1>
    </div>

    <?php
    // Get full teacher details
    require_once '../config/database.php';
    require_once '../classes/user.php';
    
    $database = new Database();
    $db = $database->getConnection();
    
    $user = new User($db);
    $user->id = $_SESSION['user_id'];
    $user->readOne();
    
    // Get teacher's classes from user_class_access
    $teacher_classes = [];
    $teacher_id = $_SESSION['user_id'];
    
    try {
        $query = "SELECT DISTINCT c.id, c.name, c.display_order, c.grade_id,
                         g.grade_name, g.grade_order,
                         s.stage_name, s.stage_order
                  FROM classes c 
                  INNER JOIN user_class_access uca ON c.id = uca.class_id 
                  LEFT JOIN grades g ON c.grade_id = g.id
                  LEFT JOIN stages s ON g.stage_id = s.id
                  WHERE uca.user_id = ? 
                  ORDER BY COALESCE(s.stage_order, 999) ASC,
                           COALESCE(g.grade_order, g.id, 999) ASC,
                           COALESCE(c.display_order, 999) ASC,
                           c.name ASC";
        $stmt = $db->prepare($query);
        $stmt->execute([$teacher_id]);
        $teacher_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Sort classes using natural ordering (e.g. Birds 1, Birds 2, Birds 3...) within stage & grade
        if (!empty($teacher_classes)) {
            usort($teacher_classes, function($a, $b) {
                $stageA = $a['stage_order'] ?? 999;
                $stageB = $b['stage_order'] ?? 999;
                if ($stageA !== $stageB) {
                    return $stageA <=> $stageB;
                }
                $gradeA = $a['grade_order'] ?? 999;
                $gradeB = $b['grade_order'] ?? 999;
                if ($gradeA !== $gradeB) {
                    return $gradeA <=> $gradeB;
                }
                $orderA = $a['display_order'] ?? 999;
                $orderB = $b['display_order'] ?? 999;
                if ($orderA !== $orderB) {
                    return $orderA <=> $orderB;
                }
                return strnatcasecmp($a['name'] ?? '', $b['name'] ?? '');
            });
        }
    } catch (PDOException $e) {
        error_log("Error fetching teacher classes: " . $e->getMessage());
    }
    
    // Check for per-user service override first
    $user_service_override = null;
    try {
        $usq = $db->prepare("SELECT services, override_stage FROM user_services WHERE user_id = ? AND role = 'teacher'");
        $usq->execute([$teacher_id]);
        $user_service_override = $usq->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error checking user service override: " . $e->getMessage());
    }

    // Get teacher's allowed services from stage settings
    $allowed_teacher_services = [];
    try {
        $query = "SELECT DISTINCT s.teacher_services, s.teacher_new_badges 
                  FROM stages s 
                  INNER JOIN grades g ON s.id = g.stage_id 
                  INNER JOIN classes c ON g.id = c.grade_id 
                  INNER JOIN user_class_access uca ON c.id = uca.class_id 
                  WHERE uca.user_id = ? AND s.status = 'active'";
        $stmt = $db->prepare($query);
        $stmt->execute([$teacher_id]);
        $stage_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $teacher_new_badges = [];
        foreach ($stage_rows as $row) {
            if (!empty($row['teacher_services'])) {
                $services = json_decode($row['teacher_services'], true);
                if (is_array($services)) {
                    $allowed_teacher_services = array_merge($allowed_teacher_services, $services);
                }
            }
            if (!empty($row['teacher_new_badges'])) {
                $badges = json_decode($row['teacher_new_badges'], true);
                if (is_array($badges)) {
                    $teacher_new_badges = array_merge($teacher_new_badges, $badges);
                }
            }
        }
        $allowed_teacher_services = array_unique($allowed_teacher_services);
        $teacher_new_badges = array_unique($teacher_new_badges);
    } catch (PDOException $e) {
        error_log("Error fetching teacher stage services: " . $e->getMessage());
    }
    
    // If per-user override exists, use it instead of stage config
    $has_stage_config = !empty($stage_rows);
    if ($user_service_override && $user_service_override['override_stage']) {
        $override_services = json_decode($user_service_override['services'], true);
        if (is_array($override_services)) {
            $allowed_teacher_services = $override_services;
            $has_stage_config = true; // Force using the override list
        }
    }
    
    // Format classes as string
    $classes_text = 'لا توجد فصول';
    if (!empty($teacher_classes)) {
        $class_names = array_column($teacher_classes, 'name');
        $classes_text = implode(', ', $class_names);
    }
    ?>

    <div class="container">
        <!-- Welcome Card with Teacher Info -->
        <div class="portal-welcome-card">
            <h2 class="portal-welcome-title">
                <i class="fas fa-hand-sparkles portal-welcome-icon"></i>
                مرحباً بك أستاذ
            </h2>
            <h3 class="portal-student-name"><?php echo htmlspecialchars($_SESSION['name']); ?></h3>
            
            <!-- Teacher Info Grid -->
            <div class="student-info-grid">
                <!-- Username -->
                <div class="student-info-item">
                    <i class="fas fa-user-graduate" style="color: #3b82f6;"></i>
                    <div class="student-info-label">اسم المستخدم</div>
                    <div class="value-with-copy">
                        <span class="value-text" id="username-text"><?php echo htmlspecialchars($user->username); ?></span>
                        <button class="copy-btn" onclick="copyToClipboard('username-text', this)" title="انقر لنسخ اسم المستخدم">
                            <i class="fas fa-clipboard"></i>
                            <span class="copy-btn-text">نسخ</span>
                        </button>
                    </div>
                </div>
                
                <!-- Password -->
                <div class="student-info-item">
                    <i class="fas fa-key" style="color: #f59e0b;"></i>
                    <div class="student-info-label">كلمة المرور</div>
                    <div class="value-with-copy">
                        <?php 
                        $plain_password = $user->password ?? ''; 
                        $masked_password = str_repeat('*', max(6, mb_strlen($plain_password)));
                        ?>
                        <input type="text" id="portal-password" class="portal-password-input" value="<?php echo htmlspecialchars($masked_password); ?>" data-password="<?php echo htmlspecialchars($plain_password); ?>" readonly>
                        <div class="portal-password-actions">
                            <button class="copy-btn" onclick="togglePassword('portal-password', this)" title="عرض/إخفاء كلمة المرور">
                                <i class="fas fa-eye"></i>
                                <span class="copy-btn-text">عرض</span>
                            </button>
                            <button class="copy-btn" onclick="copyPasswordToClipboard('portal-password', this)" title="نسخ كلمة المرور">
                                <i class="fas fa-clipboard"></i>
                                <span class="copy-btn-text">نسخ</span>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Classes -->
                <div class="student-info-item classes-info-item">
                    <i class="fas fa-school" style="color: #10b981;"></i>
                    <div class="student-info-label">الفصول الدراسية (<?php echo count($teacher_classes); ?>)</div>
                    <div class="value-with-copy">
                        <div class="classes-badges-container">
                            <?php if (empty($teacher_classes)): ?>
                                <span class="text-muted">لا توجد فصول</span>
                            <?php else: 
                                $max_visible = 3;
                                foreach ($teacher_classes as $idx => $tc): ?>
                                    <span class="class-badge <?php echo $idx >= $max_visible ? 'class-badge-hidden' : ''; ?>"><?php echo htmlspecialchars($tc['name']); ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($teacher_classes) && count($teacher_classes) > $max_visible): ?>
                            <button type="button" class="copy-btn class-toggle-btn" onclick="toggleClasses(this)" title="عرض/إخفاء كل الفصول">
                                <i class="fas fa-chevron-down"></i>
                                <span class="copy-btn-text">عرض الكل (<?php echo count($teacher_classes); ?>)</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Occasion Banners & Notifications -->
    <?php
    // Get active occasions for teachers
    $activeOccasions = getActiveOccasions($db, 'teacher');
    echo renderOccasionBanners($activeOccasions);
    
    // Get teacher notifications from admin
    $teacherNotifications = getTeacherNotifications($db, $_SESSION['user_id']);
    echo renderPortalNotifications($teacherNotifications);
    ?>

    <!-- Navigation Grid -->
    <div class="container">
        <div class="nav-grid">
            <a href="../staff_hr_portal.php" class="nav-button">
                <i class="fas fa-people-roof"></i>
                <h3>خدمات شؤون العاملين</h3>
                <p>الأذونات والإجازات والاعتمادات ومنصة ارتق</p>
            </a>
            
            <?php if (!$has_stage_config || in_array('rewards', $allowed_teacher_services)): ?>
            <!-- نظام المكافآت -->
            <a href="index.php" class="nav-button"<?php if (in_array('rewards', $teacher_new_badges)): ?> style="position: relative;"<?php endif; ?>>
                <?php if (in_array('rewards', $teacher_new_badges)): ?>
                <span style="position: absolute; top: 8px; right: 8px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; box-shadow: 0 3px 10px rgba(239, 68, 68, 0.4); animation: newPulse 2s infinite; z-index: 2;">جديد 🎓</span>
                <?php endif; ?>
                <i class="fas fa-award"></i>
                <h3>نظام المكافآت</h3>
                <p>رصد وتقييم النقاط</p>
            </a>
            <?php endif; ?>

            <?php if (!$has_stage_config || in_array('lesson_prep', $allowed_teacher_services)): ?>
            <!-- تحضير الدروس بالذكاء الاصطناعي -->
            <a href="lesson_welcome.php" class="nav-button"<?php if (in_array('lesson_prep', $teacher_new_badges)): ?> style="position: relative;"<?php endif; ?>>
                <?php if (in_array('lesson_prep', $teacher_new_badges)): ?>
                <span style="position: absolute; top: 8px; right: 8px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; box-shadow: 0 3px 10px rgba(239, 68, 68, 0.4); animation: newPulse 2s infinite; z-index: 2;">جديد 🎓</span>
                <?php endif; ?>
                <i class="fas fa-robot"></i>
                <h3>تحضير الدروس</h3>
                <p>تحضير تلقائي وإنشاء اختبارات</p>
            </a>
            <?php endif; ?>

            <?php if (!$has_stage_config || in_array('grade_system', $allowed_teacher_services)): ?>
            <!-- نظام رصد الدرجات -->
            <a href="assessment_marks.php" class="nav-button"<?php if (in_array('grade_system', $teacher_new_badges)): ?> style="position: relative;"<?php endif; ?>>
                <?php if (in_array('grade_system', $teacher_new_badges)): ?>
                <span style="position: absolute; top: 8px; right: 8px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; box-shadow: 0 3px 10px rgba(239, 68, 68, 0.4); animation: newPulse 2s infinite; z-index: 2;">جديد 🎓</span>
                <?php endif; ?>
                <i class="fas fa-graduation-cap"></i>
                <h3>نظام رصد الدرجات</h3>
                <p>رصد وإدارة درجات الطلاب</p>
            </a>
            <a href="assessment_review.php" class="nav-button">
                <i class="fas fa-clipboard-check"></i>
                <h3>مراجعة الدرجات</h3>
                <p>اعتماد أو رفض الدرجات التي تتطلب مراجعة</p>
            </a>
            <a href="assessment_reports.php" class="nav-button">
                <i class="fas fa-file-export"></i>
                <h3>نشر التقارير</h3>
                <p>إتاحة تقارير الدرجات للطلاب حسب الصلاحية</p>
            </a>
            <?php endif; ?>

            <?php if (!$has_stage_config || in_array('attendance', $allowed_teacher_services)): ?>
            <!-- نظام الحضور والغياب -->
            <a href="attendance.php" class="nav-button"<?php if (in_array('attendance', $teacher_new_badges)): ?> style="position: relative;"<?php endif; ?>>
                <?php if (in_array('attendance', $teacher_new_badges)): ?>
                <span style="position: absolute; top: 8px; right: 8px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; box-shadow: 0 3px 10px rgba(239, 68, 68, 0.4); animation: newPulse 2s infinite; z-index: 2;">جديد 🎓</span>
                <?php endif; ?>
                <i class="fas fa-clipboard-check"></i>
                <h3>الحضور والغياب</h3>
                <p>تسجيل حضور وغياب الطلاب</p>
            </a>
            <?php endif; ?>

            <?php if (!$has_stage_config || in_array('timetable', $allowed_teacher_services)): ?>
            <!-- الجدول المدرسي -->
            <a href="timetable.php" class="nav-button"<?php if (in_array('timetable', $teacher_new_badges)): ?> style="position: relative;"<?php endif; ?>>
                <?php if (in_array('timetable', $teacher_new_badges)): ?>
                <span style="position: absolute; top: 8px; right: 8px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; box-shadow: 0 3px 10px rgba(239, 68, 68, 0.4); animation: newPulse 2s infinite; z-index: 2;">جديد 🎓</span>
                <?php endif; ?>
                <i class="fas fa-calendar-alt"></i>
                <h3>الجدول المدرسي</h3>
                <p>عرض جدول الحصص الأسبوعي</p>
            </a>
            <?php endif; ?>

            <?php if (!$has_stage_config || in_array('training', $allowed_teacher_services)): ?>
            <!-- التطوير المهني والتدريبات -->
            <a href="training.php" class="nav-button"<?php if (in_array('training', $teacher_new_badges)): ?> style="position: relative;"<?php endif; ?>>
                <?php if (in_array('training', $teacher_new_badges)): ?>
                <span style="position: absolute; top: 8px; right: 8px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; box-shadow: 0 3px 10px rgba(239, 68, 68, 0.4); animation: newPulse 2s infinite; z-index: 2;">جديد 🎓</span>
                <?php endif; ?>
                <i class="fas fa-chalkboard-teacher"></i>
                <h3>التطوير المهني</h3>
                <p>التدريبات والدورات التطويرية</p>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <style>
        @keyframes newPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.08); }
        }
    </style>

    <!-- Footer -->
    <footer class="portal-footer">
        <div class="container text-center">
            <p>جميع الحقوق محفوظة © <?php echo date('Y'); ?><br> Delta Modern Language Schools<br>
                Computer Department</p>
            
            <!-- Social Media Icons in Footer -->
            <div class="social-media-footer">
                <a href="https://www.facebook.com/DELTA.MLS" target="_blank" class="social-footer-icon facebook" title="صفحتنا على الفيسبوك">
                    <i class="fab fa-facebook-f"></i>
                </a>
                <a href="https://wa.me/201289999818" target="_blank" class="social-footer-icon whatsapp" title="الدعم الفني - واتساب">
                    <i class="fab fa-whatsapp"></i>
                </a>
                <a href="https://www.instagram.com/delta.mls" target="_blank" class="social-footer-icon instagram" title="حسابنا على انستجرام">
                    <i class="fab fa-instagram"></i>
                </a>
            </div>
        </div>
    </footer>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
        <p>جاري التحميل...</p>
    </div>

    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script src="script.js?v=1.2"></script>
    
    <script>
        // Toggle classes visibility
        function toggleClasses(btn) {
            const card = btn.closest('.classes-info-item') || btn.parentElement;
            const container = card.querySelector('.classes-badges-container');
            if (!container) return;
            const isExpanded = container.classList.toggle('classes-expanded');
            const icon = btn.querySelector('i');
            const textSpan = btn.querySelector('.copy-btn-text');
            const total = container.querySelectorAll('.class-badge').length;
            if (isExpanded) {
                if (icon) icon.className = 'fas fa-chevron-up';
                if (textSpan) textSpan.textContent = 'عرض أقل';
            } else {
                if (icon) icon.className = 'fas fa-chevron-down';
                if (textSpan) textSpan.textContent = 'عرض الكل (' + total + ')';
            }
        }

        // Toggle Password Visibility
        function togglePassword(inputId, button) {
            const passwordInput = document.getElementById(inputId);
            const icon = button.querySelector('i');
            const textSpan = button.querySelector('.copy-btn-text');
            const realPassword = passwordInput.getAttribute('data-password');

            if (!realPassword) {
                alert('كلمة المرور مشفرة بنظام أمان متقدم وغير قابلة للقراءة.');
                return;
            }

            if (passwordInput.value !== realPassword) {
                passwordInput.value = realPassword;
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                if (textSpan) textSpan.textContent = 'إخفاء';
            } else {
                const maskedVal = '*'.repeat(Math.max(6, realPassword.length));
                passwordInput.value = maskedVal;
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                if (textSpan) textSpan.textContent = 'عرض';
            }
        }

        // Copy Password to Clipboard Function
        function copyPasswordToClipboard(inputId, button) {
            const passwordInput = document.getElementById(inputId);
            const realPassword = passwordInput.getAttribute('data-password');
            
            if (!realPassword) {
                alert('لا يمكن نسخ كلمة المرور لأنها مشفرة بنظام أمان متقدم.');
                return;
            }
            
            const tempInput = document.createElement('textarea');
            tempInput.value = realPassword;
            tempInput.style.position = 'fixed';
            tempInput.style.opacity = '0';
            document.body.appendChild(tempInput);
            
            tempInput.select();
            tempInput.setSelectionRange(0, 99999); // For mobile devices
            
            try {
                document.execCommand('copy');
                
                // Visual feedback
                const originalHTML = button.innerHTML;
                button.classList.add('copied');
                button.innerHTML = '<i class="fas fa-check-circle"></i><span class="copy-btn-text">تم النسخ ✓</span>';
                
                setTimeout(() => {
                    button.classList.remove('copied');
                    button.innerHTML = originalHTML;
                }, 2500);
            } catch (err) {
                console.error('فشل النسخ:', err);
            }
            
            document.body.removeChild(tempInput);
        }

        // Copy to Clipboard Function
        function copyToClipboard(elementId, button) {
            const textElement = document.getElementById(elementId);
            const textToCopy = textElement.textContent;
            
            // Create a temporary textarea element
            const tempInput = document.createElement('textarea');
            tempInput.value = textToCopy;
            tempInput.style.position = 'fixed';
            tempInput.style.opacity = '0';
            document.body.appendChild(tempInput);
            
            // Select and copy the text
            tempInput.select();
            tempInput.setSelectionRange(0, 99999); // For mobile devices
            
            try {
                document.execCommand('copy');
                
                // Visual feedback - Change button appearance
                const originalHTML = button.innerHTML;
                button.classList.add('copied');
                button.innerHTML = '<i class="fas fa-check-circle"></i><span class="copy-btn-text">تم النسخ ✓</span>';
                
                // Reset button after 2.5 seconds
                setTimeout(() => {
                    button.classList.remove('copied');
                    button.innerHTML = originalHTML;
                }, 2500);
                
            } catch (err) {
                console.error('فشل النسخ:', err);
                alert('حدث خطأ أثناء النسخ. يرجى المحاولة مرة أخرى.');
            }
            
            // Remove the temporary element
            document.body.removeChild(tempInput);
        }

        // Theme Toggle
        (function() {
            const themeToggle = document.getElementById('themeToggle');
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode');
                document.body.classList.remove('light-mode');
                themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
            } else {
                document.body.classList.remove('dark-mode');
                document.body.classList.add('light-mode');
                themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
            }
            if (typeof updateParticlesTheme === 'function') updateParticlesTheme(savedTheme || 'light');
            themeToggle.addEventListener('click', () => {
                document.body.classList.toggle('dark-mode');
                if (document.body.classList.contains('dark-mode')) {
                    document.body.classList.remove('light-mode');
                    localStorage.setItem('theme', 'dark');
                    themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
                    if (typeof updateParticlesTheme === 'function') updateParticlesTheme('dark');
                } else {
                    document.body.classList.add('light-mode');
                    localStorage.setItem('theme', 'light');
                    themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
                    if (typeof updateParticlesTheme === 'function') updateParticlesTheme('light');
                }
            });
        })();
    </script>
    <?php echo getPortalNotificationsAssets('../api/dismiss_notification.php'); ?>
</body>

</html>
