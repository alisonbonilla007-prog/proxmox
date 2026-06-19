<?php
// src/Auth.php — authentication + CSRF for both the platform superadmin and
// per-tenant owners. Sessions are namespaced so a superadmin and a tenant
// owner can coexist without collisions.

class Auth
{
    public function __construct(private PDO $pdo) {}

    // ---------- CSRF ----------
    public function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public function csrfOk(): bool
    {
        return isset($_POST['csrf']) && isset($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $_POST['csrf']);
    }

    // ---------- Superadmin ----------
    public function loginSuperadmin(string $username, string $password): bool
    {
        $st = $this->pdo->prepare("SELECT * FROM superadmins WHERE username = ?");
        $st->execute([$username]);
        $row = $st->fetch();
        if ($row && password_verify($password, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['superadmin_id'] = (int)$row['id'];
            $_SESSION['superadmin_name'] = $row['username'];
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            return true;
        }
        return false;
    }

    public function superadminId(): ?int
    {
        return $_SESSION['superadmin_id'] ?? null;
    }

    // ---------- Tenant owner ----------
    public function loginTenant(int $tenantId, string $username, string $password): bool
    {
        $st = $this->pdo->prepare("SELECT * FROM tenant_users WHERE tenant_id = ? AND username = ?");
        $st->execute([$tenantId, $username]);
        $row = $st->fetch();
        if ($row && password_verify($password, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['tenant_user'] = ['id' => (int)$row['id'], 'tenant_id' => $tenantId, 'name' => $row['username']];
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            return true;
        }
        return false;
    }

    /** Returns the logged-in tenant user IF it belongs to $tenantId, else null. */
    public function tenantUser(int $tenantId): ?array
    {
        $u = $_SESSION['tenant_user'] ?? null;
        return ($u && (int)$u['tenant_id'] === $tenantId) ? $u : null;
    }

    public function logoutTenant(): void { unset($_SESSION['tenant_user']); }
    public function logoutSuperadmin(): void { unset($_SESSION['superadmin_id'], $_SESSION['superadmin_name']); }
}
