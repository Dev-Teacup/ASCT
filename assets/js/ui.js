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
        else if (target === 'profile-picture') closeProfilePictureCropper();
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

