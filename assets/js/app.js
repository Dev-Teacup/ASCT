/* ============================================ */
/* 1. DATA & STATE MANAGEMENT                   */
/* ============================================ */

// Users and students are loaded from the PHP/MySQL API after authentication.

// Application state
let state = {
    currentUser: null,     // Currently logged-in user object
    loginChallenge: null,  // Pending email OTP challenge after password verification
    signupChallenge: null, // Pending email confirmation challenge during student signup
    currentView: 'dashboard',
    enrollmentTrendRange: '3y',
    users: [],
    passkeys: [],
    students: [],
    auditLogs: [],
    csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || '',
    profilePictureVersion: Date.now(),
    editingStudentId: null, // ID of student being edited (null = adding new)
    editingUserId: null,    // ID of user being edited
    approvingUserId: null   // Pending student user being approved
};

const PROFILE_PICTURE_MAX_BYTES = 2 * 1024 * 1024;
const PROFILE_PICTURE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

function setCsrfToken(token) {
    if (!token) return;
    state.csrfToken = token;

    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) {
        meta.content = token;
    }
}

function getCsrfToken() {
    return state.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function apiRequest(endpoint, action, options = {}) {
    const method = options.method || 'GET';
    const init = {
        method,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
    };

    if (method !== 'GET') {
        const csrfToken = getCsrfToken();
        if (!csrfToken) {
            throw new Error('Security token is missing. Refresh the page and try again.');
        }

        init.headers['Content-Type'] = 'application/json';
        init.headers['X-CSRF-Token'] = csrfToken;
        init.body = JSON.stringify({ action, ...(options.data || {}), csrf_token: csrfToken });
    }

    const response = await fetch(`api/${endpoint}.php?action=${encodeURIComponent(action)}`, init);
    const payload = await response.json().catch(() => null);

    if (payload?.csrf_token) {
        setCsrfToken(payload.csrf_token);
    }

    if (!payload || !payload.success) {
        throw new Error(payload?.message || 'Request failed. Please try again.');
    }

    return payload.data ?? null;
}

async function apiFormRequest(endpoint, action, formData) {
    const csrfToken = getCsrfToken();
    if (!csrfToken) {
        throw new Error('Security token is missing. Refresh the page and try again.');
    }

    if (!formData.has('csrf_token')) {
        formData.append('csrf_token', csrfToken);
    }

    const response = await fetch(`api/${endpoint}.php?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: formData
    });
    const payload = await response.json().catch(() => null);

    if (payload?.csrf_token) {
        setCsrfToken(payload.csrf_token);
    }

    if (!payload || !payload.success) {
        throw new Error(payload?.message || 'Request failed. Please try again.');
    }

    return payload.data ?? null;
}

function normalizeStudent(student) {
    return {
        ...student,
        id: parseInt(student.id, 10),
        user_id: student.user_id === null || student.user_id === undefined ? null : parseInt(student.user_id, 10),
        year_level: parseInt(student.year_level, 10)
    };
}

function normalizeUser(user) {
    return {
        ...user,
        id: parseInt(user.id, 10),
        status: user.status || 'active',
        requested_student_id: user.requested_student_id || '',
        profile_picture_version: user.profile_picture_version || null
    };
}

function normalizePasskey(passkey) {
    return {
        ...passkey,
        id: parseInt(passkey.id, 10)
    };
}

function normalizeAuditLog(log) {
    let metadata = {};
    if (log.metadata) {
        try {
            metadata = typeof log.metadata === 'string' ? JSON.parse(log.metadata) : log.metadata;
        } catch (error) {
            metadata = {};
        }
    }

    return {
        ...log,
        id: parseInt(log.id, 10),
        actor_user_id: log.actor_user_id === null || log.actor_user_id === undefined ? null : parseInt(log.actor_user_id, 10),
        target_id: log.target_id === null || log.target_id === undefined ? null : parseInt(log.target_id, 10),
        metadata
    };
}

async function loadData() {
    const [students, users, passkeys, auditLogs] = await Promise.all([
        apiRequest('students', 'list'),
        apiRequest('users', 'list'),
        apiRequest('auth', 'passkey_list'),
        state.currentUser?.role === 'admin'
            ? apiRequest('audit_logs', 'list')
            : Promise.resolve([])
    ]);

    state.students = (students || []).map(normalizeStudent);
    state.users = (users || []).map(normalizeUser);
    state.passkeys = (passkeys || []).map(normalizePasskey);
    state.auditLogs = (auditLogs || []).map(normalizeAuditLog);
}

async function refreshAuditLogs() {
    if (state.currentUser?.role !== 'admin') {
        state.auditLogs = [];
        return;
    }

    try {
        const auditLogs = await apiRequest('audit_logs', 'list');
        state.auditLogs = (auditLogs || []).map(normalizeAuditLog);
    } catch (error) {
        showToast('Audit log refresh failed.', 'warning');
    }
}

function upsertStudent(student) {
    const normalized = normalizeStudent(student);
    const index = state.students.findIndex(s => s.id === normalized.id);

    if (index >= 0) {
        state.students[index] = normalized;
    } else {
        state.students.push(normalized);
    }
}

function removeStudent(studentId) {
    state.students = state.students.filter(s => s.id !== studentId);
}

function upsertUser(user) {
    const normalized = normalizeUser(user);
    const index = state.users.findIndex(u => u.id === normalized.id);

    if (index >= 0) {
        state.users[index] = normalized;
    } else {
        state.users.push(normalized);
    }

    if (state.currentUser && state.currentUser.id === normalized.id) {
        state.currentUser = normalized;
    }
}

function removeUser(userId) {
    state.users = state.users.filter(u => u.id !== userId);
}

function findOwnStudent() {
    if (!state.currentUser) return null;
    return state.students.find(s =>
        s.user_id === state.currentUser.id ||
        s.email === state.currentUser.email
    ) || null;
}

/* ============================================ */
/* 2. AUTHENTICATION                            */
/* ============================================ */

// Attempt login with email and password
async function login(email, password) {
    try {
        const data = await apiRequest('auth', 'login', { method: 'POST', data: { email, password } });
        if (data?.requires_otp) {
            state.loginChallenge = {
                token: data.challenge_token,
                email: data.email
            };

            return {
                success: true,
                requiresOtp: true,
                email: data.email,
                resendAvailableIn: data.resend_available_in || 60
            };
        }

        if (data?.user) {
            state.currentUser = normalizeUser(data.user);
            state.profilePictureVersion = Date.now();
            await loadData();
            return { success: true };
        }

        return { success: false, message: 'Unexpected authentication response.' };
    } catch (error) {
        return { success: false, message: error.message };
    }
}

async function signupStudent(payload) {
    try {
        const data = await apiRequest('auth', 'signup', { method: 'POST', data: payload });
        state.signupChallenge = {
            token: data.challenge_token,
            email: data.email
        };

        return {
            success: true,
            email: data.email,
            resendAvailableIn: data.resend_available_in || 60
        };
    } catch (error) {
        return { success: false, message: error.message };
    }
}

async function verifySignupCode(code) {
    if (!state.signupChallenge?.token) {
        return { success: false, message: 'Please restart signup to request a new confirmation code.' };
    }

    try {
        await apiRequest('auth', 'verify_signup', {
            method: 'POST',
            data: {
                challenge_token: state.signupChallenge.token,
                code
            }
        });
        state.signupChallenge = null;
        return { success: true };
    } catch (error) {
        return { success: false, message: error.message };
    }
}

async function resendSignupCode() {
    if (!state.signupChallenge?.token) {
        return { success: false, message: 'Please restart signup to request a new confirmation code.' };
    }

    try {
        const data = await apiRequest('auth', 'resend_signup_code', {
            method: 'POST',
            data: { challenge_token: state.signupChallenge.token }
        });

        return {
            success: true,
            resendAvailableIn: data?.resend_available_in || 60
        };
    } catch (error) {
        return { success: false, message: error.message };
    }
}

function base64UrlToArrayBuffer(value) {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
    }

    return bytes.buffer;
}

function arrayBufferToBase64Url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    const chunkSize = 0x8000;

    for (let i = 0; i < bytes.length; i += chunkSize) {
        const chunk = bytes.subarray(i, i + chunkSize);
        binary += String.fromCharCode(...chunk);
    }

    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function preparePasskeyCreationOptions(publicKey) {
    const options = JSON.parse(JSON.stringify(publicKey));
    options.challenge = base64UrlToArrayBuffer(options.challenge);
    options.user.id = base64UrlToArrayBuffer(options.user.id);
    options.excludeCredentials = (options.excludeCredentials || []).map(credential => ({
        ...credential,
        id: base64UrlToArrayBuffer(credential.id)
    }));

    return options;
}

function preparePasskeyRequestOptions(publicKey) {
    const options = JSON.parse(JSON.stringify(publicKey));
    options.challenge = base64UrlToArrayBuffer(options.challenge);
    options.allowCredentials = (options.allowCredentials || []).map(credential => ({
        ...credential,
        id: base64UrlToArrayBuffer(credential.id)
    }));

    return options;
}

function passkeyCredentialPayload(credential) {
    const response = credential.response;
    const payload = {
        id: credential.id,
        rawId: arrayBufferToBase64Url(credential.rawId),
        type: credential.type,
        response: {
            clientDataJSON: arrayBufferToBase64Url(response.clientDataJSON)
        }
    };

    if (response.attestationObject) {
        payload.response.attestationObject = arrayBufferToBase64Url(response.attestationObject);
    }
    if (response.authenticatorData) {
        payload.response.authenticatorData = arrayBufferToBase64Url(response.authenticatorData);
    }
    if (response.signature) {
        payload.response.signature = arrayBufferToBase64Url(response.signature);
    }
    if (response.userHandle) {
        payload.response.userHandle = arrayBufferToBase64Url(response.userHandle);
    }

    return payload;
}

function ensurePasskeySupport() {
    if (!window.PublicKeyCredential || !navigator.credentials) {
        throw new Error('This browser does not support passkeys.');
    }

    if (!window.isSecureContext) {
        throw new Error('Passkeys require localhost or HTTPS.');
    }
}

function passkeyErrorMessage(error) {
    if (error?.name === 'NotAllowedError') {
        return 'Passkey request was cancelled or timed out.';
    }

    if (error?.name === 'InvalidStateError') {
        return 'That passkey is already registered.';
    }

    if (error?.name === 'SecurityError') {
        return 'Passkeys are not available for this site origin.';
    }

    return error?.message || 'Passkey request failed.';
}

async function loginWithPasskey(email) {
    try {
        ensurePasskeySupport();
        const optionsData = await apiRequest('auth', 'passkey_login_options', {
            method: 'POST',
            data: { email }
        });
        const credential = await navigator.credentials.get({
            publicKey: preparePasskeyRequestOptions(optionsData.publicKey)
        });

        if (!credential) {
            return { success: false, message: 'No passkey was selected.' };
        }

        const data = await apiRequest('auth', 'passkey_login_verify', {
            method: 'POST',
            data: passkeyCredentialPayload(credential)
        });

        state.currentUser = normalizeUser(data.user);
        state.profilePictureVersion = Date.now();
        await loadData();
        return { success: true };
    } catch (error) {
        return { success: false, message: passkeyErrorMessage(error) };
    }
}

async function addPasskey() {
    const addBtn = document.querySelector('[data-action="add-passkey"]');
    const labelInput = document.getElementById('passkey-label');
    const label = labelInput?.value.trim() || 'Passkey';

    try {
        ensurePasskeySupport();
        if (addBtn) {
            addBtn.disabled = true;
            addBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding...';
        }

        const optionsData = await apiRequest('auth', 'passkey_register_options', {
            method: 'POST',
            data: { label }
        });
        const credential = await navigator.credentials.create({
            publicKey: preparePasskeyCreationOptions(optionsData.publicKey)
        });

        if (!credential) {
            showToast('No passkey was created.', 'warning');
            return;
        }

        const data = await apiRequest('auth', 'passkey_register_verify', {
            method: 'POST',
            data: passkeyCredentialPayload(credential)
        });

        state.passkeys = (data.passkeys || []).map(normalizePasskey);
        if (labelInput) labelInput.value = '';
        showToast('Passkey added to your account.', 'success');
        renderCurrentView();
    } catch (error) {
        showToast(passkeyErrorMessage(error), 'error');
    } finally {
        if (addBtn) {
            addBtn.disabled = false;
            addBtn.innerHTML = '<i class="fa-solid fa-plus"></i> Add Passkey';
        }
    }
}

function deletePasskey(passkeyId) {
    const passkey = state.passkeys.find(p => p.id === passkeyId);
    if (!passkey) return;

    showConfirm(
        'DELETE PASSKEY',
        `Remove "${passkey.label}" from your account? You can still sign in with your password and email code.`,
        'Delete Passkey',
        'btn-danger',
        async () => {
            try {
                const data = await apiRequest('auth', 'passkey_delete', {
                    method: 'POST',
                    data: { id: passkeyId }
                });
                state.passkeys = (data.passkeys || []).map(normalizePasskey);
                showToast('Passkey deleted.', 'warning');
                renderCurrentView();
            } catch (error) {
                showToast(error.message, 'error');
            }
        }
    );
}

async function verifyLoginOtp(code) {
    if (!state.loginChallenge?.token) {
        return { success: false, message: 'Please sign in again.' };
    }

    try {
        const data = await apiRequest('auth', 'verify_otp', {
            method: 'POST',
            data: {
                challenge_token: state.loginChallenge.token,
                code
            }
        });

        state.currentUser = normalizeUser(data.user);
        state.profilePictureVersion = Date.now();
        state.loginChallenge = null;
        await loadData();
        return { success: true };
    } catch (error) {
        return { success: false, message: error.message };
    }
}

async function resendLoginOtp() {
    if (!state.loginChallenge?.token) {
        return { success: false, message: 'Please sign in again.' };
    }

    try {
        const data = await apiRequest('auth', 'resend_otp', {
            method: 'POST',
            data: { challenge_token: state.loginChallenge.token }
        });

        return {
            success: true,
            resendAvailableIn: data?.resend_available_in || 60
        };
    } catch (error) {
        return { success: false, message: error.message };
    }
}

// Logout and clear session
async function logout() {
    try {
        await apiRequest('auth', 'logout', { method: 'POST' });
    } catch (error) {
        showToast(error.message, 'warning');
    }

    state.currentUser = null;
    state.loginChallenge = null;
    state.signupChallenge = null;
    state.currentView = 'dashboard';
    state.users = [];
    state.passkeys = [];
    state.students = [];
    state.auditLogs = [];
    state.profilePictureVersion = Date.now();
    showLoginPage();
}

// Check if a PHP session exists and restore it
async function checkSession() {
    try {
        const data = await apiRequest('auth', 'session');
        if (!data?.user) return false;

        state.currentUser = normalizeUser(data.user);
        state.profilePictureVersion = Date.now();
        await loadData();
        return true;
    } catch (error) {
        return false;
    }
}

let resendCountdownTimer = null;

function clearLoginError() {
    document.getElementById('login-error').classList.remove('show', 'success');
    document.getElementById('login-error-msg').textContent = '';
    document.getElementById('login-message-icon').className = 'fa-solid fa-circle-exclamation';
}

function showLoginError(message) {
    const box = document.getElementById('login-error');
    box.classList.remove('success');
    box.classList.add('show');
    document.getElementById('login-message-icon').className = 'fa-solid fa-circle-exclamation';
    document.getElementById('login-error-msg').textContent = message;
}

function showLoginSuccess(message) {
    const box = document.getElementById('login-error');
    box.classList.add('show', 'success');
    document.getElementById('login-message-icon').className = 'fa-solid fa-circle-check';
    document.getElementById('login-error-msg').textContent = message;
}

function setLoginHeading(title, subtitle) {
    document.querySelector('.login-form-title').textContent = title;
    document.getElementById('login-form-sub').textContent = subtitle;
}

function stopResendCountdown() {
    if (resendCountdownTimer) {
        clearInterval(resendCountdownTimer);
        resendCountdownTimer = null;
    }
}

function resetResendButton(buttonId) {
    const resendBtn = document.getElementById(buttonId);
    if (resendBtn) {
        resendBtn.disabled = false;
        resendBtn.textContent = 'Resend Code';
    }
}

function startResendCountdown(seconds, buttonId = 'otp-resend-btn') {
    stopResendCountdown();

    const resendBtn = document.getElementById(buttonId);
    if (!resendBtn) return;

    let remaining = Math.max(0, parseInt(seconds, 10) || 0);
    const shouldTick = remaining > 0;

    const render = () => {
        if (remaining > 0) {
            resendBtn.disabled = true;
            resendBtn.textContent = `Resend in ${remaining}s`;
            remaining -= 1;
            return;
        }

        stopResendCountdown();
        resendBtn.disabled = false;
        resendBtn.textContent = 'Resend Code';
    };

    render();
    if (shouldTick) {
        resendCountdownTimer = setInterval(render, 1000);
    }
}

function showOtpLoginStep(email, resendAvailableIn = 60) {
    clearLoginError();
    setLoginHeading('VERIFY LOGIN', 'Enter the code sent to your email to finish signing in.');
    document.getElementById('login-form').style.display = 'none';
    document.getElementById('otp-form').style.display = 'block';
    document.getElementById('signup-form').style.display = 'none';
    document.getElementById('signup-otp-form').style.display = 'none';
    document.getElementById('otp-email').textContent = email;
    document.getElementById('otp-code').value = '';
    document.getElementById('otp-code').focus();
    startResendCountdown(resendAvailableIn, 'otp-resend-btn');
}

function showPasswordLoginStep() {
    stopResendCountdown();
    clearLoginError();
    state.loginChallenge = null;
    state.signupChallenge = null;
    setLoginHeading('SIGN IN', 'Access your ASCT dashboard and student records.');
    document.getElementById('login-form').style.display = 'block';
    document.getElementById('otp-form').style.display = 'none';
    document.getElementById('signup-form').style.display = 'none';
    document.getElementById('signup-otp-form').style.display = 'none';
    document.getElementById('otp-code').value = '';
    document.getElementById('signup-otp-code').value = '';
    resetResendButton('otp-resend-btn');
    resetResendButton('signup-otp-resend-btn');
}

function showSignupStep() {
    stopResendCountdown();
    clearLoginError();
    state.loginChallenge = null;
    state.signupChallenge = null;
    setLoginHeading('STUDENT SIGNUP', 'Confirm your email, then request a student account for admin approval.');
    document.getElementById('login-form').style.display = 'none';
    document.getElementById('otp-form').style.display = 'none';
    document.getElementById('signup-form').style.display = 'block';
    document.getElementById('signup-otp-form').style.display = 'none';
    resetResendButton('signup-otp-resend-btn');
    document.getElementById('signup-name').focus();
}

function showSignupVerificationStep(email, resendAvailableIn = 60) {
    clearLoginError();
    setLoginHeading('CONFIRM EMAIL', 'Enter the code sent to your email to finish registration.');
    document.getElementById('login-form').style.display = 'none';
    document.getElementById('otp-form').style.display = 'none';
    document.getElementById('signup-form').style.display = 'none';
    document.getElementById('signup-otp-form').style.display = 'block';
    document.getElementById('signup-otp-email').textContent = email;
    document.getElementById('signup-otp-code').value = '';
    document.getElementById('signup-otp-code').focus();
    startResendCountdown(resendAvailableIn, 'signup-otp-resend-btn');
}

/* ============================================ */
/* 3. PERMISSION SYSTEM                         */
/* ============================================ */

// Returns true if the current user has the specified permission
function hasPermission(permission) {
    if (!state.currentUser) return false;
    const role = state.currentUser.role;
    const matrix = {
        add_student:      { admin: true,  teacher: false, student: false },
        view_all_students:{ admin: true,  teacher: true,  student: false },
        view_own_profile: { admin: true,  teacher: true,  student: true  },
        edit_students:    { admin: true,  teacher: true,  student: true  }, // limited by role in form
        soft_delete:      { admin: true,  teacher: true,  student: false },
        hard_delete:      { admin: true,  teacher: false, student: false },
        manage_users:     { admin: true,  teacher: false, student: false },
        view_audit_logs:  { admin: true,  teacher: false, student: false }
    };
    return matrix[permission] ? matrix[permission][role] : false;
}

// Check if current student user can edit a specific field
function canEditField(field) {
    const role = state.currentUser.role;
    if (role === 'admin') return true; // Admin can edit all fields
    if (role === 'teacher') return ['phone','address','course','year_level','status'].includes(field);
    if (role === 'student') return ['email','phone','address'].includes(field);
    return false;
}

/* ============================================ */
/* 4. NAVIGATION & ROUTING                      */
/* ============================================ */

// Define navigation items per role
const NAV_ITEMS = {
    admin: [
        { id: 'dashboard', label: 'Dashboard', icon: 'fa-solid fa-chart-pie' },
        { id: 'students',  label: 'Students',  icon: 'fa-solid fa-users' },
        { id: 'users',     label: 'Faculty',   icon: 'fa-solid fa-user-gear' },
        { id: 'approval',  label: 'Approval',  icon: 'fa-solid fa-user-check' },
        { id: 'audit',     label: 'Audit Log', icon: 'fa-solid fa-clock-rotate-left' },
        { id: 'profile',   label: 'Profile',   icon: 'fa-solid fa-id-card' },
        { id: 'security',  label: 'Security',  icon: 'fa-solid fa-shield-halved' }
    ],
    teacher: [
        { id: 'dashboard', label: 'Dashboard', icon: 'fa-solid fa-chart-pie' },
        { id: 'students',  label: 'Students',  icon: 'fa-solid fa-users' },
        { id: 'profile',   label: 'Profile',   icon: 'fa-solid fa-id-card' },
        { id: 'security',  label: 'Security',  icon: 'fa-solid fa-shield-halved' }
    ],
    student: [
        { id: 'dashboard', label: 'Dashboard', icon: 'fa-solid fa-chart-pie' },
        { id: 'profile',   label: 'Profile',   icon: 'fa-solid fa-id-card' },
        { id: 'security',  label: 'Security',  icon: 'fa-solid fa-shield-halved' }
    ]
};

// Navigate to a view
function navigateTo(viewId) {
    // Permission-based navigation guard
    if (viewId === 'students' && !hasPermission('view_all_students') && state.currentUser.role !== 'student') {
        showToast('Access denied. You do not have permission to view this section.', 'error');
        return;
    }
    if ((viewId === 'users' || viewId === 'approval') && !hasPermission('manage_users')) {
        showToast('Access denied. Admin access required.', 'error');
        return;
    }
    if (viewId === 'audit' && !hasPermission('view_audit_logs')) {
        showToast('Access denied. Admin access required.', 'error');
        return;
    }

    state.currentView = viewId;
    renderSidebar();
    renderCurrentView();
    updateHeaderTitle();

    // Close mobile sidebar
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebar-overlay').classList.remove('show');
}

// Update header page title
function updateHeaderTitle() {
    const titles = { dashboard: 'DASHBOARD', students: 'STUDENT RECORDS', users: 'FACULTY MANAGEMENT', approval: 'APPROVAL', audit: 'AUDIT LOG', profile: 'MY PROFILE', security: 'SECURITY' };
    document.getElementById('header-page-title').textContent = titles[state.currentView] || 'DASHBOARD';
}

/* ============================================ */
/* 5. UI COMPONENTS                             */
/* ============================================ */

// Toast notification system (no alert/prompt/confirm)
function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    const icons = { success: 'fa-circle-check', error: 'fa-circle-exclamation', warning: 'fa-triangle-exclamation', info: 'fa-circle-info' };
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    const icon = document.createElement('i');
    icon.className = `fa-solid ${icons[type] || icons.info}`;
    const text = document.createElement('span');
    text.className = 'toast-msg';
    text.textContent = message;
    const closeBtn = document.createElement('button');
    closeBtn.className = 'toast-close';
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Close notification');
    closeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
    closeBtn.addEventListener('click', () => {
        toast.classList.add('removing');
        setTimeout(() => toast.remove(), 300);
    });
    toast.append(icon, text, closeBtn);
    container.appendChild(toast);
    setTimeout(() => { toast.classList.add('removing'); setTimeout(() => toast.remove(), 300); }, 4000);
}

// Confirmation modal (replaces confirm())
let confirmCallback = null;
function showConfirm(title, message, btnText, btnClass, callback) {
    document.getElementById('confirm-title').textContent = title;
    document.getElementById('confirm-message').textContent = message;
    const btn = document.getElementById('confirm-btn');
    btn.textContent = btnText;
    btn.className = `btn ${btnClass} btn-sm`;
    confirmCallback = callback;
    document.getElementById('confirm-modal').classList.add('active');
}
function closeConfirm() {
    document.getElementById('confirm-modal').classList.remove('active');
    confirmCallback = null;
}

function openPendingApprovalsModal() {
    if (!hasPermission('manage_users')) {
        showToast('Admin access required.', 'error');
        return;
    }

    const pendingStudents = state.users.filter(u => u.role === 'student' && u.status === 'pending');
    document.getElementById('approval-modal-body').innerHTML = renderApprovalQueue(pendingStudents);
    document.getElementById('approval-modal').classList.add('active');
}

function closeApprovalModal() {
    document.getElementById('approval-modal').classList.remove('active');
    document.getElementById('approval-modal-body').innerHTML = '';
}

function openStatDetailModal(statType) {
    let title = '';
    let items = [];
    let countColor = 'var(--orange)';
    let mode = 'student'; // 'student' or 'user'

    switch (statType) {
        case 'stat-total-students':
            title = 'ALL STUDENTS';
            items = state.students;
            countColor = 'var(--orange)';
            break;
        case 'stat-active-students':
            title = 'ACTIVE STUDENTS';
            items = state.students.filter(s => s.status === 'active');
            countColor = 'var(--success)';
            break;
        case 'stat-inactive-students':
            title = 'INACTIVE STUDENTS';
            items = state.students.filter(s => s.status === 'inactive');
            countColor = 'var(--inactive-text)';
            break;
        case 'stat-total-faculty':
            title = 'ALL FACULTY';
            items = state.users;
            countColor = 'var(--silver)';
            mode = 'user';
            break;
        default:
            return;
    }

    document.getElementById('stat-detail-modal-title').textContent = title;
    document.getElementById('stat-detail-modal-body').innerHTML = renderStatDetailContent(items, mode, countColor);
    document.getElementById('stat-detail-modal').classList.add('active');
}

function closeStatDetailModal() {
    document.getElementById('stat-detail-modal').classList.remove('active');
    document.getElementById('stat-detail-modal-body').innerHTML = '';
}

function renderStatDetailContent(items, mode, countColor) {
    const count = items.length;

    if (count === 0) {
        return `
            <div class="empty-state" style="padding:32px 20px">
                <i class="fa-solid fa-${mode === 'user' ? 'user-gear' : 'users-slash'}"></i>
                <h3>NO RECORDS</h3>
                <p>No ${mode === 'user' ? 'faculty accounts' : 'student records'} found in this category.</p>
            </div>
        `;
    }

    let listHtml = '';
    if (mode === 'student') {
        listHtml = items.map(s => {
            const initials = escapeHtml(`${String(s.first_name ?? '').charAt(0)}${String(s.last_name ?? '').charAt(0)}`).toUpperCase();
            const status = s.status === 'inactive' ? 'inactive' : 'active';
            const statusClass = status === 'active' ? 'active' : 'inactive';
            return `
                <div class="stat-detail-item">
                    <div class="stat-detail-item-left">
                        <div class="stat-detail-avatar">${initials}</div>
                        <div class="stat-detail-info">
                            <div class="stat-detail-name">${escapeHtml(s.first_name)} ${escapeHtml(s.last_name)}</div>
                            <div class="stat-detail-sub">${escapeHtml(s.student_id)} &bull; ${escapeHtml(s.course)} &bull; Year ${escapeHtml(s.year_level)}</div>
                        </div>
                    </div>
                    <div class="stat-detail-right">
                        <span class="status-badge ${statusClass}">${escapeHtml(status)}</span>
                    </div>
                </div>
            `;
        }).join('');
    } else {
        listHtml = items.map(u => {
            const initials = escapeHtml(getInitials(u.name));
            const roleClass = safeClassToken(u.role, 'student');
            const statusClass = safeClassToken(u.status, 'active');
            return `
                <div class="stat-detail-item">
                    <div class="stat-detail-item-left">
                        <div class="stat-detail-avatar">${initials}</div>
                        <div class="stat-detail-info">
                            <div class="stat-detail-name">${escapeHtml(u.name)}</div>
                            <div class="stat-detail-sub">${escapeHtml(u.email)}</div>
                        </div>
                    </div>
                    <div class="stat-detail-right">
                        <span class="role-badge ${roleClass}">${escapeHtml(String(u.role ?? '').toUpperCase())}</span>
                        <span class="account-status-badge ${statusClass}">${escapeHtml(String(u.status ?? '').toUpperCase())}</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    return `
        <div class="stat-detail-count" style="color:${countColor}">${count}</div>
        <div class="stat-detail-count-label">${mode === 'user' ? 'Faculty Accounts' : 'Student Records'}</div>
        <div class="stat-detail-list">${listHtml}</div>
    `;
}

document.getElementById('confirm-btn').addEventListener('click', () => {
    if (confirmCallback) confirmCallback();
    closeConfirm();
});
document.querySelectorAll('[data-close-modal]').forEach(button => {
    button.addEventListener('click', () => {
        const target = button.dataset.closeModal;
        if (target === 'student') closeStudentModal();
        else if (target === 'user') closeUserModal();
        else if (target === 'approval') closeApprovalModal();
        else if (target === 'stat-detail') closeStatDetailModal();
        else closeConfirm();
    });
});

// Student modal
function openStudentModal(studentId) {
    state.editingStudentId = studentId;
    const isEdit = studentId !== null;
    const student = isEdit ? state.students.find(s => s.id === studentId) : null;
    const role = state.currentUser.role;

    document.getElementById('student-modal-title').textContent = isEdit ? 'EDIT STUDENT' : 'ADD STUDENT';

    // Build form fields, disabling those the user cannot edit
    const fields = [
        { key: 'student_id', label: 'Student ID', type: 'text', placeholder: 'ASCT-2024-XXX' },
        { key: 'first_name', label: 'First Name', type: 'text', placeholder: 'First name' },
        { key: 'last_name', label: 'Last Name', type: 'text', placeholder: 'Last name' },
        { key: 'email', label: 'Email', type: 'email', placeholder: 'email@asct.edu.ph' },
        { key: 'phone', label: 'Phone', type: 'text', placeholder: '555-0000' },
        { key: 'address', label: 'Address', type: 'text', placeholder: 'Street, City' },
        { key: 'course', label: 'Course', type: 'select', options: ['Computer Science','Engineering','Business Administration'] },
        { key: 'year_level', label: 'Year Level', type: 'select', options: [1,2,3,4] },
        { key: 'birthdate', label: 'Birthdate', type: 'date' },
        { key: 'status', label: 'Status', type: 'select', options: ['active','inactive'] }
    ];

    let html = '';
    // Row 1: student_id, first_name, last_name
    html += '<div class="form-row">';
    html += buildField(fields[0], student, isEdit, role);
    html += buildField(fields[1], student, isEdit, role);
    html += buildField(fields[2], student, isEdit, role);
    html += '</div>';
    // Row 2: email, phone
    html += '<div class="form-row">';
    html += buildField(fields[3], student, isEdit, role);
    html += buildField(fields[4], student, isEdit, role);
    html += '</div>';
    // Address full width
    html += buildField(fields[5], student, isEdit, role);
    // Row 3: course, year_level, birthdate
    html += '<div class="form-row">';
    html += buildField(fields[6], student, isEdit, role);
    html += buildField(fields[7], student, isEdit, role);
    html += '</div>';
    html += '<div class="form-row">';
    html += buildField(fields[8], student, isEdit, role);
    html += buildField(fields[9], student, isEdit, role);
    html += '</div>';

    document.getElementById('student-modal-body').innerHTML = html;
    document.getElementById('student-modal-save').textContent = isEdit ? 'Update Record' : 'Save Record';
    document.getElementById('student-modal').classList.add('active');
}

function buildField(field, student, isEdit, role) {
    const value = isEdit ? (student ? student[field.key] : '') : '';
    const disabled = isEdit && !canEditField(field.key);
    const reqMark = (!isEdit || canEditField(field.key)) ? '<span style="color:var(--orange)">*</span>' : '';
    const safeValue = escapeHtml(value);
    const safePlaceholder = escapeHtml(field.placeholder || '');
    let input = '';
    if (field.type === 'select') {
        input = `<select id="sf-${field.key}" ${disabled ? 'disabled' : ''} ${!disabled ? 'required' : ''}>`;
        if (!disabled) input += `<option value="">Select...</option>`;
        field.options.forEach(opt => {
            const sel = value == opt ? 'selected' : '';
            input += `<option value="${escapeHtml(opt)}" ${sel}>${escapeHtml(opt)}</option>`;
        });
        input += '</select>';
    } else {
        input = `<input type="${field.type}" id="sf-${field.key}" value="${safeValue}" placeholder="${safePlaceholder}" ${disabled ? 'disabled' : ''} ${!disabled && field.key !== 'address' ? 'required' : ''}>`;
    }
    return `<div class="form-group"><label for="sf-${field.key}">${field.label} ${reqMark}</label>${input}</div>`;
}

function splitFullName(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    return {
        first_name: parts[0] || '',
        last_name: parts.slice(1).join(' ')
    };
}

function buildApprovalField(field, values, disabled = false) {
    const value = values[field.key] || '';
    const safePlaceholder = escapeHtml(field.placeholder || '');
    let input = '';

    if (field.type === 'select') {
        input = `<select id="sf-${field.key}" ${disabled ? 'disabled' : ''} required>`;
        input += '<option value="">Select...</option>';
        field.options.forEach(opt => {
            const selected = value == opt ? 'selected' : '';
            input += `<option value="${escapeHtml(opt)}" ${selected}>${escapeHtml(opt)}</option>`;
        });
        input += '</select>';
    } else {
        const required = field.key === 'address' ? '' : 'required';
        input = `<input type="${field.type}" id="sf-${field.key}" value="${escapeHtml(value)}" placeholder="${safePlaceholder}" ${required} ${disabled ? 'disabled' : ''}>`;
    }

    return `<div class="form-group"><label for="sf-${field.key}">${field.label} ${field.key === 'address' ? '' : '<span style="color:var(--orange)">*</span>'}</label>${input}</div>`;
}

function openStudentApprovalModal(userId) {
    const user = state.users.find(u => u.id === userId);
    if (!user || user.role !== 'student' || user.status !== 'pending') {
        showToast('Pending student signup was not found.', 'error');
        return;
    }

    const nameParts = splitFullName(user.name);
    const values = {
        student_id: '',
        first_name: nameParts.first_name,
        last_name: nameParts.last_name,
        email: user.email,
        phone: '',
        address: '',
        course: '',
        year_level: '',
        birthdate: ''
    };

    state.approvingUserId = userId;
    state.editingStudentId = null;
    document.getElementById('student-modal-title').textContent = 'APPROVE STUDENT';

    const fields = [
        { key: 'student_id', label: 'Student ID', type: 'text', placeholder: 'ASCT-2024-XXX' },
        { key: 'first_name', label: 'First Name', type: 'text', placeholder: 'First name' },
        { key: 'last_name', label: 'Last Name', type: 'text', placeholder: 'Last name' },
        { key: 'email', label: 'Email', type: 'email', placeholder: 'email@asct.edu.ph' },
        { key: 'phone', label: 'Phone', type: 'text', placeholder: '555-0000' },
        { key: 'address', label: 'Address', type: 'text', placeholder: 'Street, City' },
        { key: 'course', label: 'Course', type: 'select', options: ['Computer Science','Engineering','Business Administration'] },
        { key: 'year_level', label: 'Year Level', type: 'select', options: [1,2,3,4] },
        { key: 'birthdate', label: 'Birthdate', type: 'date' }
    ];

    let html = '<div class="login-note" style="margin-bottom:20px">This email has been confirmed by the student. Assign the Student ID and complete the profile to activate the account.</div>';
    html += '<div class="form-row">';
    html += buildApprovalField(fields[0], values);
    html += buildApprovalField(fields[1], values);
    html += buildApprovalField(fields[2], values);
    html += '</div>';
    html += '<div class="form-row">';
    html += buildApprovalField(fields[3], values, true);
    html += buildApprovalField(fields[4], values);
    html += '</div>';
    html += buildApprovalField(fields[5], values);
    html += '<div class="form-row">';
    html += buildApprovalField(fields[6], values);
    html += buildApprovalField(fields[7], values);
    html += '</div>';
    html += buildApprovalField(fields[8], values);

    document.getElementById('student-modal-body').innerHTML = html;
    document.getElementById('student-modal-save').textContent = 'Approve Student';
    document.getElementById('student-modal').classList.add('active');
}

function closeStudentModal() {
    document.getElementById('student-modal').classList.remove('active');
    state.editingStudentId = null;
    state.approvingUserId = null;
}

// Save student (create or update)
document.getElementById('student-modal-save').addEventListener('click', async () => {
    const role = state.currentUser.role;
    const isEdit = state.editingStudentId !== null;
    const isApproval = state.approvingUserId !== null;
    const saveBtn = document.getElementById('student-modal-save');
    const defaultLabel = isApproval ? 'Approve Student' : (isEdit ? 'Update Record' : 'Save Record');

    // Permission check at logic level
    if (isApproval && !hasPermission('manage_users')) { showToast('Admin access required.', 'error'); return; }
    if (!isApproval && isEdit && !hasPermission('edit_students')) { showToast('You do not have permission to edit students.', 'error'); return; }
    if (!isApproval && !isEdit && !hasPermission('add_student')) { showToast('Only admins can add new students.', 'error'); return; }

    // Gather form values
    const getField = (key) => {
        const el = document.getElementById('sf-' + key);
        return el ? el.value.trim() : '';
    };

    // Validate required fields that are editable
    const editableFields = isApproval
        ? ['student_id','first_name','last_name','email','phone','course','year_level','birthdate']
        : ['student_id','first_name','last_name','email','phone','address','course','year_level','birthdate','status'].filter(f => canEditField(f) || !isEdit);
    let valid = true;
    for (const f of editableFields) {
        const el = document.getElementById('sf-' + f);
        if (el && el.required && !el.value.trim()) {
            el.closest('.form-group').classList.add('has-error');
            valid = false;
        } else if (el) {
            el.closest('.form-group').classList.remove('has-error');
        }
    }
    if (!valid) { showToast('Please fill in all required fields.', 'error'); return; }

    const payload = {
        student_id: getField('student_id'),
        first_name: getField('first_name'),
        last_name: getField('last_name'),
        email: getField('email'),
        phone: getField('phone'),
        address: getField('address'),
        course: getField('course'),
        year_level: parseInt(getField('year_level'), 10) || 1,
        birthdate: getField('birthdate'),
        status: getField('status') || 'active'
    };

    if (isEdit) {
        payload.id = state.editingStudentId;
    }
    if (isApproval) {
        payload.user_id = state.approvingUserId;
    }

    saveBtn.disabled = true;
    saveBtn.textContent = isApproval ? 'Approving...' : (isEdit ? 'Updating...' : 'Saving...');

    try {
        if (isApproval) {
            const data = await apiRequest('users', 'approve_student', { method: 'POST', data: payload });
            if (data?.user) upsertUser(data.user);
            if (data?.student) upsertStudent(data.student);
            showToast('Student account approved.', 'success');
        } else {
            const data = await apiRequest('students', isEdit ? 'update' : 'create', { method: 'POST', data: payload });
            upsertStudent(data);
            if (isEdit) await refreshAuditLogs();
            showToast(isEdit ? 'Student record updated successfully.' : 'New student record created successfully.', 'success');
        }
        closeStudentModal();
        renderCurrentView();
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = defaultLabel;
    }
});

// Faculty account modal
function openUserModal(userId) {
    state.editingUserId = userId;
    const isEdit = userId !== null;
    const user = isEdit ? state.users.find(u => u.id === userId) : null;

    if (!hasPermission('manage_users')) { showToast('Admin access required.', 'error'); return; }

    document.getElementById('user-modal-title').textContent = isEdit ? 'EDIT FACULTY' : 'ADD FACULTY';

    let html = `
        <div class="form-group"><label>Name <span style="color:var(--orange)">*</span></label><input type="text" id="uf-name" value="${isEdit ? escapeHtml(user.name) : ''}" placeholder="Full name" required></div>
        <div class="form-group"><label>Email <span style="color:var(--orange)">*</span></label><input type="email" id="uf-email" value="${isEdit ? escapeHtml(user.email) : ''}" placeholder="email@asct.edu.ph" required></div>
        <div class="form-group"><label>Password ${isEdit ? '' : '<span style="color:var(--orange)">*</span>'}</label><input type="password" id="uf-password" value="" placeholder="${isEdit ? 'Leave blank to keep current' : 'Enter password'}" ${isEdit ? '' : 'required'}></div>
        <div class="form-group"><label>Role <span style="color:var(--orange)">*</span></label>
            <select id="uf-role" required>
                <option value="">Select role...</option>
                <option value="admin" ${isEdit && user.role==='admin'?'selected':''}>Admin</option>
                <option value="teacher" ${isEdit && user.role==='teacher'?'selected':''}>Teacher</option>
                <option value="student" ${isEdit && user.role==='student'?'selected':''}>Student</option>
            </select>
        </div>
    `;
    document.getElementById('user-modal-body').innerHTML = html;
    document.getElementById('user-modal-save').textContent = isEdit ? 'Update Faculty' : 'Create Faculty';
    document.getElementById('user-modal').classList.add('active');
}

function closeUserModal() {
    document.getElementById('user-modal').classList.remove('active');
    state.editingUserId = null;
}

document.getElementById('user-modal-save').addEventListener('click', async () => {
    if (!hasPermission('manage_users')) { showToast('Admin access required.', 'error'); return; }

    const name = document.getElementById('uf-name').value.trim();
    const email = document.getElementById('uf-email').value.trim();
    const password = document.getElementById('uf-password').value;
    const role = document.getElementById('uf-role').value;
    const isEdit = state.editingUserId !== null;
    const saveBtn = document.getElementById('user-modal-save');

    if (!name || !email || !role) { showToast('Please fill in all required fields.', 'error'); return; }
    if (!isEdit && !password) { showToast('Password is required for new users.', 'error'); return; }

    const payload = { name, email, password, role };
    if (isEdit) payload.id = state.editingUserId;

    saveBtn.disabled = true;
    saveBtn.textContent = isEdit ? 'Updating...' : 'Creating...';

    try {
        const data = await apiRequest('users', isEdit ? 'update' : 'create', { method: 'POST', data: payload });
        upsertUser(data);
        showToast(isEdit ? 'Faculty account updated.' : 'New faculty account created.', 'success');
        closeUserModal();
        renderCurrentView();
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        saveBtn.disabled = false;
        saveBtn.textContent = isEdit ? 'Update Faculty' : 'Create Faculty';
    }
});

/* ============================================ */
/* 6. DELETE OPERATIONS                         */
/* ============================================ */

// Soft delete: mark student as inactive with timestamp
function softDeleteStudent(studentId) {
    if (!hasPermission('soft_delete')) { showToast('You do not have permission to delete students.', 'error'); return; }
    const student = state.students.find(s => s.id === studentId);
    if (!student) return;

    showConfirm(
        'SOFT DELETE',
        `Mark "${student.first_name} ${student.last_name}" as inactive? The record will be preserved but the student status will change to Inactive.`,
        'Soft Delete',
        'btn-danger',
        async () => {
            try {
                const data = await apiRequest('students', 'soft_delete', { method: 'POST', data: { id: studentId } });
                upsertStudent(data);
                await refreshAuditLogs();
                showToast(`${student.first_name} ${student.last_name} has been deactivated.`, 'warning');
                renderCurrentView();
            } catch (error) {
                showToast(error.message, 'error');
            }
        }
    );
}

// Hard delete: permanently remove from data
function hardDeleteStudent(studentId) {
    if (!hasPermission('hard_delete')) { showToast('Only admins can permanently delete records.', 'error'); return; }
    const student = state.students.find(s => s.id === studentId);
    if (!student) return;

    showConfirm(
        'PERMANENT DELETE',
        `Permanently delete "${student.first_name} ${student.last_name}"? This action CANNOT be undone. The record will be completely removed from the system.`,
        'Hard Delete',
        'btn-danger',
        async () => {
            try {
                await apiRequest('students', 'hard_delete', { method: 'POST', data: { id: studentId } });
                removeStudent(studentId);
                await refreshAuditLogs();
                showToast(`${student.first_name} ${student.last_name} has been permanently deleted.`, 'error');
                renderCurrentView();
            } catch (error) {
                showToast(error.message, 'error');
            }
        }
    );
}

// Delete faculty account (admin only)
function deleteUser(userId) {
    if (!hasPermission('manage_users')) { showToast('Admin access required.', 'error'); return; }
    const user = state.users.find(u => u.id === userId);
    if (!user) return;
    if (user.id === state.currentUser.id) { showToast('You cannot delete your own account.', 'error'); return; }

    showConfirm(
        'DELETE FACULTY',
        `Permanently delete faculty account "${user.name}"? This cannot be undone.`,
        'Delete Faculty',
        'btn-danger',
        async () => {
            try {
                await apiRequest('users', 'delete', { method: 'POST', data: { id: userId } });
                removeUser(userId);
                await refreshAuditLogs();
                showToast(`Faculty account "${user.name}" has been deleted.`, 'warning');
                renderCurrentView();
            } catch (error) {
                showToast(error.message, 'error');
            }
        }
    );
}

function rejectStudentSignup(userId) {
    if (!hasPermission('manage_users')) { showToast('Admin access required.', 'error'); return; }
    const user = state.users.find(u => u.id === userId);
    if (!user || user.role !== 'student' || user.status !== 'pending') {
        showToast('Pending student signup was not found.', 'error');
        return;
    }

    showConfirm(
        'REJECT SIGNUP',
        `Reject the student account request from "${user.name}"? The pending account will be removed.`,
        'Reject',
        'btn-danger',
        async () => {
            try {
                await apiRequest('users', 'reject_student', { method: 'POST', data: { user_id: userId } });
                removeUser(userId);
                await refreshAuditLogs();
                showToast(`Signup request from "${user.name}" was rejected.`, 'warning');
                renderCurrentView();
            } catch (error) {
                showToast(error.message, 'error');
            }
        }
    );
}

/* ============================================ */
/* 7. RENDER FUNCTIONS                          */
/* ============================================ */

// Render sidebar navigation based on role
function renderSidebar() {
    const nav = document.getElementById('sidebar-nav');
    const items = NAV_ITEMS[state.currentUser.role] || [];
    nav.innerHTML = items.map(item => `
        <li><a href="#" class="${state.currentView === item.id ? 'active' : ''}" data-nav="${item.id}"><i class="${item.icon}"></i> ${item.label}</a></li>
    `).join('');
}

// Render the current view
function renderCurrentView() {
    // Hide all sections
    document.querySelectorAll('.content-section').forEach(s => { s.classList.remove('active'); s.innerHTML = ''; });
    const section = document.getElementById('view-' + state.currentView);
    if (!section) return;

    switch (state.currentView) {
        case 'dashboard': section.innerHTML = renderDashboard(); break;
        case 'students': section.innerHTML = renderStudentsView(); break;
        case 'users': section.innerHTML = renderUsersView(); break;
        case 'approval': section.innerHTML = renderApprovalView(); break;
        case 'audit': section.innerHTML = renderAuditLogView(); break;
        case 'profile': section.innerHTML = renderProfileView(); break;
        case 'security': section.innerHTML = renderSecurityView(); break;
    }
    section.classList.add('active');
}

/* ============================================ */
/* 7a. DASHBOARD RENDERING                      */
/* ============================================ */

function renderDashboard() {
    const role = state.currentUser.role;
    const students = state.students;
    const activeCount = students.filter(s => s.status === 'active').length;
    const inactiveCount = students.filter(s => s.status === 'inactive').length;
    const totalCount = students.length;
    const userCount = state.users.length;
    const pendingUserCount = state.users.filter(u => u.role === 'student' && u.status === 'pending').length;

    if (role === 'admin') return renderAdminDashboard(totalCount, activeCount, inactiveCount, userCount, pendingUserCount);
    if (role === 'teacher') return renderTeacherDashboard(totalCount, activeCount, inactiveCount);
    return renderStudentDashboard();
}

function renderAdminDashboard(total, active, inactive, userCount, pendingUserCount) {
    return `
        <div class="dashboard-view dashboard-admin">
            <div class="hero-banner fade-in">
                <div class="hero-banner-content">
                    <h1>ADMIN <span>DASHBOARD</span></h1>
                    <p>Manage ASCT student records, faculty accounts, and enrollment information from one dashboard.</p>
                    <div class="hero-accent">ADMINISTRATIVE OVERVIEW</div>
                </div>
            </div>

        <div class="stats-grid">
            <div class="stat-card accent fade-in-up stagger-1" data-action="stat-total-students" role="button" tabindex="0" aria-label="View total students">
                <div class="stat-label">Total Students</div>
                <div class="stat-value" style="color:var(--orange)">${total}</div>
                <i class="fa-solid fa-users stat-icon"></i>
            </div>
            <div class="stat-card fade-in-up stagger-2" data-action="stat-active-students" role="button" tabindex="0" aria-label="View active students">
                <div class="stat-label">Active Students</div>
                <div class="stat-value" style="color:var(--success)">${active}</div>
                <i class="fa-regular fa-user stat-icon"></i>
            </div>
            <div class="stat-card fade-in-up stagger-3" data-action="stat-inactive-students" role="button" tabindex="0" aria-label="View inactive students">
                <div class="stat-label">Inactive Students</div>
                <div class="stat-value" style="color:var(--inactive-text)">${inactive}</div>
                <i class="fa-regular fa-user stat-icon"></i>
            </div>
            <div class="stat-card silver fade-in-up stagger-4" data-action="stat-total-faculty" role="button" tabindex="0" aria-label="View total faculty">
                <div class="stat-label">Total Faculty</div>
                <div class="stat-value">${userCount}</div>
                <i class="fa-solid fa-shield-halved stat-icon"></i>
            </div>
            <div class="stat-card fade-in-up stagger-5" data-action="open-pending-approvals" role="button" tabindex="0" aria-label="Open pending approvals">
                <div class="stat-label">Pending Approvals</div>
                <div class="stat-value" style="color:var(--warning)">${pendingUserCount}</div>
                <i class="fa-regular fa-user stat-icon"></i>
            </div>
        </div>

        <div class="quick-actions">
            <div class="action-card fade-in-up stagger-1" data-action="add-student">
                <div class="action-icon"><i class="fa-solid fa-user-plus"></i></div>
                <div><div class="action-text">Add Student</div><div class="action-sub">Register new record</div></div>
            </div>
            <div class="action-card fade-in-up stagger-2" data-action="nav-students">
                <div class="action-icon"><i class="fa-solid fa-table-list"></i></div>
                <div><div class="action-text">Manage Students</div><div class="action-sub">View and edit records</div></div>
            </div>
            <div class="action-card fade-in-up stagger-3" data-action="nav-users">
                <div class="action-icon"><i class="fa-solid fa-user-shield"></i></div>
                <div><div class="action-text">Manage Faculty</div><div class="action-sub">Accounts and roles</div></div>
            </div>
            <div class="action-card fade-in-up stagger-4" data-action="nav-approval">
                <div class="action-icon"><i class="fa-solid fa-user-clock"></i></div>
                <div><div class="action-text">Review Approvals</div><div class="action-sub">${pendingUserCount} pending requests</div></div>
            </div>
            <div class="action-card fade-in-up stagger-5" data-action="nav-profile">
                <div class="action-icon"><i class="fa-solid fa-id-card"></i></div>
                <div><div class="action-text">View Profile</div><div class="action-sub">Your account details</div></div>
            </div>
        </div>

        ${renderProgramsSection()}
        </div>
    `;
}

function renderTeacherDashboard(total, active, inactive) {
    return `
        <div class="dashboard-view dashboard-compact dashboard-teacher">
            <div class="hero-banner fade-in">
                <div class="hero-banner-content">
                    <h1>FACULTY <span>DASHBOARD</span></h1>
                    <p>Monitor student records, update academic details, and keep advising information current.</p>
                    <div class="hero-accent">TEACHER OVERVIEW</div>
                </div>
            </div>

        <div class="stats-grid">
            <div class="stat-card accent fade-in-up stagger-1" data-action="stat-total-students" role="button" tabindex="0" aria-label="View total students">
                <div class="stat-label">Total Students</div>
                <div class="stat-value" style="color:var(--orange)">${total}</div>
                <i class="fa-solid fa-users stat-icon"></i>
            </div>
            <div class="stat-card fade-in-up stagger-2" data-action="stat-active-students" role="button" tabindex="0" aria-label="View active students">
                <div class="stat-label">Active Students</div>
                <div class="stat-value" style="color:var(--success)">${active}</div>
                <i class="fa-regular fa-user stat-icon"></i>
            </div>
            <div class="stat-card fade-in-up stagger-3" data-action="stat-inactive-students" role="button" tabindex="0" aria-label="View inactive students">
                <div class="stat-label">Inactive Students</div>
                <div class="stat-value" style="color:var(--inactive-text)">${inactive}</div>
                <i class="fa-regular fa-user stat-icon"></i>
            </div>
        </div>

        <div class="quick-actions">
            <div class="action-card fade-in-up stagger-1" data-action="nav-students">
                <div class="action-icon"><i class="fa-solid fa-table-list"></i></div>
                <div><div class="action-text">View Students</div><div class="action-sub">Browse all records</div></div>
            </div>
            <div class="action-card fade-in-up stagger-2" data-action="nav-students">
                <div class="action-icon"><i class="fa-solid fa-pen-to-square"></i></div>
                <div><div class="action-text">Update Student Info</div><div class="action-sub">Edit contact and course</div></div>
            </div>
            <div class="action-card fade-in-up stagger-3" data-action="nav-students">
                <div class="action-icon"><i class="fa-solid fa-user-xmark"></i></div>
                <div><div class="action-text">Soft Delete Students</div><div class="action-sub">Mark inactive</div></div>
            </div>
            <div class="action-card fade-in-up stagger-4" data-action="nav-profile">
                <div class="action-icon"><i class="fa-solid fa-id-card"></i></div>
                <div><div class="action-text">View Profile</div><div class="action-sub">Your account details</div></div>
            </div>
        </div>

        ${renderStaffSection()}
        </div>
    `;
}

function renderStudentDashboard() {
    // Find the student record linked to the current user
    const student = findOwnStudent();
    if (!student) {
        return `
            <div class="dashboard-view dashboard-compact dashboard-student">
            <div class="hero-banner fade-in">
                <div class="hero-banner-content">
                    <h1>YOUR <span>PORTAL</span></h1>
                    <p>Welcome to your personal dashboard. Your student record is not yet linked — contact administration.</p>
                </div>
            </div>
            </div>
        `;
    }

    const firstName = escapeHtml(student.first_name);
    const lastName = escapeHtml(student.last_name);
    const firstNameUpper = escapeHtml(String(student.first_name ?? '').toUpperCase());
    const status = student.status === 'inactive' ? 'inactive' : 'active';
    const statusText = escapeHtml(String(status).toUpperCase());
    const course = escapeHtml(student.course);
    const yearLevel = escapeHtml(student.year_level);
    const studentId = escapeHtml(student.student_id);
    const email = escapeHtml(student.email);
    const phone = escapeHtml(student.phone);
    const address = escapeHtml(student.address);
    const birthdate = escapeHtml(student.birthdate);
    const initials = escapeHtml(`${String(student.first_name ?? '').charAt(0)}${String(student.last_name ?? '').charAt(0)}`);

    return `
        <div class="dashboard-view dashboard-compact dashboard-student">
        <div class="hero-banner fade-in">
            <div class="hero-banner-content">
                <h1>WELCOME, <span>${firstNameUpper}</span></h1>
                <p>View your records, academic details, and contact information in one place.</p>
                <div class="hero-accent">STUDENT PORTAL</div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card accent fade-in-up stagger-1">
                <div class="stat-label">Your Status</div>
                <div class="stat-value" style="font-size:1.8rem;color:${status==='active'?'var(--success)':'var(--inactive-text)'}">${statusText}</div>
            </div>
            <div class="stat-card fade-in-up stagger-2">
                <div class="stat-label">Program</div>
                <div class="stat-value" style="font-size:1.4rem">${course}</div>
            </div>
            <div class="stat-card silver fade-in-up stagger-3">
                <div class="stat-label">Year Level</div>
                <div class="stat-value">${yearLevel}</div>
            </div>
        </div>

        <div class="student-dashboard-grid">
            <div class="student-profile-card fade-in-up stagger-2">
                <div class="student-profile-card-header">
                    <div class="student-profile-card-avatar">${initials}</div>
                    <div class="student-profile-card-info">
                        <h3>${firstName} ${lastName}</h3>
                        <p>${studentId} &bull; ${course}</p>
                    </div>
                </div>
                <div class="student-profile-card-body">
                    <div class="student-profile-detail">
                        <div class="spd-item"><label>Email</label><span>${email}</span></div>
                        <div class="spd-item"><label>Phone</label><span>${phone}</span></div>
                        <div class="spd-item"><label>Address</label><span>${address}</span></div>
                        <div class="spd-item"><label>Birthdate</label><span>${birthdate}</span></div>
                    </div>
                </div>
            </div>
            <div class="student-dashboard-actions">
                <div class="action-card fade-in-up stagger-3" data-action="edit-own-student">
                    <div class="action-icon"><i class="fa-regular fa-pen-to-square"></i></div>
                    <div><div class="action-text">Update My Details</div><div class="action-sub">Edit email, phone, address</div></div>
                </div>
                <div class="action-card fade-in-up stagger-4" data-action="nav-profile">
                    <div class="action-icon"><i class="fa-regular fa-address-card"></i></div>
                    <div><div class="action-text">View Full Profile</div><div class="action-sub">Complete account details</div></div>
                </div>
                <div class="cta-banner student-dashboard-note fade-in-up stagger-5">
                    <h2>KEEP DETAILS CURRENT</h2>
                    <p>Review your profile and update your contact information when it changes.</p>
                </div>
            </div>
        </div>

        ${renderStoriesSection()}
        </div>
    `;
}

function getInitials(name) {
    const initials = String(name || '')
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map(part => part[0])
        .join('')
        .toUpperCase();

    return initials || 'AS';
}

function profilePictureUrl() {
    const version = state.currentUser?.profile_picture_version || state.profilePictureVersion || Date.now();
    return `api/profile_picture.php?action=view&v=${encodeURIComponent(version)}`;
}

function renderAvatarContent(initials, altText) {
    const safeInitials = escapeHtml(initials);
    const safeAlt = escapeHtml(altText || 'Profile picture');
    const imageHtml = state.currentUser?.profile_picture_version
        ? `<img class="avatar-img" src="${profilePictureUrl()}" alt="${safeAlt}" hidden>`
        : '';

    return `
        <span class="avatar-fallback">${safeInitials}</span>
        ${imageHtml}
    `;
}

// Programs/Courses section
function renderProgramsSection() {
    const rangeOptions = {
        '3y': { label: '3 Years', years: 3 },
        '5y': { label: '5 Years', years: 5 },
        all: { label: 'All', years: null }
    };
    const selectedRange = rangeOptions[state.enrollmentTrendRange] ? state.enrollmentTrendRange : '3y';
    const selectedOption = rangeOptions[selectedRange];
    const parseEnrollmentDate = value => {
        if (!value) return null;

        const normalized = String(value).trim().replace(' ', 'T');
        const date = new Date(normalized);

        return Number.isNaN(date.getTime()) ? null : date;
    };
    const trendStats = state.students.reduce((stats, student) => {
        const date = parseEnrollmentDate(student.created_at);
        if (!date) {
            return stats;
        }

        const key = String(date.getFullYear());
        if (!stats.has(key)) {
            stats.set(key, { key, year: date.getFullYear(), count: 0 });
        }

        stats.get(key).count += 1;
        return stats;
    }, new Map());

    const totalStudents = state.students.length;
    const datedRows = [...trendStats.values()].sort((a, b) => a.year - b.year);

    if (totalStudents === 0) {
        return `
            <div class="section-panel fade-in">
                <div class="section-panel-header">
                    <div><h2>ENROLLMENT <span>TREND</span></h2><div class="section-sub">No enrollee records available yet</div></div>
                </div>
                <div class="enrollment-trend-empty">Enrollment trend data will appear once student records are added.</div>
            </div>
        `;
    }

    let trendRows;
    if (datedRows.length === 0) {
        trendRows = [{ key: 'current', label: 'Current', count: totalStudents }];
    } else {
        const firstAvailableYear = datedRows[0].year;
        const lastYear = datedRows[datedRows.length - 1].year;
        const firstYear = selectedOption.years === null
            ? firstAvailableYear
            : lastYear - selectedOption.years + 1;
        const years = [];

        for (let year = firstYear; year <= lastYear; year += 1) {
            const key = String(year);
            years.push({
                key,
                label: key,
                count: trendStats.get(key)?.count || 0
            });
        }

        trendRows = years;
    }

    if (trendRows.length === 1 && trendRows[0].key !== 'current') {
        const previousYear = String(Number(trendRows[0].key) - 1);
        trendRows = [
            { key: previousYear, label: previousYear, count: 0 },
            trendRows[0]
        ];
    }

    const chartWidth = 640;
    const chartHeight = 220;
    const chartLeft = 42;
    const chartRight = 618;
    const chartTop = 22;
    const chartBottom = 176;
    const plotWidth = chartRight - chartLeft;
    const plotHeight = chartBottom - chartTop;
    const maxCount = Math.max(...trendRows.map(row => row.count), 1);
    const labelEvery = trendRows.length <= 12 ? 1 : trendRows.length <= 30 ? 2 : 5;
    const points = trendRows.map((row, index) => {
        const x = trendRows.length === 1 ? chartLeft + (plotWidth / 2) : chartLeft + ((plotWidth / (trendRows.length - 1)) * index);
        const y = chartBottom - ((row.count / maxCount) * plotHeight);
        return {
            ...row,
            x: Math.round(x),
            y: Math.round(y),
            showLabel: index === 0 || index === trendRows.length - 1 || index % labelEvery === 0
        };
    });
    const linePoints = points.map(point => `${point.x},${point.y}`).join(' ');
    const areaPoints = `${chartLeft},${chartBottom} ${linePoints} ${chartRight},${chartBottom}`;
    const peak = trendRows.reduce((best, row) => row.count > best.count ? row : best, trendRows[0]);
    const latest = trendRows[trendRows.length - 1];

    return `
        <div class="section-panel fade-in" id="enrollment-trend-panel">
            <div class="section-panel-header">
                <div><h2>ENROLLMENT <span>TREND</span></h2><div class="section-sub">New enrollee records by year - ${selectedOption.label}</div></div>
                <div class="program-chart-summary">
                    <span>${totalStudents} total enrollees</span>
                    <strong>${latest.count} latest</strong>
                    <label class="sr-only" for="enrollment-trend-range">Enrollment trend range</label>
                    <select id="enrollment-trend-range" aria-label="Enrollment trend range">
                        ${Object.entries(rangeOptions).map(([value, option]) => `<option value="${value}" ${value === selectedRange ? 'selected' : ''}>${option.label}</option>`).join('')}
                    </select>
                </div>
            </div>
            <div class="enrollment-trend-card">
                <div class="enrollment-trend-plot">
                    <svg class="enrollment-trend-svg" viewBox="0 0 ${chartWidth} ${chartHeight}" role="img" aria-label="Enrollment trend chart">
                        <line class="trend-grid" x1="${chartLeft}" y1="${chartTop}" x2="${chartRight}" y2="${chartTop}"></line>
                        <line class="trend-grid" x1="${chartLeft}" y1="${Math.round(chartTop + (plotHeight / 2))}" x2="${chartRight}" y2="${Math.round(chartTop + (plotHeight / 2))}"></line>
                        <line class="trend-grid" x1="${chartLeft}" y1="${chartBottom}" x2="${chartRight}" y2="${chartBottom}"></line>
                        <text class="trend-axis-label" x="10" y="${chartTop + 4}">${maxCount}</text>
                        <text class="trend-axis-label" x="10" y="${Math.round(chartTop + (plotHeight / 2)) + 4}">${Math.round(maxCount / 2)}</text>
                        <text class="trend-axis-label" x="18" y="${chartBottom + 4}">0</text>
                        <polygon class="trend-area" points="${areaPoints}"></polygon>
                        <polyline class="trend-line" points="${linePoints}"></polyline>
                        ${points.map(point => `<circle class="trend-point" cx="${point.x}" cy="${point.y}" r="4"></circle>`).join('')}
                    </svg>
                    <div class="trend-labels">
                        ${points.map(point => `<span class="${point.showLabel ? '' : 'is-muted'}">${point.showLabel ? escapeHtml(point.label) : '&nbsp;'}</span>`).join('')}
                    </div>
                </div>
                <div class="trend-insights">
                    <div><span>Peak Year</span><strong>${escapeHtml(peak.label)} - ${peak.count}</strong></div>
                    <div><span>Latest Year</span><strong>${escapeHtml(latest.label)} - ${latest.count}</strong></div>
                </div>
            </div>
        </div>
    `;
}

// Staff/Teacher profiles section
function renderStaffSection() {
    const teachers = state.users.filter(u => u.role === 'teacher');
    return `
        <div class="section-panel fade-in" style="margin-top:32px">
            <div class="section-panel-header">
                <div><h2>ASCT <span>FACULTY</span></h2><div class="section-sub">Academic staff supporting student records and advising</div></div>
            </div>
            <div class="staff-grid">
                ${teachers.map((t, i) => `
                    <div class="staff-card fade-in-up stagger-${i+1}">
                        <div class="staff-card-img" aria-hidden="true">
                            <div class="staff-avatar-badge">${escapeHtml(getInitials(t.name))}</div>
                        </div>
                        <div class="staff-card-body">
                            <h4>${escapeHtml(String(t.name ?? '').toUpperCase())}</h4>
                            <div class="staff-role">Faculty</div>
                            <div class="staff-detail">${escapeHtml(t.email)}</div>
                        </div>
                    </div>
                `).join('')}
            </div>
        </div>
    `;
}

// Student success stories
function renderStoriesSection() {
    const stories = [
        { name: 'Sarah Chen', course: 'Engineering, Year 2', quote: 'The academic support I received helped me grow from learning fundamentals to leading project teams.' },
        { name: 'Derek Kim', course: 'Computer Science, Year 4', quote: 'ASCT taught me to solve problems clearly, collaborate with classmates, and build systems with confidence.' },
        { name: 'Marcus Williams', course: 'Business Administration, Year 4', quote: 'The standards here pushed me to lead with accountability and prepare for real work beyond the classroom.' }
    ];
    return `
        <div class="section-panel fade-in" style="margin-top:32px">
            <div class="section-panel-header">
                <div><h2>STUDENT <span>SPOTLIGHT</span></h2><div class="section-sub">Student stories from the ASCT community</div></div>
            </div>
            <div class="stories-grid">
                ${stories.map((s, i) => `
                    <div class="story-card fade-in-up stagger-${i+1}">
                        <div class="story-card-img" aria-hidden="true">
                            <div class="story-avatar-badge">${escapeHtml(getInitials(s.name))}</div>
                        </div>
                        <div class="story-card-body">
                            <h4>${escapeHtml(String(s.name ?? '').toUpperCase())}</h4>
                            <div class="story-course">${escapeHtml(s.course)}</div>
                            <p>"${escapeHtml(s.quote)}"</p>
                        </div>
                    </div>
                `).join('')}
            </div>
        </div>
    `;
}

/* ============================================ */
/* 7b. STUDENTS VIEW RENDERING                  */
/* ============================================ */

function renderStudentsView() {
    // Students can only see their own data via dashboard/profile, not this list view
    if (state.currentUser.role === 'student') {
        return `<div class="empty-state fade-in"><i class="fa-solid fa-lock"></i><h3>ACCESS RESTRICTED</h3><p>Students cannot access the full student directory. View your own information from the Dashboard or Profile.</p></div>`;
    }

    const courses = [...new Set(state.students.map(s => s.course))];
    const isAdmin = state.currentUser.role === 'admin';

    return `
        <div class="hero-banner fade-in">
            <div class="hero-banner-content">
                <h1>STUDENT <span>RECORDS</span></h1>
                <p>Complete roster. Full control. Every record at your command.</p>
            </div>
        </div>

        <div class="table-controls fade-in-up">
            <div class="search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="student-search" placeholder="Search by name, ID, email, or course..." aria-label="Search students">
            </div>
            <select id="filter-status" aria-label="Filter by status">
                <option value="all">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <select id="filter-course" aria-label="Filter by course">
                <option value="all">All Courses</option>
                ${courses.map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('')}
            </select>
            <select id="filter-year" aria-label="Filter by year">
                <option value="all">All Years</option>
                <option value="1">Year 1</option>
                <option value="2">Year 2</option>
                <option value="3">Year 3</option>
                <option value="4">Year 4</option>
            </select>
            <button class="btn btn-export btn-sm" data-action="export-students"><i class="fa-solid fa-file-excel"></i> Export Excel</button>
            ${isAdmin ? '<button class="btn btn-primary btn-sm" data-action="add-student"><i class="fa-solid fa-plus"></i> Add Student</button>' : ''}
        </div>

        <div class="table-wrapper fade-in-up" id="students-table-wrapper">
            ${renderStudentTable(state.students)}
        </div>
    `;
}

function renderStudentTable(students) {
    if (students.length === 0) {
        return `<div class="empty-state"><i class="fa-solid fa-users-slash"></i><h3>NO RECORDS FOUND</h3><p>No student records match your current filters.</p></div>`;
    }

    const role = state.currentUser.role;
    const canSoftDel = hasPermission('soft_delete');
    const canHardDel = hasPermission('hard_delete');
    const canEdit = hasPermission('edit_students');

    return `
        <table class="data-table">
            <thead>
                <tr>
                    <th>Student ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Course</th><th>Year</th><th>Status</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
                ${students.map(s => {
                    const status = s.status === 'inactive' ? 'inactive' : 'active';
                    return `
                    <tr class="${status === 'inactive' ? 'inactive-row' : ''}">
                        <td style="font-weight:600;color:var(--silver)">${escapeHtml(s.student_id)}</td>
                        <td style="font-weight:600">${escapeHtml(s.first_name)} ${escapeHtml(s.last_name)}</td>
                        <td>${escapeHtml(s.email)}</td>
                        <td>${escapeHtml(s.phone)}</td>
                        <td>${escapeHtml(s.course)}</td>
                        <td>${escapeHtml(s.year_level)}</td>
                        <td><span class="status-badge ${status}">${escapeHtml(status)}</span></td>
                        <td>
                            <div class="table-actions">
                                ${canEdit ? `<button class="btn btn-ghost btn-sm btn-icon" data-action="edit-student" data-id="${s.id}" title="Edit"><i class="fa-solid fa-pen"></i></button>` : ''}
                                ${canSoftDel && status === 'active' ? `<button class="btn btn-secondary btn-sm btn-icon" data-action="soft-delete" data-id="${s.id}" title="Soft Delete"><i class="fa-solid fa-user-xmark"></i></button>` : ''}
                                ${canHardDel ? `<button class="btn btn-danger btn-sm btn-icon" data-action="hard-delete" data-id="${s.id}" title="Hard Delete"><i class="fa-solid fa-trash"></i></button>` : ''}
                            </div>
                        </td>
                    </tr>
                `}).join('')}
            </tbody>
        </table>
    `;
}

// Filter and search students table (called on input change)
function filterStudentTable() {
    const search = (document.getElementById('student-search')?.value || '').toLowerCase();
    const statusFilter = document.getElementById('filter-status')?.value || 'all';
    const courseFilter = document.getElementById('filter-course')?.value || 'all';
    const yearFilter = document.getElementById('filter-year')?.value || 'all';

    let filtered = state.students.filter(s => {
        const matchSearch = !search ||
            s.first_name.toLowerCase().includes(search) ||
            s.last_name.toLowerCase().includes(search) ||
            s.student_id.toLowerCase().includes(search) ||
            s.email.toLowerCase().includes(search) ||
            s.course.toLowerCase().includes(search);
        const matchStatus = statusFilter === 'all' || s.status === statusFilter;
        const matchCourse = courseFilter === 'all' || s.course === courseFilter;
        const matchYear = yearFilter === 'all' || s.year_level === parseInt(yearFilter);
        return matchSearch && matchStatus && matchCourse && matchYear;
    });

    const wrapper = document.getElementById('students-table-wrapper');
    if (wrapper) wrapper.innerHTML = renderStudentTable(filtered);
}

function getStudentFilterValues() {
    return {
        search: (document.getElementById('student-search')?.value || '').trim(),
        status: document.getElementById('filter-status')?.value || 'all',
        course: document.getElementById('filter-course')?.value || 'all',
        year: document.getElementById('filter-year')?.value || 'all'
    };
}

function filenameFromContentDisposition(value, fallback) {
    if (!value) return fallback;

    const encodedMatch = value.match(/filename\*=UTF-8''([^;]+)/i);
    if (encodedMatch) {
        try {
            return decodeURIComponent(encodedMatch[1].replace(/"/g, ''));
        } catch (error) {
            return fallback;
        }
    }

    const plainMatch = value.match(/filename="?([^"]+)"?/i);
    return plainMatch ? plainMatch[1] : fallback;
}

function formatStudentFilterSummary(filters) {
    const search = filters?.search ? filters.search : 'All';
    const status = filters?.status && filters.status !== 'all' ? filters.status : 'All';
    const course = filters?.course && filters.course !== 'all' ? filters.course : 'All Courses';
    const year = filters?.year && filters.year !== 'all' ? `Year ${filters.year}` : 'All Years';

    return `Search: ${search} | Status: ${status} | Course: ${course} | Year: ${year}`;
}

async function exportStudentsToExcel(button) {
    if (!hasPermission('view_all_students')) {
        showToast('You do not have permission to export student records.', 'error');
        return;
    }

    const csrfToken = getCsrfToken();
    if (!csrfToken) {
        showToast('Security token is missing. Refresh the page and try again.', 'error');
        return;
    }

    const originalHtml = button.innerHTML;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Exporting';

    try {
        const response = await fetch('api/students.php?action=export_excel', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                action: 'export_excel',
                csrf_token: csrfToken,
                filters: getStudentFilterValues()
            })
        });

        const contentType = response.headers.get('Content-Type') || '';
        if (!response.ok || contentType.includes('application/json')) {
            const payload = await response.json().catch(() => null);
            if (payload?.csrf_token) setCsrfToken(payload.csrf_token);
            throw new Error(payload?.message || 'Excel export failed.');
        }

        const blob = await response.blob();
        if (!blob.size) {
            throw new Error('Excel export returned an empty file.');
        }

        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filenameFromContentDisposition(
            response.headers.get('Content-Disposition'),
            'asct-student-records.xlsx'
        );
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        showToast('Excel export downloaded.', 'success');
    } catch (error) {
        showToast(error.message || 'Excel export failed.', 'error');
    } finally {
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.innerHTML = originalHtml;
    }
}

