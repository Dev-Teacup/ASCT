<?php
declare(strict_types=1);

require_once __DIR__ . '/api/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
<title>Aurora State College of Technology — Student Information Management System</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Barlow+Condensed:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/index.css">
<link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body>

<!-- Toast Container -->
<div id="toast-container" aria-live="polite"></div>

<!-- Confirmation Modal -->
<div id="confirm-modal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal-box" style="max-width:440px">
        <div class="modal-header">
            <h2 id="confirm-title">CONFIRM ACTION</h2>
            <button class="modal-close" data-close-modal="confirm" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <p id="confirm-message" style="color:var(--silver);line-height:1.6"></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" data-close-modal="confirm">Cancel</button>
            <button id="confirm-btn" class="btn btn-danger btn-sm">Confirm</button>
        </div>
    </div>
</div>

<!-- Student Form Modal -->
<div id="student-modal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal-box">
        <div class="modal-header">
            <h2 id="student-modal-title">ADD STUDENT</h2>
            <button class="modal-close" data-close-modal="student" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="student-modal-body"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" data-close-modal="student">Cancel</button>
            <button id="student-modal-save" class="btn btn-primary btn-sm">Save Record</button>
        </div>
    </div>
</div>

<!-- Approval Queue Modal -->
<div id="approval-modal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal-box" style="max-width:760px">
        <div class="modal-header">
            <h2>PENDING APPROVALS</h2>
            <button class="modal-close" data-close-modal="approval" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="approval-modal-body"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" data-close-modal="approval">Close</button>
        </div>
    </div>
</div>

<!-- Stat Detail Modal -->
<div id="stat-detail-modal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal-box" style="max-width:680px">
        <div class="modal-header">
            <h2 id="stat-detail-modal-title">DETAILS</h2>
            <button class="modal-close" data-close-modal="stat-detail" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="stat-detail-modal-body"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" data-close-modal="stat-detail">Close</button>
        </div>
    </div>
</div>

<!-- Faculty Form Modal -->
<div id="user-modal" class="modal-overlay" role="dialog" aria-modal="true">
    <div class="modal-box" style="max-width:500px">
        <div class="modal-header">
            <h2 id="user-modal-title">ADD FACULTY</h2>
            <button class="modal-close" data-close-modal="user" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="user-modal-body"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" data-close-modal="user">Cancel</button>
            <button id="user-modal-save" class="btn btn-primary btn-sm">Save Faculty</button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- LOGIN PAGE                                   -->
