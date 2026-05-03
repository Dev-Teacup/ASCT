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

