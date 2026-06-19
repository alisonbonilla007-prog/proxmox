<?php
// src/controllers_public.php — unauthenticated self-serve signup.
// Creates a tenant (trial subscription) and provisions a WireGuard slot, then
// shows the owner their portal/admin URLs so they can log in and connect a router.

function handle_signup(string $path, PDO $pdo, Auth $auth, array $config, string $VIEWS): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$auth->csrfOk()) { flash('error', 'Session expired, please retry.'); redirect('/signup'); }
        [$tid, $err] = provision_new_tenant(
            $pdo, $config,
            trim($_POST['name'] ?? ''),
            $_POST['slug'] ?? '',
            $_POST['password'] ?? '',
            $_POST['brand_color'] ?? '#25f4a7',
            trim($_POST['contact'] ?? '')
        );
        if ($err) { flash('error', $err); redirect('/signup'); }
        $slug = slugify($_POST['slug'] ?: $_POST['name']);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Account created — your 14-day trial has started.'];
        redirect('/signup/done?tenant=' . urlencode($slug));
    }

    if ($path === '/signup/done') {
        $slug = slugify($_GET['tenant'] ?? '');
        $st = $pdo->prepare("SELECT * FROM tenants WHERE slug = ?");
        $st->execute([$slug]);
        $tenant = $st->fetch();
        if (!$tenant) { redirect('/signup'); }
        echo view($VIEWS . '/signup_done.php', ['tenant' => $tenant, 'config' => $config, 'flash' => take_flash()]);
        return;
    }

    echo view($VIEWS . '/signup.php', ['csrf' => $auth->csrfToken(), 'flash' => take_flash(), 'config' => $config]);
}