/* ============================================ */
/* 7c. FACULTY VIEW RENDERING                   */
/* ============================================ */

function renderUsersView() {
    if (!hasPermission('manage_users')) {
        return `<div class="empty-state fade-in"><i class="fa-solid fa-lock"></i><h3>ACCESS DENIED</h3><p>Administrator access required to manage users.</p></div>`;
    }

    return `
        <div class="hero-banner fade-in">
            <div class="hero-banner-content">
                <h1>FACULTY <span>MANAGEMENT</span></h1>
                <p>Control access, assign roles, and maintain system integrity.</p>
            </div>
        </div>

        <div class="section-panel fade-in-up" style="margin-bottom:0">
            <div class="section-panel-header">
                <div><h2>FACULTY <span>DIRECTORY</span></h2><div class="section-sub">Active accounts, roles, and account state</div></div>
            </div>

            <div class="table-controls">
                <div class="search-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="user-search" placeholder="Search users by name, email, role, or status..." aria-label="Search users">
                </div>
                <button class="btn btn-primary btn-sm" data-action="add-user"><i class="fa-solid fa-plus"></i> Add Faculty</button>
            </div>

            <div class="table-wrapper" id="users-table-wrapper">
                ${renderUserTable(state.users)}
            </div>
        </div>
    `;
}

