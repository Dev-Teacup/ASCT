// Soft delete: mark student as inactive with timestamp
function softDeleteStudent(studentId) {
    if (!hasPermission('soft_delete')) { showToast('You do not have permission to delete students.', 'error'); return; }
    const student = state.students.find(s => s.id === studentId);
    if (!student) return;

    showConfirm(
        'SOFT DELETE',
        `Mark "${student.first_name} ${student.last_name}" as inactive? The record will be preserved but the student status will change to Inactive.`,
        'Soft Delete',
        'btn-danger',
        async () => {
            try {
                const data = await apiRequest('students', 'soft_delete', { method: 'POST', data: { id: studentId } });
                upsertStudent(data);
                await refreshAuditLogs();
                showToast(`${student.first_name} ${student.last_name} has been deactivated.`, 'warning');
                renderCurrentView();
            } catch (error) {
                showToast(error.message, 'error');
            }
        }
    );
}

// Hard delete: permanently remove from data
function hardDeleteStudent(studentId) {
    if (!hasPermission('hard_delete')) { showToast('Only admins can permanently delete records.', 'error'); return; }
    const student = state.students.find(s => s.id === studentId);
    if (!student) return;

    showConfirm(
        'PERMANENT DELETE',
        `Permanently delete "${student.first_name} ${student.last_name}"? This action CANNOT be undone. The record will be completely removed from the system.`,
        'Hard Delete',
        'btn-danger',
        async () => {
            try {
                await apiRequest('students', 'hard_delete', { method: 'POST', data: { id: studentId } });
                removeStudent(studentId);
                await refreshAuditLogs();
                showToast(`${student.first_name} ${student.last_name} has been permanently deleted.`, 'error');
                renderCurrentView();
            } catch (error) {
                showToast(error.message, 'error');
            }
        }
    );
}

// Delete faculty account (admin only)
function deleteUser(userId) {
    if (!hasPermission('manage_users')) { showToast('Admin access required.', 'error'); return; }
    const user = state.users.find(u => u.id === userId);
    if (!user) return;
    if (user.id === state.currentUser.id) { showToast('You cannot delete your own account.', 'error'); return; }

    showConfirm(
        'DELETE FACULTY',
        `Permanently delete faculty account "${user.name}"? This cannot be undone.`,
        'Delete Faculty',
        'btn-danger',
        async () => {
            try {
                await apiRequest('users', 'delete', { method: 'POST', data: { id: userId } });
                removeUser(userId);
                await refreshAuditLogs();
                showToast(`Faculty account "${user.name}" has been deleted.`, 'warning');
                renderCurrentView();
            } catch (error) {
                showToast(error.message, 'error');
            }
        }
    );
}

function rejectStudentSignup(userId) {
    if (!hasPermission('manage_users')) { showToast('Admin access required.', 'error'); return; }
    const user = state.users.find(u => u.id === userId);
    if (!user || user.role !== 'student' || user.status !== 'pending') {
        showToast('Pending student signup was not found.', 'error');
        return;
    }

    showConfirm(
        'REJECT SIGNUP',
        `Reject the student account request from "${user.name}"? The pending account will be removed.`,
        'Reject',
        'btn-danger',
        async () => {
            try {
                await apiRequest('users', 'reject_student', { method: 'POST', data: { user_id: userId } });
                removeUser(userId);
                await refreshAuditLogs();
                showToast(`Signup request from "${user.name}" was rejected.`, 'warning');
                renderCurrentView();
            } catch (error) {
                showToast(error.message, 'error');
            }
        }
    );
}

