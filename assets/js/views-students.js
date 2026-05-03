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