function renderApprovalView() {
    if (!hasPermission('manage_users')) {
        return `<div class="empty-state fade-in"><i class="fa-solid fa-lock"></i><h3>ACCESS DENIED</h3><p>Administrator access required to approve students.</p></div>`;
    }

    const pendingStudents = state.users.filter(u => u.role === 'student' && u.status === 'pending');

    return `
        <div class="hero-banner fade-in">
            <div class="hero-banner-content">
                <h1>STUDENT <span>APPROVAL</span></h1>
                <p>Review student signup requests and activate approved accounts.</p>
            </div>
        </div>

        ${renderApprovalQueue(pendingStudents)}
    `;
}

function renderApprovalQueue(users) {
    const count = users.length;
    const cards = count
        ? `<div class="approval-grid">
            ${users.map(user => `
                <div class="approval-card">
                    <div class="approval-card-header">
                        <div>
                            <div class="approval-name">${escapeHtml(user.name)}</div>
                            <div class="approval-email">${escapeHtml(user.email)}</div>
                        </div>
                        <span class="account-status-badge pending">Pending</span>
                    </div>
                    <div class="approval-meta-grid">
                        <div class="approval-meta"><label>Email Status</label><span>Confirmed</span></div>
                        <div class="approval-meta"><label>Requested</label><span>${escapeHtml(formatPasskeyTimestamp(user.created_at, 'Unknown'))}</span></div>
                    </div>
                    <div class="approval-actions">
                        <button class="btn btn-primary btn-sm" data-action="approve-student-user" data-id="${user.id}">
                            <i class="fa-solid fa-user-check"></i> Approve
                        </button>
                        <button class="btn btn-danger btn-sm" data-action="reject-student-user" data-id="${user.id}">
                            <i class="fa-solid fa-user-xmark"></i> Reject
                        </button>
                    </div>
                </div>
            `).join('')}
        </div>`
        : `<div class="approval-empty">
            <i class="fa-solid fa-circle-check"></i>
            <div><strong>No Pending Requests</strong><span>New student signup requests will appear here for approval.</span></div>
        </div>`;

    return `
        <div class="section-panel fade-in-up">
            <div class="section-panel-header">
                <div><h2>STUDENT <span>APPROVALS</span></h2><div class="section-sub">Review self-service student account requests</div></div>
                <div class="approval-summary"><div class="approval-count">${count}</div><span>${count === 1 ? 'request waiting' : 'requests waiting'}</span></div>
            </div>
            ${cards}
        </div>
    `;
}

