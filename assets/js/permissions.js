/* ============================================ */
/* 3. PERMISSION SYSTEM                         */
/* ============================================ */

// Returns true if the current user has the specified permission
function hasPermission(permission) {
    if (!state.currentUser) return false;
    const role = state.currentUser.role;
    const matrix = {
        add_student:      { admin: true,  teacher: false, student: false },
        view_all_students:{ admin: true,  teacher: true,  student: false },
        view_own_profile: { admin: true,  teacher: true,  student: true  },
        edit_students:    { admin: true,  teacher: true,  student: true  }, // limited by role in form
        soft_delete:      { admin: true,  teacher: true,  student: false },
        hard_delete:      { admin: true,  teacher: false, student: false },
        manage_users:     { admin: true,  teacher: false, student: false },
        view_audit_logs:  { admin: true,  teacher: false, student: false }
    };
    return matrix[permission] ? matrix[permission][role] : false;
}

// Check if current student user can edit a specific field
function canEditField(field) {
    const role = state.currentUser.role;
    if (role === 'admin') return true; // Admin can edit all fields
    if (role === 'teacher') return ['phone','address','course','year_level','status'].includes(field);
    if (role === 'student') return ['email','phone','address'].includes(field);
    return false;
}