<!-- ============================================ -->
<div id="login-page">
    <div class="login-hero">
        <div class="login-hero-content">
            <img class="brand-logo login-brand" src="assets/img/asct-logo.png" alt="ASCT.">
            <div class="login-tagline">AURORA STATE COLLEGE OF TECHNOLOGY</div>
        </div>
    </div>
    <div class="login-form-side">
        <h2 class="login-form-title">SIGN IN</h2>
        <p class="login-form-sub" id="login-form-sub">Access your ASCT dashboard and student records.</p>
        <div id="login-error" class="login-error"><i id="login-message-icon" class="fa-solid fa-circle-exclamation"></i><span id="login-error-msg"></span></div>
        <form id="login-form" autocomplete="off">
            <div class="form-group">
                <label for="login-email">Email Address</label>
                <input type="email" id="login-email" placeholder="Enter your email" required>
                <div class="error-msg">Please enter a valid email address</div>
            </div>
            <div class="form-group">
                <label for="login-password">Password</label>
                <input type="password" id="login-password" placeholder="Enter your password" required>
                <div class="error-msg">Password is required</div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block" style="margin-top:8px;font-family:var(--font-heading);letter-spacing:3px;font-size:1.2rem">
                <i class="fa-solid fa-right-to-bracket"></i> ACCESS SYSTEM
            </button>
            <div class="login-divider"><span>or</span></div>
            <button type="button" id="passkey-login-btn" class="btn btn-secondary btn-lg btn-block" style="font-family:var(--font-heading);letter-spacing:3px;font-size:1.2rem">
                <i class="fa-solid fa-fingerprint"></i> USE PASSKEY
            </button>
            <div class="login-inline-actions" style="justify-content:center">
                <button type="button" class="login-link-btn" id="signup-show-btn">Create Student Account</button>
            </div>
        </form>
        <form id="otp-form" autocomplete="off" style="display:none">
            <div class="login-note">
                A 6-digit verification code was sent to <strong id="otp-email"></strong>.
            </div>
            <div class="form-group">
                <label for="otp-code">Verification Code</label>
                <input type="text" id="otp-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="Enter 6-digit code" required>
                <div class="error-msg">Enter the 6-digit verification code</div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block" style="margin-top:8px;font-family:var(--font-heading);letter-spacing:3px;font-size:1.2rem">
                <i class="fa-solid fa-shield-halved"></i> VERIFY LOGIN
            </button>
            <div class="login-inline-actions">
                <button type="button" class="login-link-btn" id="otp-back-btn">Different Account</button>
                <button type="button" class="login-link-btn" id="otp-resend-btn">Resend Code</button>
            </div>
        </form>
        <form id="signup-form" autocomplete="off" style="display:none">
            <div class="login-note">
                Student accounts require email confirmation and admin approval before sign-in is available.
            </div>
            <div class="form-group">
                <label for="signup-name">Full Name</label>
                <input type="text" id="signup-name" placeholder="Enter your full name" required>
                <div class="error-msg">Full name is required</div>
            </div>
            <div class="form-group">
                <label for="signup-email">Email Address</label>
                <input type="email" id="signup-email" placeholder="email@asct.edu.ph" required>
                <div class="error-msg">Please enter a valid email address</div>
            </div>
            <div class="form-group">
                <label for="signup-password">Password</label>
                <input type="password" id="signup-password" placeholder="At least 8 characters" minlength="8" required>
                <div class="error-msg">Password must be at least 8 characters</div>
            </div>
            <div class="form-group">
                <label for="signup-password-confirm">Confirm Password</label>
                <input type="password" id="signup-password-confirm" placeholder="Repeat your password" minlength="8" required>
                <div class="error-msg">Passwords must match</div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block" style="margin-top:8px;font-family:var(--font-heading);letter-spacing:3px;font-size:1.2rem">
                <i class="fa-solid fa-envelope-circle-check"></i> SEND CONFIRMATION
            </button>
            <div class="login-inline-actions" style="justify-content:center">
                <button type="button" class="login-link-btn" id="signup-back-btn">Back to Sign In</button>
            </div>
        </form>
        <form id="signup-otp-form" autocomplete="off" style="display:none">
            <div class="login-note">
                A 6-digit confirmation code was sent to <strong id="signup-otp-email"></strong>.
            </div>
            <div class="form-group">
                <label for="signup-otp-code">Confirmation Code</label>
                <input type="text" id="signup-otp-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="Enter 6-digit code" required>
                <div class="error-msg">Enter the 6-digit confirmation code</div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block" style="margin-top:8px;font-family:var(--font-heading);letter-spacing:3px;font-size:1.2rem">
                <i class="fa-solid fa-shield-halved"></i> CONFIRM EMAIL
            </button>
            <div class="login-inline-actions">
                <button type="button" class="login-link-btn" id="signup-otp-back-btn">Edit Signup</button>
                <button type="button" class="login-link-btn" id="signup-otp-resend-btn">Resend Code</button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- APP CONTAINER                                -->
<!-- ============================================ -->
<div id="app-container">
    <!-- Header -->
    <header id="app-header">
        <div class="header-left">
            <button class="hamburger" id="hamburger-btn" aria-label="Toggle menu"><i class="fa-solid fa-bars"></i></button>
            <img class="brand-logo header-brand" src="assets/img/asct-logo.png" alt="ASCT.">
            <div class="header-divider"></div>
            <div class="header-page-title" id="header-page-title">DASHBOARD</div>
        </div>
        <div class="header-right">
            <div class="header-user">
                <div class="header-avatar" id="header-avatar">A</div>
                <div class="header-user-info">
                    <div class="header-user-name" id="header-user-name">Admin</div>
                    <span class="role-badge admin" id="header-role-badge">ADMIN</span>
                </div>
            </div>
            <button class="btn-logout" id="logout-btn" aria-label="Logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</button>
        </div>
    </header>

    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    <!-- Sidebar -->
    <aside id="sidebar">
        <div class="sidebar-section">
            <div class="sidebar-section-title">Navigation</div>
            <ul class="sidebar-nav" id="sidebar-nav"></ul>
        </div>
        <div class="sidebar-footer">
            <div class="sidebar-footer-text">ASCT SIMS v1.0</div>
        </div>
    </aside>

    <!-- Main Content -->
    <main id="main-content">
        <section class="content-section" id="view-dashboard"></section>
        <section class="content-section" id="view-students"></section>
        <section class="content-section" id="view-users"></section>
        <section class="content-section" id="view-approval"></section>
        <section class="content-section" id="view-audit"></section>
        <section class="content-section" id="view-profile"></section>
        <section class="content-section" id="view-security"></section>
    </main>
</div>

<script src="assets/js/state.js"></script>
<script src="assets/js/auth.js"></script>
<script src="assets/js/permissions.js"></script>
<script src="assets/js/navigation.js"></script>
<script src="assets/js/ui.js"></script>
<script src="assets/js/delete-actions.js"></script>
<script src="assets/js/views-dashboard.js"></script>
<script src="assets/js/views-students.js"></script>
<script src="assets/js/views-admin.js"></script>
<script src="assets/js/views-profile.js"></script>
<script src="assets/js/events.js"></script>
<script src="assets/js/init.js"></script>
</body>
</html>

