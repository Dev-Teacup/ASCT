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

