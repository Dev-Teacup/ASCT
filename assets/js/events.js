/* ============================================ */
/* 8. EVENT HANDLERS                            */
/* ============================================ */

document.addEventListener('error', (e) => {
    if (e.target?.classList?.contains('avatar-img')) {
        e.target.hidden = true;
    }
}, true);

document.addEventListener('load', (e) => {
    if (e.target?.classList?.contains('avatar-img')) {
        e.target.hidden = false;
    }
}, true);

// Login form submission
document.getElementById('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const email = document.getElementById('login-email').value.trim();
    const password = document.getElementById('login-password').value;
    const submitBtn = e.currentTarget.querySelector('button[type="submit"]');

    if (!email || !password) {
        showLoginError('Please enter both email and password.');
        return;
    }

    clearLoginError();
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ACCESSING...';

    const result = await login(email, password);
    if (result.success) {
        if (result.requiresOtp) {
            showOtpLoginStep(result.email, result.resendAvailableIn);
        } else {
            showApp();
        }
    } else {
        showLoginError(result.message);
    }

    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="fa-solid fa-right-to-bracket"></i> ACCESS SYSTEM';
});

document.getElementById('signup-show-btn').addEventListener('click', () => {
    showSignupStep();
});

document.getElementById('signup-back-btn').addEventListener('click', () => {
    showPasswordLoginStep();
});

document.getElementById('signup-form').addEventListener('submit', async (e) => {
    e.preventDefault();

    const name = document.getElementById('signup-name').value.trim();
    const email = document.getElementById('signup-email').value.trim();
    const password = document.getElementById('signup-password').value;
    const passwordConfirm = document.getElementById('signup-password-confirm').value;
    const submitBtn = e.currentTarget.querySelector('button[type="submit"]');

    if (!name || !email || !password || !passwordConfirm) {
        showLoginError('Please fill in all signup fields.');
        return;
    }

    if (password.length < 8) {
        showLoginError('Password must be at least 8 characters.');
        return;
    }

    if (password !== passwordConfirm) {
        showLoginError('Passwords do not match.');
        return;
    }

    clearLoginError();
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> SENDING CODE...';

    const result = await signupStudent({
        name,
        email,
        password,
        password_confirm: passwordConfirm
    });

    if (result.success) {
        showSignupVerificationStep(result.email, result.resendAvailableIn);
    } else {
        showLoginError(result.message);
    }

    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="fa-solid fa-envelope-circle-check"></i> SEND CONFIRMATION';
});

document.getElementById('signup-otp-code').addEventListener('input', (e) => {
    e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6);
});

document.getElementById('signup-otp-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const code = document.getElementById('signup-otp-code').value.trim();
    const submitBtn = e.currentTarget.querySelector('button[type="submit"]');
    const confirmedEmail = state.signupChallenge?.email || document.getElementById('signup-email').value.trim();

    if (!/^\d{6}$/.test(code)) {
        showLoginError('Please enter the 6-digit confirmation code.');
        return;
    }

    clearLoginError();
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> CONFIRMING...';

    const result = await verifySignupCode(code);
    if (result.success) {
        document.getElementById('signup-form').reset();
        document.getElementById('signup-otp-form').reset();
        document.getElementById('login-email').value = confirmedEmail;
        showPasswordLoginStep();
        showLoginSuccess('Email confirmed. Your student account request is waiting for admin approval.');
    } else {
        showLoginError(result.message);
    }

    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> CONFIRM EMAIL';
});

document.getElementById('signup-otp-resend-btn').addEventListener('click', async () => {
    const resendBtn = document.getElementById('signup-otp-resend-btn');

    clearLoginError();
    resendBtn.disabled = true;
    resendBtn.textContent = 'Sending...';

    const result = await resendSignupCode();
    if (result.success) {
        showToast('A new confirmation code was sent.', 'success');
        document.getElementById('signup-otp-code').value = '';
        document.getElementById('signup-otp-code').focus();
        startResendCountdown(result.resendAvailableIn, 'signup-otp-resend-btn');
    } else {
        showLoginError(result.message);
        resendBtn.disabled = false;
        resendBtn.textContent = 'Resend Code';
    }
});

