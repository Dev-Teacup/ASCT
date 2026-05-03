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
                    <p>Welcome to your personal dashboard. Your student record is not yet linked â€” contact administration.</p>
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

