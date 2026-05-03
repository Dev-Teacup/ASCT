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

    headerAvatar.classList.remove('avatar-loaded');
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