document.getElementById('signup-otp-back-btn').addEventListener('click', () => {
    showSignupStep();
});

document.getElementById('passkey-login-btn').addEventListener('click', async () => {
    const emailInput = document.getElementById('login-email');
    const email = emailInput.value.trim();
    const passkeyBtn = document.getElementById('passkey-login-btn');

    if (!email) {
        showLoginError('Please enter your email address before using a passkey.');
        emailInput.focus();
        return;
    }

    clearLoginError();
    passkeyBtn.disabled = true;
    passkeyBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> WAITING...';

    const result = await loginWithPasskey(email);
    if (result.success) {
        showApp();
    } else {
        showLoginError(result.message);
    }

    passkeyBtn.disabled = false;
    passkeyBtn.innerHTML = '<i class="fa-solid fa-fingerprint"></i> USE PASSKEY';
});

document.getElementById('otp-code').addEventListener('input', (e) => {
    e.target.value = e.target.value.replace(/\D/g, '').slice(0, 6);
});

document.getElementById('otp-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const code = document.getElementById('otp-code').value.trim();
    const submitBtn = e.currentTarget.querySelector('button[type="submit"]');

    if (!/^\d{6}$/.test(code)) {
        showLoginError('Please enter the 6-digit verification code.');
        return;
    }

    clearLoginError();
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> VERIFYING...';

    const result = await verifyLoginOtp(code);
    if (result.success) {
        showApp();
    } else {
        showLoginError(result.message);
    }

    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> VERIFY LOGIN';
});

document.getElementById('otp-resend-btn').addEventListener('click', async () => {
    const resendBtn = document.getElementById('otp-resend-btn');

    clearLoginError();
    resendBtn.disabled = true;
    resendBtn.textContent = 'Sending...';

    const result = await resendLoginOtp();
    if (result.success) {
        showToast('A new verification code was sent.', 'success');
        document.getElementById('otp-code').value = '';
        document.getElementById('otp-code').focus();
        startResendCountdown(result.resendAvailableIn);
    } else {
        showLoginError(result.message);
        resendBtn.disabled = false;
        resendBtn.textContent = 'Resend Code';
    }
});

document.getElementById('otp-back-btn').addEventListener('click', () => {
    showPasswordLoginStep();
    document.getElementById('login-password').value = '';
    document.getElementById('login-password').focus();
});

// Logout button
document.getElementById('logout-btn').addEventListener('click', logout);

// Hamburger menu toggle
document.getElementById('hamburger-btn').addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebar-overlay').classList.toggle('show');
});
document.getElementById('sidebar-overlay').addEventListener('click', () => {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebar-overlay').classList.remove('show');
});

// Sidebar navigation clicks (event delegation)
document.getElementById('sidebar-nav').addEventListener('click', (e) => {
    e.preventDefault();
    const link = e.target.closest('[data-nav]');
    if (link) navigateTo(link.dataset.nav);
});

