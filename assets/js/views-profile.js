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
                    <div class="profile-picture-help">JPG, PNG, or WEBP up to 12 MB</div>
                </div>
            </div>
            <div class="profile-main">
                <h2>ACCOUNT <span>INFORMATION</span></h2>
                ${detailsHtml}
            </div>
        </div>
    `;
}

const PROFILE_PICTURE_EDITOR_SOURCE_MAX_BYTES = 12 * 1024 * 1024;
const PROFILE_PICTURE_EDITOR_SOURCE_MAX_DIMENSION = 8000;
const PROFILE_PICTURE_EDITOR_OUTPUT_SIZE = 512;

let profilePictureCropper = {
    source: null,
    zoom: 1,
    rotation: 0,
    offsetX: 0,
    offsetY: 0,
    drag: null,
    isSaving: false
};

function setProfilePictureEditorStatus(message) {
    const status = document.getElementById('profile-picture-editor-status');
    if (status) {
        status.textContent = message;
    }
}

function releaseProfilePictureSource() {
    const source = profilePictureCropper.source;
    if (source?.release) {
        source.release();
    }

    profilePictureCropper.source = null;
}

function resetProfilePictureCropperState(releaseSource = true) {
    if (releaseSource) {
        releaseProfilePictureSource();
    }

    profilePictureCropper.zoom = 1;
    profilePictureCropper.rotation = 0;
    profilePictureCropper.offsetX = 0;
    profilePictureCropper.offsetY = 0;
    profilePictureCropper.drag = null;
    profilePictureCropper.isSaving = false;
}

function profilePictureRotatedDimensions(width, height, rotation) {
    return Math.abs(rotation % 180) === 90
        ? { width: height, height: width }
        : { width, height };
}

function profilePictureBaseScale(size = PROFILE_PICTURE_EDITOR_OUTPUT_SIZE) {
    const source = profilePictureCropper.source;
    if (!source) return 1;

    const rotated = profilePictureRotatedDimensions(source.width, source.height, profilePictureCropper.rotation);
    return Math.max(size / rotated.width, size / rotated.height);
}

function clampProfilePictureOffset(size = PROFILE_PICTURE_EDITOR_OUTPUT_SIZE) {
    const source = profilePictureCropper.source;
    if (!source) return;

    const rotated = profilePictureRotatedDimensions(source.width, source.height, profilePictureCropper.rotation);
    const scale = profilePictureBaseScale(size) * profilePictureCropper.zoom;
    const maxOffsetX = Math.max(0, ((rotated.width * scale) - size) / 2);
    const maxOffsetY = Math.max(0, ((rotated.height * scale) - size) / 2);

    profilePictureCropper.offsetX = Math.max(-maxOffsetX, Math.min(maxOffsetX, profilePictureCropper.offsetX));
    profilePictureCropper.offsetY = Math.max(-maxOffsetY, Math.min(maxOffsetY, profilePictureCropper.offsetY));
}

function renderProfilePictureToCanvas(canvas) {
    const source = profilePictureCropper.source;
    if (!source) return;

    const ctx = canvas.getContext('2d');
    const size = canvas.width;
    clampProfilePictureOffset(size);

    ctx.clearRect(0, 0, size, size);
    ctx.fillStyle = '#111314';
    ctx.fillRect(0, 0, size, size);
    ctx.save();
    ctx.translate((size / 2) + profilePictureCropper.offsetX, (size / 2) + profilePictureCropper.offsetY);
    ctx.rotate(profilePictureCropper.rotation * Math.PI / 180);
    const scale = profilePictureBaseScale(size) * profilePictureCropper.zoom;
    ctx.scale(scale, scale);
    ctx.drawImage(source.image, -source.width / 2, -source.height / 2, source.width, source.height);
    ctx.restore();
}

function drawProfilePictureCropperPreview() {
    const canvas = document.getElementById('profile-picture-crop-canvas');
    if (!canvas || !profilePictureCropper.source) return;

    canvas.width = PROFILE_PICTURE_EDITOR_OUTPUT_SIZE;
    canvas.height = PROFILE_PICTURE_EDITOR_OUTPUT_SIZE;
    renderProfilePictureToCanvas(canvas);
}

function validateProfilePictureSourceDimensions(width, height) {
    if (width < 1 || height < 1) {
        throw new Error('Profile picture must be a valid image.');
    }

    if (width > PROFILE_PICTURE_EDITOR_SOURCE_MAX_DIMENSION || height > PROFILE_PICTURE_EDITOR_SOURCE_MAX_DIMENSION) {
        throw new Error('Profile picture dimensions are too large.');
    }
}

function loadProfilePictureImage(objectUrl) {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('Profile picture could not be read.'));
        image.src = objectUrl;
    });
}

async function decodeProfilePictureFile(file) {
    if (typeof createImageBitmap === 'function') {
        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            validateProfilePictureSourceDimensions(bitmap.width, bitmap.height);
            return {
                image: bitmap,
                width: bitmap.width,
                height: bitmap.height,
                release: () => {
                    if (typeof bitmap.close === 'function') {
                        bitmap.close();
                    }
                }
            };
        } catch (error) {
            if (String(error?.message || '').startsWith('Profile picture')) {
                throw error;
            }
        }
    }

    const objectUrl = URL.createObjectURL(file);
    try {
        const image = await loadProfilePictureImage(objectUrl);
        validateProfilePictureSourceDimensions(image.naturalWidth, image.naturalHeight);
        return {
            image,
            width: image.naturalWidth,
            height: image.naturalHeight,
            release: () => URL.revokeObjectURL(objectUrl)
        };
    } catch (error) {
        URL.revokeObjectURL(objectUrl);
        throw error;
    }
}

async function handleProfilePictureFileSelected(file, input) {
    if (!file) return;

    if (!PROFILE_PICTURE_TYPES.includes(file.type)) {
        showToast('Use a JPG, PNG, or WEBP image.', 'error');
        input.value = '';
        return;
    }

    if (file.size > PROFILE_PICTURE_EDITOR_SOURCE_MAX_BYTES) {
        showToast('Choose an image 12 MB or smaller.', 'error');
        input.value = '';
        return;
    }

    const button = document.querySelector('label[for="profile-picture-input"]');
    const previousButtonHtml = button?.innerHTML;

    if (button) {
        button.style.pointerEvents = 'none';
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Preparing';
    }

    try {
        const source = await decodeProfilePictureFile(file);
        openProfilePictureCropper(source);
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

function openProfilePictureCropper(source) {
    resetProfilePictureCropperState();
    profilePictureCropper.source = source;

    const zoom = document.getElementById('profile-picture-zoom');
    if (zoom) {
        zoom.value = '1';
    }

    setProfilePictureEditorStatus('Ready');
    document.getElementById('profile-picture-modal')?.classList.add('active');
    requestAnimationFrame(drawProfilePictureCropperPreview);
}

function closeProfilePictureCropper() {
    if (profilePictureCropper.isSaving) return;

    document.getElementById('profile-picture-modal')?.classList.remove('active');
    resetProfilePictureCropperState();
}

function updateProfilePictureZoom(value) {
    const zoom = Number.parseFloat(value);
    if (!Number.isFinite(zoom)) return;

    profilePictureCropper.zoom = Math.max(1, Math.min(3, zoom));
    drawProfilePictureCropperPreview();
}

function rotateProfilePictureCropper(direction) {
    const delta = direction === 'left' ? -90 : 90;
    profilePictureCropper.rotation = (profilePictureCropper.rotation + delta) % 360;
    profilePictureCropper.offsetX = 0;
    profilePictureCropper.offsetY = 0;
    drawProfilePictureCropperPreview();
}

function resetProfilePictureCropperTransform() {
    profilePictureCropper.zoom = 1;
    profilePictureCropper.rotation = 0;
    profilePictureCropper.offsetX = 0;
    profilePictureCropper.offsetY = 0;

    const zoom = document.getElementById('profile-picture-zoom');
    if (zoom) {
        zoom.value = '1';
    }

    drawProfilePictureCropperPreview();
}

function profilePictureCanvasPoint(event) {
    const canvas = document.getElementById('profile-picture-crop-canvas');
    const rect = canvas.getBoundingClientRect();

    return {
        x: (event.clientX - rect.left) * (PROFILE_PICTURE_EDITOR_OUTPUT_SIZE / rect.width),
        y: (event.clientY - rect.top) * (PROFILE_PICTURE_EDITOR_OUTPUT_SIZE / rect.height)
    };
}

function startProfilePictureCropDrag(event) {
    if (!profilePictureCropper.source || event.button > 0) return;

    const canvas = document.getElementById('profile-picture-crop-canvas');
    const point = profilePictureCanvasPoint(event);
    profilePictureCropper.drag = {
        pointerId: event.pointerId,
        startX: point.x,
        startY: point.y,
        offsetX: profilePictureCropper.offsetX,
        offsetY: profilePictureCropper.offsetY
    };

    canvas.setPointerCapture(event.pointerId);
    canvas.classList.add('is-dragging');
}

function moveProfilePictureCropDrag(event) {
    const drag = profilePictureCropper.drag;
    if (!drag || drag.pointerId !== event.pointerId) return;

    const point = profilePictureCanvasPoint(event);
    profilePictureCropper.offsetX = drag.offsetX + (point.x - drag.startX);
    profilePictureCropper.offsetY = drag.offsetY + (point.y - drag.startY);
    drawProfilePictureCropperPreview();
}

function endProfilePictureCropDrag(event) {
    const drag = profilePictureCropper.drag;
    if (!drag || drag.pointerId !== event.pointerId) return;

    const canvas = document.getElementById('profile-picture-crop-canvas');
    profilePictureCropper.drag = null;
    canvas.classList.remove('is-dragging');

    if (canvas.hasPointerCapture(event.pointerId)) {
        canvas.releasePointerCapture(event.pointerId);
    }
}

function canvasToBlob(canvas, type, quality) {
    return new Promise((resolve, reject) => {
        if (typeof canvas.toBlob !== 'function') {
            const dataUrl = canvas.toDataURL(type, quality);
            const parts = dataUrl.split(',');
            const binary = atob(parts[1] || '');
            const bytes = new Uint8Array(binary.length);

            for (let i = 0; i < binary.length; i += 1) {
                bytes[i] = binary.charCodeAt(i);
            }

            resolve(new Blob([bytes], { type }));
            return;
        }

        canvas.toBlob(blob => {
            if (blob) {
                resolve(blob);
            } else {
                reject(new Error('Profile picture could not be processed.'));
            }
        }, type, quality);
    });
}

async function exportProfilePictureFile() {
    if (!profilePictureCropper.source) {
        throw new Error('Please choose a profile picture to upload.');
    }

    const canvas = document.createElement('canvas');
    canvas.width = PROFILE_PICTURE_EDITOR_OUTPUT_SIZE;
    canvas.height = PROFILE_PICTURE_EDITOR_OUTPUT_SIZE;
    renderProfilePictureToCanvas(canvas);

    let quality = 0.9;
    let blob = await canvasToBlob(canvas, 'image/jpeg', quality);
    while (blob.size > PROFILE_PICTURE_MAX_BYTES && quality > 0.62) {
        quality -= 0.08;
        blob = await canvasToBlob(canvas, 'image/jpeg', quality);
    }

    if (blob.size > PROFILE_PICTURE_MAX_BYTES) {
        throw new Error('Profile picture could not be compressed below 2 MB.');
    }

    try {
        return new File([blob], 'profile-picture.jpg', { type: 'image/jpeg', lastModified: Date.now() });
    } catch (error) {
        blob.name = 'profile-picture.jpg';
        return blob;
    }
}

async function uploadProfilePictureFile(file) {
    const formData = new FormData();
    formData.append('profile_picture', file, 'profile-picture.jpg');

    const data = await apiFormRequest('profile_picture', 'upload', formData);
    const version = data?.version || Date.now();
    state.profilePictureVersion = version;

    if (state.currentUser) {
        state.currentUser.profile_picture_version = version;
    }

    updateHeaderUser();
    renderCurrentView();
}

async function saveProfilePictureCropper() {
    if (profilePictureCropper.isSaving) return;

    const saveButton = document.getElementById('profile-picture-save');
    const previousButtonHtml = saveButton?.innerHTML;

    profilePictureCropper.isSaving = true;
    setProfilePictureEditorStatus('Saving');

    if (saveButton) {
        saveButton.disabled = true;
        saveButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving';
    }

    try {
        const file = await exportProfilePictureFile();
        await uploadProfilePictureFile(file);
        document.getElementById('profile-picture-modal')?.classList.remove('active');
        resetProfilePictureCropperState();
        showToast('Profile picture updated.', 'success');
    } catch (error) {
        profilePictureCropper.isSaving = false;
        setProfilePictureEditorStatus('Ready');
        showToast(error.message, 'error');
    } finally {
        if (saveButton) {
            saveButton.disabled = false;
            saveButton.innerHTML = previousButtonHtml;
        }
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

