<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    $user = require_user();
    require_permission($user, 'view_audit_logs', 'Admin access required.');

    $action = request_action();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET' && $action === 'list') {
        $stmt = db()->query(
            'SELECT id, actor_user_id, actor_name, actor_email, actor_role, action, target_type, target_id, target_label, metadata, created_at
             FROM audit_logs
             ORDER BY id DESC
             LIMIT 100'
        );

        json_response([
            'success' => true,
            'data' => $stmt->fetchAll(),
        ]);
    }

    error_response('Unsupported audit log action.', 404);
} catch (Throwable $e) {
    error_response('Audit log request failed.', 500);
}
