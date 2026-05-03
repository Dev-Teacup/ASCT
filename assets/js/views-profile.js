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

