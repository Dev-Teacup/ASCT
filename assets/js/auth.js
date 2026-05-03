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