function renderUserTable(users) {
    if (users.length === 0) {
        return `<div class="empty-state"><i class="fa-solid fa-users-slash"></i><h3>NO FACULTY FOUND</h3><p>No faculty accounts match your search.</p></div>`;
    }

    return `
        <table class="data-table user-table">
            <thead>
                <tr><th>Name</th><th>Email</th><th>Password</th><th>Role</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                ${users.map(u => {
                    const isPendingStudent = u.role === 'student' && u.status === 'pending';
                    const roleClass = safeClassToken(u.role, 'student');
                    const statusClass = safeClassToken(u.status, 'active');
                    return `
                    <tr>
                        <td style="font-weight:600">${escapeHtml(u.name)}</td>
                        <td>${escapeHtml(u.email)}</td>
                        <td style="color:var(--muted);font-style:italic;font-size:0.85rem">Encrypted</td>
                        <td><span class="role-badge ${roleClass}">${escapeHtml(String(u.role ?? '').toUpperCase())}</span></td>
                        <td><span class="account-status-badge ${statusClass}">${escapeHtml(String(u.status ?? '').toUpperCase())}</span></td>
                        <td>
                            <div class="table-actions">
                                ${isPendingStudent ? `<button class="btn btn-primary btn-sm btn-icon" data-action="approve-student-user" data-id="${u.id}" title="Approve"><i class="fa-solid fa-user-check"></i></button>` : ''}
                                ${isPendingStudent ? `<button class="btn btn-danger btn-sm btn-icon" data-action="reject-student-user" data-id="${u.id}" title="Reject"><i class="fa-solid fa-user-xmark"></i></button>` : ''}
                                <button class="btn btn-ghost btn-sm btn-icon" data-action="edit-user" data-id="${u.id}" title="Edit"><i class="fa-solid fa-pen"></i></button>
                                ${u.id !== state.currentUser.id && !isPendingStudent ? `<button class="btn btn-danger btn-sm btn-icon" data-action="delete-user" data-id="${u.id}" title="Delete"><i class="fa-solid fa-trash"></i></button>` : ''}
                            </div>
                        </td>
                    </tr>
                `}).join('')}
            </tbody>
        </table>
    `;
}

