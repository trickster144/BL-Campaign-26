<?php
// files/auth.php — Team-based authentication & access control
// Include AFTER config.php. Call ensureTeamColumn() on setup pages only.

/**
 * Ensure the 'team' column exists on the users table.
 * Safe to call multiple times — only adds if missing.
 */
function ensureTeamColumn($conn) {
    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'team'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN team ENUM('grey','blue','red','green') NOT NULL DEFAULT 'grey' AFTER account_type");
    }
}

/**
 * Get the current user's full info from session + DB.
 * Returns array with id, username, account_type, team — or null if not logged in.
 */
function getCurrentUser($conn) {
    if (!isset($_SESSION['user_id'])) return null;

    // Always fetch fresh from DB so team/role changes take effect immediately
    $stmt = $conn->prepare("SELECT id, username, account_type, team FROM users WHERE id = ?");
    $uid = (int)$_SESSION['user_id'];
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user) {
        $_SESSION['team'] = $user['team'] ?? 'grey';
        $_SESSION['account_type'] = $user['account_type'];
        $_SESSION['username'] = $user['username'];
        return $user;
    }
    return null;
}

/** Redirect to login if not logged in */
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . BASE_URL . "auth/login.php");
        exit;
    }
}

/** Redirect with message if user is on grey team (unassigned) */
function requireTeamAssignment($user) {
    if (!$user || $user['team'] === 'grey') {
        header("Location: " . BASE_URL . "index.php?msg=no_team");
        exit;
    }
}

/** Check if user can view a specific faction's data */
function canViewFaction($user, $faction) {
    if (!$user) return false;
    if ($user['team'] === 'green') return true; // green sees all
    return $user['team'] === $faction;
}

/** Get the faction(s) a user can see — returns array */
function viewableFactions($user) {
    if (!$user) return [];
    if ($user['team'] === 'green') return ['blue', 'red'];
    if (in_array($user['team'], ['blue', 'red'])) return [$user['team']];
    return [];
}

/** Check if user is green team (game admin) */
function isGreenTeam($user) {
    return $user && $user['team'] === 'green';
}

/** Check if user is mod or admin (account_type) or green team */
function isModOrAbove($user) {
    if (!$user) return false;
    return in_array($user['account_type'], ['admin', 'mod']) || $user['team'] === 'green';
}

/** Check if user is admin (account_type) or green team */
function isAdmin($user) {
    if (!$user) return false;
    return $user['account_type'] === 'admin' || $user['team'] === 'green';
}

/** Get team badge HTML */
function teamBadge($team) {
    return match($team) {
        'blue' => '<span class="badge bg-primary">Blue Team</span>',
        'red' => '<span class="badge bg-danger">Red Team</span>',
        'green' => '<span class="badge bg-success">Game Admin</span>',
        'grey' => '<span class="badge bg-secondary">Unassigned</span>',
        default => '<span class="badge bg-secondary">' . htmlspecialchars($team) . '</span>',
    };
}
