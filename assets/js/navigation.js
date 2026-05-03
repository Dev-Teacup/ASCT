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