function filterUserTable() {
    const search = (document.getElementById('user-search')?.value || '').toLowerCase();
    const filtered = state.users.filter(u =>
        u.name.toLowerCase().includes(search) ||
        u.email.toLowerCase().includes(search) ||
        u.role.toLowerCase().includes(search) ||
        u.status.toLowerCase().includes(search)
    );
    const wrapper = document.getElementById('users-table-wrapper');
    if (wrapper) wrapper.innerHTML = renderUserTable(filtered);
}

function formatAuditAction(action) {
    const labels = {
        student_soft_delete: 'Student Soft Delete',
        student_hard_delete: 'Student Hard Delete',
        student_export: 'Student Export',
        user_delete: 'Faculty Delete',
        student_signup_reject: 'Student Signup Rejected'
    };

    return labels[action] || String(action || '').replace(/_/g, ' ');
}

function formatAuditSource(source) {
    const labels = {
        student_update: 'Status edit',
        student_soft_delete: 'Soft delete button'
    };

    return labels[source] || source;
}

function renderAuditDetails(log) {
    const metadata = log.metadata || {};
    const parts = [];

    if (metadata.student_id) parts.push(`Student ID: ${escapeHtml(metadata.student_id)}`);
    if (metadata.email) parts.push(`Email: ${escapeHtml(metadata.email)}`);
    if (metadata.role) parts.push(`Role: ${escapeHtml(metadata.role)}`);
    if (metadata.course) parts.push(`Course: ${escapeHtml(metadata.course)}`);
    if (metadata.previous_status) parts.push(`Previous status: ${escapeHtml(metadata.previous_status)}`);
    if (metadata.source) parts.push(`Source: ${escapeHtml(formatAuditSource(metadata.source))}`);
    if (metadata.record_count !== undefined) parts.push(`Records exported: ${escapeHtml(metadata.record_count)}`);
    if (metadata.filters) parts.push(`Filters: ${escapeHtml(formatStudentFilterSummary(metadata.filters))}`);

    return parts.length
        ? `<div class="audit-details">${parts.join('<br>')}</div>`
        : '<span class="audit-muted">No details</span>';
}