const mainContentActionHandlers = {
    'add-student': () => {
        if (!hasPermission('add_student')) { showToast('Only admins can add students.', 'error'); return; }
        openStudentModal(null);
    },
    'edit-student': ({ id }) => {
        if (!hasPermission('edit_students')) { showToast('You do not have permission to edit students.', 'error'); return; }
        if (id) openStudentModal(id);
    },
    'soft-delete': ({ id }) => softDeleteStudent(id),
    'hard-delete': ({ id }) => hardDeleteStudent(id),
    'export-students': ({ btn }) => exportStudentsToExcel(btn),
    'add-user': () => openUserModal(null),
    'edit-user': ({ id }) => openUserModal(id),
    'delete-user': ({ id }) => deleteUser(id),
    'approve-student-user': ({ id }) => openStudentApprovalModal(id),
    'reject-student-user': ({ id }) => rejectStudentSignup(id),
    'open-pending-approvals': () => openPendingApprovalsModal(),
    'stat-total-students': ({ action }) => openStatDetailModal(action),
    'stat-active-students': ({ action }) => openStatDetailModal(action),
    'stat-inactive-students': ({ action }) => openStatDetailModal(action),
    'stat-total-faculty': ({ action }) => openStatDetailModal(action),
    'nav-students': () => navigateTo('students'),
    'nav-users': () => navigateTo('users'),
    'nav-approval': () => navigateTo('approval'),
    'refresh-audit': () => refreshAuditLogs().then(renderCurrentView),
    'nav-profile': () => navigateTo('profile'),
    'add-passkey': () => addPasskey(),
    'delete-passkey': ({ id }) => deletePasskey(id),
    'edit-own-student': () => {
        const ownStudent = findOwnStudent();
        if (ownStudent) openStudentModal(ownStudent.id);
        else showToast('Your student record was not found.', 'error');
    },
};

// Main content action clicks (event delegation)
document.getElementById('main-content').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    const action = btn.dataset.action;
    const id = btn.dataset.id ? parseInt(btn.dataset.id) : null;
    const handler = mainContentActionHandlers[action];

    if (handler) {
        handler({ action, btn, id });
    }
});

document.getElementById('main-content').addEventListener('keydown', (e) => {
    const target = e.target.closest('[data-action="open-pending-approvals"], [data-action="stat-total-students"], [data-action="stat-active-students"], [data-action="stat-inactive-students"], [data-action="stat-total-faculty"]');
    if (!target || !['Enter', ' '].includes(e.key)) return;

    e.preventDefault();
    const action = target.dataset.action;
    if (action === 'open-pending-approvals') {
        openPendingApprovalsModal();
    } else {
        openStatDetailModal(action);
    }
});

document.getElementById('approval-modal-body').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;

    const id = btn.dataset.id ? parseInt(btn.dataset.id, 10) : null;
    if (btn.dataset.action === 'approve-student-user') {
        closeApprovalModal();
        openStudentApprovalModal(id);
    }
    if (btn.dataset.action === 'reject-student-user') {
        closeApprovalModal();
        rejectStudentSignup(id);
    }
});

// Search and filter event delegation for students
document.getElementById('main-content').addEventListener('input', (e) => {
    if (['student-search','filter-status','filter-course','filter-year'].includes(e.target.id)) {
        filterStudentTable();
    }
    if (e.target.id === 'user-search') {
        filterUserTable();
    }
});

document.getElementById('main-content').addEventListener('change', (e) => {
    if (e.target.id === 'profile-picture-input') {
        handleProfilePictureUpload(e.target.files?.[0], e.target);
        return;
    }

    if (e.target.id === 'enrollment-trend-range') {
        state.enrollmentTrendRange = e.target.value;
        const panel = document.getElementById('enrollment-trend-panel');
        if (panel) {
            panel.outerHTML = renderProgramsSection();
        }
    }
});

// Keyboard: Escape closes modals
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeStudentModal();
        closeUserModal();
        closeApprovalModal();
        closeStatDetailModal();
        closeConfirm();
    }
});

// Click outside modal to close
['student-modal','user-modal','approval-modal','confirm-modal','stat-detail-modal'].forEach(id => {
    document.getElementById(id).addEventListener('click', (e) => {
        if (e.target === document.getElementById(id)) {
            if (id === 'student-modal') closeStudentModal();
            else if (id === 'user-modal') closeUserModal();
            else if (id === 'approval-modal') closeApprovalModal();
            else if (id === 'stat-detail-modal') closeStatDetailModal();
            else closeConfirm();
        }
    });
});