function renderAuditLogView() {
    if (!hasPermission('view_audit_logs')) {
        return `<div class="empty-state fade-in"><i class="fa-solid fa-lock"></i><h3>ACCESS DENIED</h3><p>Administrator access required to view audit logs.</p></div>`;
    }

    return `
        <div class="hero-banner fade-in">
            <div class="hero-banner-content">
                <h1>AUDIT <span>LOG</span></h1>
                <p>Review delete activity across student records, user accounts, and signup requests.</p>
            </div>
        </div>

        <div class="section-panel fade-in-up" style="margin-bottom:0">
            <div class="section-panel-header">
                <div><h2>DELETE <span>ACTIVITY</span></h2><div class="section-sub">Newest 100 audited delete events</div></div>
            </div>
            <div class="table-controls">
                <button class="btn btn-secondary btn-sm" data-action="refresh-audit"><i class="fa-solid fa-rotate"></i> Refresh</button>
            </div>
            <div class="table-wrapper">
                ${renderAuditLogTable(state.auditLogs)}
            </div>
        </div>
    `;
}

function renderAuditLogTable(logs) {
    if (!logs.length) {
        return `<div class="empty-state"><i class="fa-solid fa-clock-rotate-left"></i><h3>NO AUDIT EVENTS</h3><p>Delete activity will appear here after it is recorded.</p></div>`;
    }

    return `
        <table class="data-table audit-log-table">
            <thead>
                <tr><th>Date/Time</th><th>Actor</th><th>Role</th><th>Action</th><th>Target</th><th>Details</th></tr>
            </thead>
            <tbody>
                ${logs.map(log => {
                    const actorRole = String(log.actor_role || '');
                    const actorRoleClass = ['admin', 'teacher', 'student'].includes(actorRole) ? actorRole : '';
                    const targetId = log.target_id ? `#${log.target_id}` : 'No ID';
                    return `
                    <tr>
                        <td>${escapeHtml(formatPasskeyTimestamp(log.created_at, 'Unknown'))}</td>
                        <td><div class="audit-cell-main">${escapeHtml(log.actor_name)}<span>${escapeHtml(log.actor_email)}</span></div></td>
                        <td><span class="role-badge ${actorRoleClass}">${escapeHtml(actorRole.toUpperCase())}</span></td>
                        <td>${escapeHtml(formatAuditAction(log.action))}</td>
                        <td><div class="audit-cell-main">${escapeHtml(log.target_label)}<span>${escapeHtml(log.target_type)} ${escapeHtml(targetId)}</span></div></td>
                        <td>${renderAuditDetails(log)}</td>
                    </tr>
                `}).join('')}
            </tbody>
        </table>
    `;
}

/* ============================================ */
/* 7d. PROFILE VIEW RENDERING                   */
/* ============================================ */

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function safeClassToken(value, fallback = '') {
    const token = String(value ?? '');
    return /^[a-z0-9_-]+$/i.test(token) ? token : fallback;
}

function formatPasskeyTimestamp(value, fallback = 'Never used') {
    if (!value) return fallback;

    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString([], {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function renderProfileView() {
    const user = state.currentUser;
    const isStudent = user.role === 'student';
    const student = isStudent ? findOwnStudent() : null;

    const roleClass = safeClassToken(user.role, 'student');
    const roleText = escapeHtml(String(user.role ?? '').toUpperCase());
    const initials = getInitials(user.name);
    const userName = escapeHtml(user.name);
    const userEmail = escapeHtml(user.email);
    const avatarHtml = renderAvatarContent(initials, user.name);

    let detailsHtml = `
        <div class="profile-detail-grid">
            <div class="profile-detail-item"><label>Full Name</label><span>${userName}</span></div>
            <div class="profile-detail-item"><label>Email</label><span>${userEmail}</span></div>
            <div class="profile-detail-item"><label>Role</label><span class="role-badge ${roleClass}" style="font-size:0.85rem">${roleText}</span></div>
        </div>
    `;

    // Student-specific: editable fields
    if (isStudent && student) {
        detailsHtml += `
            <h2 style="margin-top:28px">STUDENT <span>DETAILS</span></h2>
            <div class="profile-detail-grid">
                <div class="profile-detail-item"><label>Student ID</label><span>${escapeHtml(student.student_id)}</span></div>
                <div class="profile-detail-item"><label>Status</label><span class="status-badge ${safeClassToken(student.status, 'active')}">${escapeHtml(student.status)}</span></div>
                <div class="profile-detail-item"><label>Course</label><span>${escapeHtml(student.course)}</span></div>
                <div class="profile-detail-item"><label>Year Level</label><span>${escapeHtml(student.year_level)}</span></div>
                <div class="profile-detail-item"><label>Phone</label><span>${escapeHtml(student.phone)}</span></div>
                <div class="profile-detail-item"><label>Address</label><span>${escapeHtml(student.address)}</span></div>
                <div class="profile-detail-item"><label>Birthdate</label><span>${escapeHtml(student.birthdate)}</span></div>
            </div>
            <button class="btn btn-primary" data-action="edit-own-student" style="margin-top:20px;font-family:var(--font-heading);letter-spacing:2px">
                <i class="fa-solid fa-pen-to-square"></i> UPDATE MY DETAILS
            </button>
        `;
    }

    return `
        <div class="hero-banner fade-in">
            <div class="hero-banner-content">
                <h1>MY <span>PROFILE</span></h1>
                <p>Your account identity and student information.</p>
            </div>
        </div>
        <div class="profile-layout fade-in-up">
            <div class="profile-sidebar">
                <div class="profile-avatar">${avatarHtml}</div>
                <div class="profile-name">${userName}</div>
                <div class="profile-email">${userEmail}</div>
                <span class="role-badge ${roleClass}" style="font-size:0.8rem">${roleText}</span>
                <div class="profile-picture-actions">
                    <input type="file" id="profile-picture-input" class="sr-only" accept="image/jpeg,image/png,image/webp">
                    <label class="btn btn-secondary btn-sm" for="profile-picture-input"><i class="fa-solid fa-camera"></i> Upload Photo</label>
                    <div class="profile-picture-help">JPG, PNG, or WEBP up to 2 MB</div>
                </div>
            </div>
            <div class="profile-main">
                <h2>ACCOUNT <span>INFORMATION</span></h2>
                ${detailsHtml}
            </div>
        </div>
    `;
}

async function handleProfilePictureUpload(file, input) {
    if (!file) return;

    if (!PROFILE_PICTURE_TYPES.includes(file.type)) {
        showToast('Use a JPG, PNG, or WEBP image.', 'error');
        input.value = '';
        return;
    }

    if (file.size > PROFILE_PICTURE_MAX_BYTES) {
        showToast('Profile picture must be 2 MB or smaller.', 'error');
        input.value = '';
        return;
    }

    const button = document.querySelector('label[for="profile-picture-input"]');
    const previousButtonHtml = button?.innerHTML;
    const formData = new FormData();
    formData.append('profile_picture', file);

    if (button) {
        button.style.pointerEvents = 'none';
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading';
    }

    try {
        const data = await apiFormRequest('profile_picture', 'upload', formData);
        const version = data?.version || Date.now();
        state.profilePictureVersion = version;
        if (state.currentUser) {
            state.currentUser.profile_picture_version = version;
        }
        updateHeaderUser();
        renderCurrentView();
        showToast('Profile picture updated.', 'success');
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        if (button) {
            button.style.pointerEvents = '';
            button.innerHTML = previousButtonHtml;
        }
        input.value = '';
    }
}

function renderSecurityView() {
    const passkeyRows = state.passkeys.length
        ? state.passkeys.map(passkey => `
            <div class="passkey-row">
                <div>
                    <div class="passkey-name">${escapeHtml(passkey.label)}</div>
                    <div class="passkey-meta">Added ${escapeHtml(formatPasskeyTimestamp(passkey.created_at, 'Unknown'))} &bull; Last used ${escapeHtml(formatPasskeyTimestamp(passkey.last_used_at))}</div>
                </div>
                <button class="btn btn-danger btn-sm btn-icon" data-action="delete-passkey" data-id="${passkey.id}" title="Delete passkey"><i class="fa-solid fa-trash"></i></button>
            </div>
        `).join('')
        : `<div class="empty-state" style="padding:28px 20px"><i class="fa-solid fa-fingerprint"></i><h3>NO PASSKEYS YET</h3><p>Add a passkey to sign in with your device.</p></div>`;

    return `
        <div class="hero-banner fade-in">
            <div class="hero-banner-content">
                <h1>ACCOUNT <span>SECURITY</span></h1>
                <p>Manage your password fallback and passkey sign-in methods.</p>
            </div>
        </div>
        <div class="profile-main fade-in-up">
            <h2>SIGN-IN <span>METHODS</span></h2>
            <div class="profile-detail-grid">
                <div class="profile-detail-item"><label>Password</label><span style="color:var(--muted);font-style:italic">Encrypted</span></div>
                <div class="profile-detail-item"><label>Passkeys</label><span>${state.passkeys.length}</span></div>
            </div>

            <h2 style="margin-top:28px">PASSKEY <span>MANAGEMENT</span></h2>
            <div class="passkey-actions">
                <div class="form-group">
                    <label for="passkey-label">Passkey Name</label>
                    <input type="text" id="passkey-label" maxlength="120" placeholder="My device passkey">
                </div>
                <button class="btn btn-primary" data-action="add-passkey" style="font-family:var(--font-heading);letter-spacing:2px">
                    <i class="fa-solid fa-plus"></i> Add Passkey
                </button>
            </div>
            <div class="passkey-list">${passkeyRows}</div>
        </div>
    `;
}

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

// Main content action clicks (event delegation)
document.getElementById('main-content').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    const action = btn.dataset.action;
    const id = btn.dataset.id ? parseInt(btn.dataset.id) : null;

    switch (action) {
        case 'add-student':
            if (!hasPermission('add_student')) { showToast('Only admins can add students.', 'error'); return; }
            openStudentModal(null);
            break;
        case 'edit-student':
            if (!hasPermission('edit_students')) { showToast('You do not have permission to edit students.', 'error'); return; }
            if (id) openStudentModal(id);
            break;
        case 'soft-delete':
            softDeleteStudent(id);
            break;
        case 'hard-delete':
            hardDeleteStudent(id);
            break;
        case 'export-students':
            exportStudentsToExcel(btn);
            break;
        case 'add-user':
            openUserModal(null);
            break;
        case 'edit-user':
            openUserModal(id);
            break;
        case 'delete-user':
            deleteUser(id);
            break;
        case 'approve-student-user':
            openStudentApprovalModal(id);
            break;
        case 'reject-student-user':
            rejectStudentSignup(id);
            break;
        case 'open-pending-approvals':
            openPendingApprovalsModal();
            break;
        case 'stat-total-students':
        case 'stat-active-students':
        case 'stat-inactive-students':
        case 'stat-total-faculty':
            openStatDetailModal(action);
            break;
        case 'nav-students':
            navigateTo('students');
            break;
        case 'nav-users':
            navigateTo('users');
            break;
        case 'nav-approval':
            navigateTo('approval');
            break;
        case 'refresh-audit':
            refreshAuditLogs().then(renderCurrentView);
            break;
        case 'nav-profile':
            navigateTo('profile');
            break;
        case 'add-passkey':
            addPasskey();
            break;
        case 'delete-passkey':
            deletePasskey(id);
            break;
        case 'edit-own-student':
            // Student editing their own record
            const ownStudent = findOwnStudent();
            if (ownStudent) openStudentModal(ownStudent.id);
            else showToast('Your student record was not found.', 'error');
            break;
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

/* ============================================ */
/* 9. APP INITIALIZATION                        */
/* ============================================ */

// Show the login page
function showLoginPage() {
    showPasswordLoginStep();
    document.getElementById('login-page').style.display = 'flex';
    document.getElementById('app-container').style.display = 'none';
    document.getElementById('login-password').value = '';
}

function updateHeaderUser() {
    if (!state.currentUser) return;

    const user = state.currentUser;
    const initials = getInitials(user.name);
    const headerAvatar = document.getElementById('header-avatar');

    headerAvatar.innerHTML = renderAvatarContent(initials, user.name);
    document.getElementById('header-user-name').textContent = user.name;

    const roleBadge = document.getElementById('header-role-badge');
    roleBadge.textContent = user.role.toUpperCase();
    roleBadge.className = `role-badge ${safeClassToken(user.role, 'student')}`;
}

// Show the authenticated application shell
function showApp() {
    if (!state.currentUser) {
        showLoginPage();
        return;
    }

    document.getElementById('login-page').style.display = 'none';
    document.getElementById('app-container').style.display = 'block';
    updateHeaderUser();

    renderSidebar();
    updateHeaderTitle();
    renderCurrentView();
}

// Load data and route to the correct initial screen
async function initApp() {
    if (await checkSession()) {
        showApp();
    } else {
        showLoginPage();
    }
}

initApp();
