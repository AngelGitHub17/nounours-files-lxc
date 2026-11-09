<?php
session_start();
require_once __DIR__ . '/frontend-config.php';

// ---- Déconnexion ----
if (isset($_GET['logout'])) {
    unset($_SESSION['nounours_files_authed']);
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ---- Traitement du formulaire de connexion ----
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['page_password'])) {
    if (hash_equals(PAGE_PASSWORD, $_POST['page_password'])) {
        $_SESSION['nounours_files_authed'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $loginError = 'Mot de passe incorrect.';
}

$isAuthed = !empty($_SESSION['nounours_files_authed']);

// ---- Page de connexion si non authentifié ----
if (!$isAuthed) {
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nounours Files — Connexion</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #0A0D16; --surface: #121723; --surface-2: #171E2D; --border: #232A3D;
    --accent-1: #4C8DFF; --accent-2: #8B5CF6; --text: #E9EDF7; --text-muted: #8A93A8; --danger: #FF6B6B;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
    background: var(--bg);
    background-image: radial-gradient(circle at 20% 20%, rgba(76,141,255,0.10), transparent 45%),
                       radial-gradient(circle at 80% 80%, rgba(139,92,246,0.08), transparent 45%);
    color: var(--text); font-family: 'Inter', sans-serif;
  }
  .login-card {
    background: var(--surface); border: 1px solid var(--border); border-radius: 16px;
    padding: 32px; width: 320px; display: flex; flex-direction: column; gap: 16px;
  }
  .brand { display: flex; align-items: center; gap: 10px; font-family: 'Sora', sans-serif; font-weight: 700; font-size: 17px; }
  .brand-mark { width: 30px; height: 30px; border-radius: 9px; background: linear-gradient(135deg, var(--accent-1), var(--accent-2)); }
  .login-card p { margin: 0; font-size: 13px; color: var(--text-muted); }
  input[type="password"] {
    background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px;
    padding: 10px 12px; color: var(--text); font-family: 'Inter', sans-serif; font-size: 14px;
  }
  input[type="password"]:focus { outline: none; border-color: var(--accent-1); }
  button {
    border: none; border-radius: 8px; padding: 10px 14px; font-weight: 600; font-size: 14px;
    font-family: 'Inter', sans-serif; cursor: pointer; color: white;
    background: linear-gradient(135deg, var(--accent-1), var(--accent-2));
  }
  .error { color: var(--danger); font-size: 12.5px; margin: -6px 0 0 0; }
</style>
</head>
<body>
  <form class="login-card" method="POST">
    <div class="brand"><div class="brand-mark"></div>Nounours Files</div>
    <p>Entre le mot de passe pour accéder à ton espace.</p>
    <?php if ($loginError): ?><p class="error"><?= htmlspecialchars($loginError) ?></p><?php endif; ?>
    <input type="password" name="page_password" placeholder="Mot de passe" autofocus required>
    <button type="submit">Entrer</button>
  </form>
</body>
</html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nounours Files</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --bg: #0A0D16;
    --surface: #121723;
    --surface-2: #171E2D;
    --surface-3: #1C243A;
    --border: #232A3D;
    --accent-1: #4C8DFF;
    --accent-2: #8B5CF6;
    --text: #E9EDF7;
    --text-muted: #8A93A8;
    --danger: #FF6B6B;
    --success: #4ADE80;
    --star: #F5B942;
    --radius-sm: 6px;
    --radius-md: 10px;
    --radius-lg: 16px;
  }

  * { box-sizing: border-box; }

  body {
    margin: 0;
    background: var(--bg);
    background-image:
      radial-gradient(circle at 15% 0%, rgba(76,141,255,0.07), transparent 40%),
      radial-gradient(circle at 85% 100%, rgba(139,92,246,0.05), transparent 40%);
    color: var(--text);
    font-family: 'Inter', sans-serif;
    height: 100vh;
    overflow: hidden;
    font-size: 14px;
  }

  h1, h2, h3, .brand, .drive-letter { font-family: 'Sora', sans-serif; }

  .app {
    display: grid;
    grid-template-columns: 250px 1fr;
    grid-template-rows: 100vh;
    height: 100vh;
  }

  /* ---------- Sidebar ---------- */
  .sidebar {
    border-right: 1px solid var(--border);
    padding: 20px 14px;
    display: flex;
    flex-direction: column;
    gap: 22px;
    overflow-y: auto;
  }

  .brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
    font-size: 16px;
    letter-spacing: -0.01em;
    padding: 0 6px;
  }

  .brand-mark {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--accent-1), var(--accent-2));
    flex-shrink: 0;
  }

  .nav-section { display: flex; flex-direction: column; gap: 2px; }

  .nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border-radius: var(--radius-sm);
    color: var(--text-muted);
    cursor: pointer;
    font-size: 13.5px;
    font-weight: 500;
  }
  .nav-item:hover { background: var(--surface); color: var(--text); }
  .nav-item.active { background: var(--surface); color: var(--text); }
  .nav-item .nav-icon { width: 16px; text-align: center; flex-shrink: 0; opacity: 0.9; }

  .drives-section { display: flex; flex-direction: column; margin-top: 10px; margin-bottom: 10px; }

  #drivesList { display: flex; flex-direction: column; gap: 14px; }

  .section-label {
    font-size: 11px;
    color: var(--text-muted);
    padding: 6px 10px 8px 10px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  .drive-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 13px 14px;
    cursor: pointer;
    transition: border-color 0.15s ease;
  }

  .drive-card:hover { border-color: #364057; }

  .drive-card.active {
    border-color: transparent;
    background:
      linear-gradient(var(--surface), var(--surface)) padding-box,
      linear-gradient(135deg, var(--accent-1), var(--accent-2)) border-box;
    border: 1px solid transparent;
  }

  .drive-top {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 7px;
  }

  .drive-letter { font-size: 13px; font-weight: 600; }
  .drive-pct { font-size: 11px; color: var(--text-muted); }

  .drive-bar {
    height: 4px;
    border-radius: 3px;
    background: var(--surface-2);
    overflow: hidden;
    margin-bottom: 5px;
  }

  .drive-bar-fill {
    height: 100%;
    border-radius: 3px;
    background: linear-gradient(90deg, var(--accent-1), var(--accent-2));
  }

  .drive-detail { font-size: 10.5px; color: var(--text-muted); }

  .sidebar-bottom {
    margin-top: auto;
    display: flex;
    flex-direction: column;
    gap: 10px;
    border-top: 1px solid var(--border);
    padding-top: 14px;
  }

  .storage-summary { padding: 0 10px; }
  .storage-summary .drive-detail { margin-top: 5px; }

  .sidebar-user {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 8px;
    border-radius: var(--radius-sm);
    cursor: pointer;
  }
  .sidebar-user:hover { background: var(--surface); }

  .avatar {
    width: 28px; height: 28px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent-1), var(--accent-2));
    display: flex; align-items: center; justify-content: center;
    font-family: 'Sora', sans-serif; font-weight: 700; font-size: 12px;
    flex-shrink: 0; color: white;
  }

  .sidebar-user-info { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
  .sidebar-user-name { font-size: 12.5px; font-weight: 500; }
  .sidebar-user-status { font-size: 10.5px; color: var(--text-muted); display: flex; align-items: center; gap: 5px; }

  .status-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--success);
    box-shadow: 0 0 6px var(--success);
    flex-shrink: 0;
  }

  .logout-link {
    font-size: 11.5px;
    color: var(--text-muted);
    text-decoration: none;
    padding: 4px 8px;
  }
  .logout-link:hover { color: var(--text); }

  /* ---------- Main ---------- */
  .main {
    display: flex;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
  }

  .topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 26px;
    border-bottom: 1px solid var(--border);
    gap: 20px;
  }

  .breadcrumb {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14.5px;
    min-width: 0;
    overflow: hidden;
    white-space: nowrap;
  }

  .crumb {
    color: var(--text-muted);
    cursor: pointer;
  }
  .crumb:hover { color: var(--text); }
  .crumb.current { color: var(--text); font-weight: 500; }
  .crumb-sep { color: #3A4258; font-size: 12px; }
  .crumb-home { display: flex; align-items: center; }

  .topbar-actions { display: flex; gap: 8px; align-items: center; flex-shrink: 0; }

  .search-box {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 7px 12px;
    color: var(--text);
    font-size: 13px;
    width: 180px;
    font-family: 'Inter', sans-serif;
  }
  .search-box::placeholder { color: var(--text-muted); }
  .search-box:focus { outline: none; border-color: var(--accent-1); }

  .icon-btn {
    background: var(--surface);
    border: 1px solid var(--border);
    color: var(--text-muted);
    width: 33px; height: 33px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
  }
  .icon-btn:hover { color: var(--text); border-color: #364057; }
  .icon-btn.active { color: var(--text); background: var(--surface-3); }

  .btn {
    border: none;
    border-radius: var(--radius-sm);
    padding: 8px 14px;
    font-size: 13px;
    font-weight: 600;
    font-family: 'Inter', sans-serif;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: opacity 0.15s ease;
  }
  .btn:hover { opacity: 0.88; }

  .btn-primary {
    background: linear-gradient(135deg, var(--accent-1), var(--accent-2));
    color: white;
  }

  .view-toggle {
    display: flex;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    overflow: hidden;
  }
  .view-toggle button {
    background: var(--surface);
    border: none;
    color: var(--text-muted);
    padding: 7px 10px;
    cursor: pointer;
    font-size: 12.5px;
  }
  .view-toggle button.active { background: var(--surface-3); color: var(--text); }

  /* New menu */
  .new-menu-wrap { position: relative; }
  .new-menu {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 6px;
    display: none;
    flex-direction: column;
    min-width: 190px;
    box-shadow: 0 12px 30px rgba(0,0,0,0.4);
    z-index: 15;
  }
  .new-menu.show { display: flex; }
  .new-menu-item {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 10px; border-radius: var(--radius-sm);
    cursor: pointer; font-size: 13px; color: var(--text);
    background: none; border: none; text-align: left; font-family: 'Inter', sans-serif;
  }
  .new-menu-item:hover { background: var(--surface-3); }
  .new-menu-item .mi { width: 16px; text-align: center; color: var(--text-muted); }

  .content {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 20px 26px 26px 26px;
    position: relative;
  }

  /* Quick access strip */
  .quick-access {
    margin-bottom: 22px;
  }
  .quick-access-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 12px;
  }
  .quick-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .quick-card:hover { border-color: #364057; }
  .quick-card .file-icon { width: 34px; height: 34px; font-size: 16px; flex-shrink: 0; }
  .quick-card-info { min-width: 0; }
  .quick-card-name { font-size: 12.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .quick-card-meta { font-size: 10.5px; color: var(--text-muted); margin-top: 2px; }

  /* Grid view */
  .file-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 14px;
  }

  .file-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 16px 12px;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    text-align: center;
    transition: border-color 0.15s ease, transform 0.1s ease;
    position: relative;
  }
  .file-card:hover { border-color: #364057; transform: translateY(-1px); }
  .file-card .star-btn { position: absolute; top: 8px; right: 8px; }

  .file-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
  }
  .file-icon.dir {
    background: linear-gradient(135deg, rgba(76,141,255,0.18), rgba(139,92,246,0.18));
  }
  .file-icon.file { background: var(--surface-2); }

  .file-name {
    font-size: 12.5px;
    word-break: break-word;
    line-height: 1.35;
    max-width: 100%;
  }
  .file-meta { font-size: 10.5px; color: var(--text-muted); }

  /* List view (Nextcloud-like) */
  .file-list { display: flex; flex-direction: column; }
  .file-row {
    display: grid;
    grid-template-columns: 22px 26px 1fr 90px 130px 28px 28px;
    align-items: center;
    gap: 12px;
    padding: 8px 8px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-size: 13px;
    position: relative;
  }
  .file-row:hover { background: var(--surface); }
  .file-row:hover .row-menu-btn, .file-row:hover .star-btn { opacity: 1; }
  .file-row .file-icon { width: 26px; height: 26px; font-size: 14px; border-radius: 6px; }
  .file-row .file-name { text-align: left; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .file-row .file-meta { text-align: left; color: var(--text-muted); }
  .row-head {
    font-size: 11px;
    color: var(--text-muted);
    padding: 0 8px 8px 8px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 4px;
  }
  .row-head-grid {
    display: grid;
    grid-template-columns: 22px 26px 1fr 90px 130px 28px 28px;
    gap: 12px;
    align-items: center;
  }

  .row-checkbox, .file-row input[type="checkbox"] {
    accent-color: var(--accent-1);
    cursor: pointer;
  }

  .star-btn, .row-menu-btn {
    background: none; border: none; color: var(--text-muted);
    cursor: pointer; font-size: 15px; padding: 4px;
    border-radius: 4px;
    opacity: 0;
    transition: opacity 0.1s ease;
  }
  .star-btn.starred { opacity: 1; color: var(--star); }
  .row-menu-btn:hover { background: var(--surface-3); color: var(--text); }
  .file-row .star-btn { opacity: 0; }
  .file-row .star-btn.starred { opacity: 1; }

  /* Context menu */
  .ctx-menu {
    position: fixed;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 6px;
    display: none;
    flex-direction: column;
    min-width: 170px;
    box-shadow: 0 12px 30px rgba(0,0,0,0.4);
    z-index: 25;
  }
  .ctx-menu.show { display: flex; }
  .ctx-item {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 10px; border-radius: var(--radius-sm);
    cursor: pointer; font-size: 13px; color: var(--text);
    background: none; border: none; text-align: left; font-family: 'Inter', sans-serif;
  }
  .ctx-item:hover { background: var(--surface-3); }
  .ctx-item.danger { color: var(--danger); }
  .ctx-item .mi { width: 16px; text-align: center; color: var(--text-muted); }

  /* Empty state */
  .empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 70px 20px;
    color: var(--text-muted);
    text-align: center;
  }
  .empty-state .glyph {
    width: 54px; height: 54px; border-radius: 14px;
    background: linear-gradient(135deg, rgba(76,141,255,0.15), rgba(139,92,246,0.15));
    margin-bottom: 16px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px;
  }
  .empty-state p { margin: 4px 0; font-size: 13px; }
  .empty-state .empty-title { color: var(--text); font-size: 15px; font-weight: 600; margin-bottom: 4px; }

  /* Drag overlay */
  .drop-overlay {
    position: absolute;
    inset: 14px;
    border: 2px dashed var(--accent-1);
    border-radius: var(--radius-lg);
    background: rgba(76,141,255,0.06);
    display: none;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    font-weight: 600;
    color: var(--accent-1);
    pointer-events: none;
    z-index: 5;
  }
  .content.dragging .drop-overlay { display: flex; }

  /* Toast */
  .toast {
    position: fixed;
    bottom: 22px;
    right: 22px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    padding: 12px 16px;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.35);
    transform: translateY(120%);
    transition: transform 0.25s ease;
    z-index: 30;
    max-width: 320px;
  }
  .toast.show { transform: translateY(0); }
  .toast .dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
  .toast.error .dot { background: var(--danger); box-shadow: 0 0 6px var(--danger); }
  .toast.ok .dot { background: var(--success); box-shadow: 0 0 6px var(--success); }

  /* Selection bar */
  .selection-bar {
    display: none;
    align-items: center;
    gap: 14px;
    padding: 10px 26px;
    border-bottom: 1px solid var(--border);
    background: var(--surface);
    font-size: 13px;
  }
  .selection-bar.show { display: flex; }
  .selection-bar .btn { padding: 6px 12px; }
  .selection-count { font-weight: 600; }
  .selection-clear { color: var(--text-muted); cursor: pointer; margin-left: auto; }

  /* Panneau de progression d'upload */
  .upload-panel {
    position: fixed;
    bottom: 22px;
    right: 22px;
    width: 320px;
    max-height: 340px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-md);
    box-shadow: 0 12px 30px rgba(0,0,0,0.4);
    display: none;
    flex-direction: column;
    z-index: 28;
    overflow: hidden;
  }
  .upload-panel.show { display: flex; }
  .upload-panel-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 14px; border-bottom: 1px solid var(--border);
    font-size: 13px; font-weight: 600;
  }
  .upload-panel-close {
    background: none; border: none; color: var(--text-muted);
    cursor: pointer; font-size: 14px; padding: 2px 4px;
  }
  .upload-panel-close:hover { color: var(--text); }
  .upload-list { overflow-y: auto; padding: 8px 10px; display: flex; flex-direction: column; gap: 10px; }
  .upload-item-top {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 12px; gap: 8px; margin-bottom: 5px;
  }
  .upload-item-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .upload-item-status { color: var(--text-muted); flex-shrink: 0; font-size: 11px; }
  .upload-item-bar {
    height: 4px; border-radius: 3px; background: var(--surface-3); overflow: hidden;
  }
  .upload-item-bar-fill {
    height: 100%; border-radius: 3px;
    background: linear-gradient(90deg, var(--accent-1), var(--accent-2));
    transition: width 0.15s ease;
  }
  .upload-item-bar-fill.done { background: var(--success); }
  .upload-item-bar-fill.error { background: var(--danger); }
  .upload-item-error { color: var(--danger); font-size: 11px; margin-top: 3px; }
  .upload-item-detail { color: var(--text-muted); font-size: 10.5px; margin-top: 4px; }

  .hidden { display: none !important; }

  .sidebar-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(4,6,12,0.55);
    z-index: 18;
  }

  .menu-toggle-btn {
    display: none;
  }

  /* ---------- Responsive (tablette / mobile) ---------- */
  @media (max-width: 900px) {
    .app {
      grid-template-columns: 1fr;
    }

    .sidebar {
      position: fixed;
      inset: 0 auto 0 0;
      width: 260px;
      z-index: 19;
      background: var(--bg);
      transform: translateX(-100%);
      transition: transform 0.2s ease;
      box-shadow: 10px 0 30px rgba(0,0,0,0.4);
    }
    .sidebar.open { transform: translateX(0); }

    .sidebar-overlay.show { display: block; }

    .menu-toggle-btn {
      display: flex;
    }

    .topbar {
      padding: 14px 16px;
      gap: 10px;
      flex-wrap: wrap;
    }
    .breadcrumb { order: 2; width: 100%; }
    .topbar-actions { order: 1; margin-left: auto; flex-wrap: wrap; justify-content: flex-end; row-gap: 8px; }

    .search-box { width: 120px; }

    .content { padding: 16px; }

    .quick-access-grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); }
    .file-grid { grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); }

    .file-row {
      grid-template-columns: 20px 24px 1fr 70px 28px 28px;
    }
    .file-row .file-meta:nth-child(5),
    .row-head-grid span:nth-child(5) {
      display: none; /* masque la colonne "Modifié" pour gagner de la place */
    }
    .row-head-grid { grid-template-columns: 20px 24px 1fr 70px 28px 28px; }
  }

  @media (max-width: 520px) {
    .search-box { width: 90px; font-size: 12px; }
    .view-toggle button { padding: 7px 8px; font-size: 11.5px; }
    .btn-primary { padding: 8px 10px; font-size: 12.5px; }
    .file-grid { grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); gap: 10px; }
    .file-row {
      grid-template-columns: 18px 22px 1fr 26px;
    }
    .file-row .file-meta,
    .row-head-grid span:nth-child(4) {
      display: none; /* masque aussi "Taille" sur très petit écran */
    }
    .row-head-grid { grid-template-columns: 18px 22px 1fr 26px; }
    .file-row .star-btn { display: none; } /* garde juste le menu "..." */
  }

  ::-webkit-scrollbar { width: 10px; }
  ::-webkit-scrollbar-thumb { background: var(--surface-2); border-radius: 6px; border: 2px solid var(--bg); }
</style>
</head>
<body>

<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-mark"></div>
      Nounours Files
    </div>

    <div class="nav-section">
      <div class="nav-item active" data-nav="all"><span class="nav-icon">▤</span> Tous les fichiers</div>
      <div class="nav-item" data-nav="recent"><span class="nav-icon">◷</span> Récents</div>
      <div class="nav-item" data-nav="favorites"><span class="nav-icon">★</span> Favoris</div>
    </div>

    <div class="drives-section">
      <div class="section-label">Disques</div>
      <div id="drivesList"></div>
    </div>

    <div class="sidebar-bottom">
      <div class="storage-summary">
        <div class="drive-top"><span class="drive-letter" style="font-size:12px;">Stockage total</span></div>
        <div class="drive-bar"><div class="drive-bar-fill" id="totalStorageBar" style="width:0%"></div></div>
        <div class="drive-detail" id="totalStorageDetail">Calcul en cours...</div>
      </div>
      <div class="sidebar-user" id="settingsBtn">
        <div class="avatar">D</div>
        <div class="sidebar-user-info">
          <span class="sidebar-user-name">DidiKaria</span>
          <span class="sidebar-user-status"><span class="status-dot" id="statusDot"></span><span id="statusText">Connexion...</span></span>
        </div>
      </div>
      <a href="?logout=1" class="logout-link">Se déconnecter</a>
    </div>
  </aside>

  <main class="main">
    <div class="topbar">
      <div class="breadcrumb" id="breadcrumb"></div>
      <div class="topbar-actions">
        <button class="icon-btn menu-toggle-btn" id="menuToggleBtn" title="Menu">☰</button>
        <button class="icon-btn" id="refreshBtn" title="Rafraîchir ce dossier">⟳</button>
        <input type="text" class="search-box" id="searchBox" placeholder="Filtrer les fichiers...">
        <div class="view-toggle">
          <button id="viewGridBtn">Grille</button>
          <button id="viewListBtn" class="active">Liste</button>
        </div>
        <div class="new-menu-wrap">
          <button class="btn btn-primary" id="newBtn">+ Nouveau</button>
          <div class="new-menu" id="newMenu">
            <button class="new-menu-item" id="newFolderBtn"><span class="mi">📁</span> Nouveau dossier</button>
            <button class="new-menu-item" id="uploadBtn"><span class="mi">⭱</span> Importer un fichier</button>
            <button class="new-menu-item" id="uploadFolderBtn"><span class="mi">📂</span> Importer un dossier</button>
          </div>
        </div>
        <input type="file" id="fileInput" class="hidden" multiple>
        <input type="file" id="folderInput" class="hidden" webkitdirectory directory multiple>
      </div>
    </div>

    <div class="selection-bar" id="selectionBar">
      <span class="selection-count" id="selectionCount">0 sélectionné(s)</span>
      <button class="btn btn-primary" id="deleteSelectedBtn">Supprimer</button>
      <span class="selection-clear" id="clearSelectionBtn">Annuler</span>
    </div>

    <div class="content" id="content">
      <div class="drop-overlay" id="dropOverlay">Dépose ton fichier pour l'envoyer ici</div>

      <div class="quick-access hidden" id="quickAccess">
        <div class="section-label" style="padding-left:0;">Accès rapide</div>
        <div class="quick-access-grid" id="quickAccessGrid"></div>
      </div>

      <div id="fileArea"></div>
    </div>
  </main>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="toast" id="toast"><span class="dot"></span><span id="toastText"></span></div>

<div class="upload-panel" id="uploadPanel">
  <div class="upload-panel-header">
    <span id="uploadPanelTitle">Import en cours</span>
    <button class="upload-panel-close" id="uploadPanelClose">✕</button>
  </div>
  <div class="upload-list" id="uploadList"></div>
</div>

<div class="ctx-menu" id="ctxMenu">
  <button class="ctx-item" id="ctxDownload"><span class="mi">⭳</span> Télécharger</button>
  <button class="ctx-item" id="ctxRename"><span class="mi">✎</span> Renommer</button>
  <button class="ctx-item" id="ctxStar"><span class="mi">★</span> Favori</button>
  <button class="ctx-item danger" id="ctxDelete"><span class="mi">✕</span> Supprimer</button>
</div>

<div class="new-menu" id="settingsModal" style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); min-width:320px; padding:18px; display:none; flex-direction:column; gap:12px;">
  <div style="font-family:'Sora',sans-serif; font-weight:700; font-size:15px;">Connexion à l'agent</div>
  <div style="display:flex; flex-direction:column; gap:6px;">
    <label style="font-size:11.5px; color:var(--text-muted);">URL de l'agent</label>
    <input type="text" id="cfgUrl" style="background:var(--surface-3); border:1px solid var(--border); border-radius:6px; padding:8px 10px; color:var(--text); font-family:'Inter',sans-serif; font-size:13px;">
  </div>
  <div style="display:flex; flex-direction:column; gap:6px;">
    <label style="font-size:11.5px; color:var(--text-muted);">Jeton</label>
    <input type="text" id="cfgToken" style="background:var(--surface-3); border:1px solid var(--border); border-radius:6px; padding:8px 10px; color:var(--text); font-family:'Inter',sans-serif; font-size:13px;">
  </div>
  <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:4px;">
    <button class="btn" style="background:var(--surface-3); color:var(--text);" id="cfgCancel">Annuler</button>
    <button class="btn btn-primary" id="cfgSave">Enregistrer</button>
  </div>
</div>
<div id="settingsBackdrop" style="position:fixed; inset:0; background:rgba(4,6,12,0.6); display:none; z-index:29;"></div>

<script>
// ---------- État ----------
let config = {
  url: <?= json_encode(AGENT_URL) ?>,
  token: <?= json_encode(AGENT_TOKEN) ?>
};
let drives = [];
let diskInfoCache = {};
let currentDrive = null;
let currentPath = '';
let currentItems = [];
let viewMode = 'list';
let currentNav = 'all';
let selectedKeys = new Set();
let ctxTargetItem = null;

const favKey = 'nounoursfiles_favorites';
const recentKey = 'nounoursfiles_recent';

function loadFavorites() {
  try { return new Set(JSON.parse(localStorage.getItem(favKey) || '[]')); } catch (e) { return new Set(); }
}
function saveFavorites(set) {
  localStorage.setItem(favKey, JSON.stringify([...set]));
}
let favorites = loadFavorites();

function loadRecent() {
  try { return JSON.parse(localStorage.getItem(recentKey) || '[]'); } catch (e) { return []; }
}
function saveRecent(list) {
  localStorage.setItem(recentKey, JSON.stringify(list.slice(0, 8)));
}
function pushRecent(entry) {
  let list = loadRecent().filter(r => r.key !== entry.key);
  list.unshift(entry);
  saveRecent(list);
  renderQuickAccess();
}

function itemKey(drive, path, name) {
  return drive + '::' + (path ? path + '/' : '') + name;
}

// ---------- Utilitaires ----------
function formatSize(bytes) {
  if (bytes === null || bytes === undefined) return '—';
  const units = ['o', 'Ko', 'Mo', 'Go', 'To'];
  let i = 0, val = bytes;
  while (val >= 1024 && i < units.length - 1) { val /= 1024; i++; }
  return val.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
}

function formatSpeed(bytesPerSec) {
  if (!bytesPerSec || bytesPerSec <= 0) return '';
  return formatSize(bytesPerSec) + '/s';
}

function formatEta(bytesPerSec, remainingBytes) {
  if (!bytesPerSec || bytesPerSec <= 0 || remainingBytes <= 0) return '';
  const seconds = remainingBytes / bytesPerSec;
  if (seconds < 60) return Math.ceil(seconds) + ' s restantes';
  if (seconds < 3600) return Math.ceil(seconds / 60) + ' min restantes';
  return (seconds / 3600).toFixed(1) + ' h restantes';
}

function formatDate(ts) {
  if (!ts) return '—';
  const d = new Date(ts * 1000);
  return d.toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
}

function iconFor(item) {
  if (item.type === 'dir') return '📁';
  const ext = (item.name.split('.').pop() || '').toLowerCase();
  const map = {
    pdf: '📕', doc: '📄', docx: '📄', txt: '📄',
    jpg: '🖼️', jpeg: '🖼️', png: '🖼️', gif: '🖼️',
    mp4: '🎬', mkv: '🎬', avi: '🎬',
    mp3: '🎵', wav: '🎵',
    zip: '🗜️', rar: '🗜️', '7z': '🗜️',
    xlsx: '📊', csv: '📊',
  };
  return map[ext] || '📄';
}

function showToast(message, type = 'ok') {
  const toast = document.getElementById('toast');
  const text = document.getElementById('toastText');
  toast.className = 'toast show ' + type;
  text.textContent = message;
  clearTimeout(showToast._t);
  showToast._t = setTimeout(() => toast.classList.remove('show'), 3200);
}

async function api(action, params = {}, options = {}) {
  const url = new URL(config.url);
  url.searchParams.set('action', action);
  url.searchParams.set('token', config.token);
  Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));

  const response = await fetch(url.toString(), options);
  if (!response.ok && !options.rawResponse) {
    let msg = 'Erreur (' + response.status + ')';
    try { const j = await response.json(); msg = j.error || msg; } catch (e) {}
    throw new Error(msg);
  }
  if (options.rawResponse) return response;
  return response.json();
}

// ---------- Chargement des disques ----------
async function loadDrives() {
  try {
    const [drivesRes, diskInfoRes] = await Promise.all([
      api('drives'),
      api('diskinfo').catch(() => ({ disks: {} }))
    ]);
    drives = drivesRes.drives;
    diskInfoCache = diskInfoRes.disks || {};
    renderDrives();
    renderTotalStorage();
    if (drivesRes.pcOnline === false) {
      pcWasOffline = true;
      setStatus('pc-offline');
      showPcOffline();
      return;
    }
    pcWasOffline = false;
    setStatus(true);
    if (!currentDrive && drives.length) selectDrive(drives[0]);
  } catch (err) {
    setStatus(false);
    showToast('Connexion à l\'agent impossible : ' + err.message, 'error');
  }
}

function renderDrives() {
  const container = document.getElementById('drivesList');
  container.innerHTML = '';
  drives.forEach(letter => {
    const info = diskInfoCache[letter] || {};
    const pct = (info.total && info.used !== null) ? Math.round((info.used / info.total) * 100) : 0;
    const card = document.createElement('div');
    card.className = 'drive-card' + (letter === currentDrive && currentNav === 'all' ? ' active' : '');
    card.innerHTML = `
      <div class="drive-top">
        <span class="drive-letter">Disque ${letter}</span>
        <span class="drive-pct">${info.total ? pct + '%' : ''}</span>
      </div>
      <div class="drive-bar"><div class="drive-bar-fill" style="width:${pct}%"></div></div>
      <div class="drive-detail">${info.total ? formatSize(info.free) + ' libres sur ' + formatSize(info.total) : 'Info indisponible'}</div>
    `;
    card.addEventListener('click', () => { currentNav = 'all'; setActiveNav('all'); selectDrive(letter); closeSidebar(); });
    container.appendChild(card);
  });
}

function renderTotalStorage() {
  let total = 0, used = 0, any = false;
  Object.values(diskInfoCache).forEach(info => {
    if (info.total) { total += info.total; used += (info.used || 0); any = true; }
  });
  const pct = total ? Math.round((used / total) * 100) : 0;
  document.getElementById('totalStorageBar').style.width = pct + '%';
  document.getElementById('totalStorageDetail').textContent = any
    ? formatSize(total - used) + ' libres sur ' + formatSize(total)
    : 'Info indisponible';
}

function setActiveNav(nav) {
  document.querySelectorAll('.nav-item').forEach(el => {
    el.classList.toggle('active', el.dataset.nav === nav);
  });
}

function selectDrive(letter) {
  currentDrive = letter;
  currentPath = '';
  document.querySelectorAll('.drive-card').forEach((el, i) => {
    el.classList.toggle('active', drives[i] === letter && currentNav === 'all');
  });
  clearSelection();
  loadFiles();
}

// ---------- Navigation / fichiers ----------
let loadToken = 0;

async function loadFiles(forceRefresh = false) {
  const myToken = ++loadToken;
  const area = document.getElementById('fileArea');
  area.innerHTML = '<div class="empty-state"><p>Chargement...</p></div>';
  currentItems = [];

  try {
    const firstParams = { drive: currentDrive, path: currentPath, offset: 0, limit: 1000 };
    if (forceRefresh) firstParams.refresh = 1;
    const first = await api('list', firstParams);
    if (myToken !== loadToken) return; // l'utilisateur a navigué ailleurs entre-temps
    currentItems = first.items;
    renderBreadcrumb();
    renderFiles();
    renderQuickAccess();

    // Continue de charger les pages suivantes en arrière-plan si le
    // dossier contient plus d'éléments que la première page.
    let offset = first.items.length;
    let hasMore = first.hasMore;
    while (hasMore) {
      if (myToken !== loadToken) return;
      const next = await api('list', { drive: currentDrive, path: currentPath, offset, limit: 1000 });
      if (myToken !== loadToken) return;
      currentItems = currentItems.concat(next.items);
      offset += next.items.length;
      hasMore = next.hasMore;
      renderFiles();
    }
  } catch (err) {
    if (myToken !== loadToken) return;
    area.innerHTML = '';
    showToast('Erreur de chargement : ' + err.message, 'error');
  }
}

function renderBreadcrumb() {
  const bc = document.getElementById('breadcrumb');
  bc.innerHTML = '';
  const parts = currentPath ? currentPath.split('/').filter(Boolean) : [];

  const rootCrumb = document.createElement('span');
  rootCrumb.className = 'crumb crumb-home' + (parts.length === 0 ? ' current' : '');
  rootCrumb.innerHTML = '⌂&nbsp; Disque ' + currentDrive;
  rootCrumb.addEventListener('click', () => { currentPath = ''; loadFiles(); });
  bc.appendChild(rootCrumb);

  let accum = '';
  parts.forEach((part, idx) => {
    const sep = document.createElement('span');
    sep.className = 'crumb-sep';
    sep.textContent = '›';
    bc.appendChild(sep);

    accum += (accum ? '/' : '') + part;
    const crumb = document.createElement('span');
    crumb.className = 'crumb' + (idx === parts.length - 1 ? ' current' : '');
    crumb.textContent = part;
    const targetPath = accum;
    crumb.addEventListener('click', () => { currentPath = targetPath; loadFiles(); });
    bc.appendChild(crumb);
  });
}

function getVisibleItems() {
  const filter = document.getElementById('searchBox').value.trim().toLowerCase();
  let items = currentItems;
  if (currentNav === 'favorites') {
    items = items.filter(i => favorites.has(itemKey(currentDrive, currentPath, i.name)));
  }
  return items.filter(i => i.name.toLowerCase().includes(filter));
}

// ---------- Rendu par lots (dossiers avec beaucoup d'éléments) ----------
const RENDER_BATCH = 150;
let renderQueue = [];
let renderedCount = 0;

function renderFiles() {
  const area = document.getElementById('fileArea');
  const items = getVisibleItems();

  if (items.length === 0) {
    area.innerHTML = `
      <div class="empty-state">
        <div class="glyph">📂</div>
        <div class="empty-title">Rien à voir ici</div>
        <p>${currentNav === 'favorites' ? 'Aucun favori dans ce dossier.' : "Ce dossier est vide. Importe un fichier ou crée un sous-dossier."}</p>
      </div>`;
    return;
  }

  renderQueue = items;
  renderedCount = 0;
  area.innerHTML = '';

  if (viewMode === 'grid') {
    const grid = document.createElement('div');
    grid.className = 'file-grid';
    grid.id = 'renderTarget';
    area.appendChild(grid);
  } else {
    const list = document.createElement('div');
    list.className = 'file-list';
    list.innerHTML = `
      <div class="row-head row-head-grid">
        <input type="checkbox" class="row-checkbox" id="selectAllBox">
        <span></span><span>Nom</span><span>Taille</span><span>Modifié</span><span></span><span></span>
      </div>
      <div id="renderTarget"></div>`;
    area.appendChild(list);
    document.getElementById('selectAllBox').addEventListener('change', (e) => {
      if (e.target.checked) items.forEach(i => selectedKeys.add(itemKey(currentDrive, currentPath, i.name)));
      else selectedKeys.clear();
      renderFiles();
      updateSelectionBar();
    });
  }

  renderNextBatch();
}

function renderNextBatch() {
  const target = document.getElementById('renderTarget');
  if (!target) return;
  const batch = renderQueue.slice(renderedCount, renderedCount + RENDER_BATCH);
  const frag = document.createDocumentFragment();
  batch.forEach(item => {
    frag.appendChild(viewMode === 'grid' ? buildFileCard(item) : buildFileRow(item));
  });
  target.appendChild(frag);
  renderedCount += batch.length;

  if (renderedCount < renderQueue.length) {
    // Rendu progressif automatique (un lot par frame) : le dossier reste
    // consultable et scrollable pendant que le reste se charge en fond,
    // sans jamais geler la page même avec des milliers d'éléments.
    requestAnimationFrame(renderNextBatch);
  }
}

function buildFileCard(item) {
  const key = itemKey(currentDrive, currentPath, item.name);
  const card = document.createElement('div');
  card.className = 'file-card';
  card.innerHTML = `
    <button class="star-btn ${favorites.has(key) ? 'starred' : ''}" data-key="${key}">★</button>
    <div class="file-icon ${item.type}">${iconFor(item)}</div>
    <div class="file-name">${escapeHtml(item.name)}</div>
    <div class="file-meta">${item.type === 'dir' ? 'Dossier' : formatSize(item.size)}</div>
  `;
  card.addEventListener('click', (e) => {
    if (e.target.closest('.star-btn')) return;
    handleItemClick(item);
  });
  card.addEventListener('contextmenu', (e) => { e.preventDefault(); openCtxMenu(e, item); });
  card.querySelector('.star-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    toggleFavorite(key, item);
  });
  return card;
}

function buildFileRow(item) {
  const key = itemKey(currentDrive, currentPath, item.name);
  const row = document.createElement('div');
  row.className = 'file-row';
  row.innerHTML = `
    <input type="checkbox" class="row-select" ${selectedKeys.has(key) ? 'checked' : ''}>
    <span class="file-icon ${item.type}">${iconFor(item)}</span>
    <span class="file-name">${escapeHtml(item.name)}</span>
    <span class="file-meta">${item.type === 'dir' ? '—' : formatSize(item.size)}</span>
    <span class="file-meta">${formatDate(item.modified)}</span>
    <button class="star-btn ${favorites.has(key) ? 'starred' : ''}" data-key="${key}">★</button>
    <button class="row-menu-btn" title="Options">⋯</button>
  `;
  row.addEventListener('click', (e) => {
    if (e.target.closest('.row-menu-btn') || e.target.closest('.star-btn') || e.target.closest('.row-select')) return;
    handleItemClick(item);
  });
  row.addEventListener('contextmenu', (e) => { e.preventDefault(); openCtxMenu(e, item); });
  row.querySelector('.row-select').addEventListener('change', (e) => {
    if (e.target.checked) selectedKeys.add(key); else selectedKeys.delete(key);
    updateSelectionBar();
  });
  row.querySelector('.star-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    toggleFavorite(key, item);
  });
  row.querySelector('.row-menu-btn').addEventListener('click', (e) => {
    e.stopPropagation();
    openCtxMenu(e, item);
  });
  return row;
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

function toggleFavorite(key, item) {
  if (favorites.has(key)) favorites.delete(key); else favorites.add(key);
  saveFavorites(favorites);
  renderFiles();
}

function handleItemClick(item) {
  if (item.type === 'dir') {
    currentPath = currentPath ? currentPath + '/' + item.name : item.name;
    loadFiles();
  } else {
    downloadItem(item);
  }
}

function downloadItem(item) {
  const path = currentPath ? currentPath + '/' + item.name : item.name;
  pushRecent({ key: itemKey(currentDrive, currentPath, item.name), drive: currentDrive, path: currentPath, name: item.name, type: item.type });
  const url = new URL(config.url);
  url.searchParams.set('action', 'download');
  url.searchParams.set('drive', currentDrive);
  url.searchParams.set('path', path);
  url.searchParams.set('token', config.token);
  window.open(url.toString(), '_blank');
}

function downloadZip(item) {
  const path = currentPath ? currentPath + '/' + item.name : item.name;
  showToast('Préparation de l\'archive .zip... (peut prendre un moment pour un gros dossier)', 'ok');
  const url = new URL(config.url);
  url.searchParams.set('action', 'downloadZip');
  url.searchParams.set('drive', currentDrive);
  url.searchParams.set('path', path);
  url.searchParams.set('token', config.token);
  window.open(url.toString(), '_blank');
}

async function deleteItem(item) {
  if (!confirm(`Supprimer "${item.name}" ?`)) return;
  const path = currentPath ? currentPath + '/' + item.name : item.name;
  try {
    await api('delete', { drive: currentDrive, path });
    showToast('"' + item.name + '" supprimé', 'ok');
    loadFiles();
  } catch (err) {
    showToast('Suppression impossible : ' + err.message, 'error');
  }
}

async function renameItem(item) {
  const newName = prompt('Nouveau nom :', item.name);
  if (!newName || newName === item.name) return;
  const path = currentPath ? currentPath + '/' + item.name : item.name;
  try {
    await api('rename', { drive: currentDrive, path, newName });
    showToast('Renommé en "' + newName + '"', 'ok');
    loadFiles();
  } catch (err) {
    showToast('Renommage impossible : ' + err.message, 'error');
  }
}

// ---------- Sélection multiple ----------
function clearSelection() {
  selectedKeys.clear();
  updateSelectionBar();
}
function updateSelectionBar() {
  const bar = document.getElementById('selectionBar');
  const count = selectedKeys.size;
  document.getElementById('selectionCount').textContent = count + ' sélectionné(s)';
  bar.classList.toggle('show', count > 0);
}
document.getElementById('clearSelectionBtn').addEventListener('click', () => { clearSelection(); renderFiles(); });
document.getElementById('deleteSelectedBtn').addEventListener('click', async () => {
  if (!confirm(`Supprimer ${selectedKeys.size} élément(s) ?`)) return;
  for (const key of selectedKeys) {
    const name = key.split('::').pop().split('/').pop();
    const item = currentItems.find(i => i.name === name);
    if (!item) continue;
    const path = currentPath ? currentPath + '/' + item.name : item.name;
    try { await api('delete', { drive: currentDrive, path }); } catch (e) {}
  }
  showToast('Éléments supprimés', 'ok');
  clearSelection();
  loadFiles();
});

// ---------- Menu contextuel ----------
function openCtxMenu(e, item) {
  ctxTargetItem = item;
  const menu = document.getElementById('ctxMenu');
  const key = itemKey(currentDrive, currentPath, item.name);
  document.getElementById('ctxStar').innerHTML = '<span class="mi">★</span> ' + (favorites.has(key) ? 'Retirer des favoris' : 'Ajouter aux favoris');
  document.getElementById('ctxDownload').style.display = 'flex';
  document.getElementById('ctxDownload').innerHTML = item.type === 'dir'
    ? '<span class="mi">⭳</span> Télécharger (.zip)'
    : '<span class="mi">⭳</span> Télécharger';

  // Affiche le menu hors écran d'abord pour pouvoir mesurer sa taille
  // réelle, puis le repositionne pour qu'il reste toujours visible
  // (jamais coupé sur les bords, y compris sur petit écran).
  menu.style.left = '-9999px';
  menu.style.top = '-9999px';
  menu.classList.add('show');
  const menuRect = menu.getBoundingClientRect();
  const margin = 8;
  let x = e.clientX;
  let y = e.clientY;
  if (x + menuRect.width + margin > window.innerWidth) {
    x = window.innerWidth - menuRect.width - margin;
  }
  if (y + menuRect.height + margin > window.innerHeight) {
    y = window.innerHeight - menuRect.height - margin;
  }
  x = Math.max(margin, x);
  y = Math.max(margin, y);
  menu.style.left = x + 'px';
  menu.style.top = y + 'px';
}
document.addEventListener('click', () => document.getElementById('ctxMenu').classList.remove('show'));
window.addEventListener('resize', () => document.getElementById('ctxMenu').classList.remove('show'));
document.getElementById('content').addEventListener('scroll', () => document.getElementById('ctxMenu').classList.remove('show'));
// Filet de sécurité supplémentaire : capte tous les redimensionnements,
// y compris ceux que l'événement "resize" classique du navigateur peut
// manquer (ex: certains outils de test responsive).
new ResizeObserver(() => document.getElementById('ctxMenu').classList.remove('show')).observe(document.body);
document.getElementById('ctxDownload').addEventListener('click', () => {
  if (!ctxTargetItem) return;
  if (ctxTargetItem.type === 'dir') downloadZip(ctxTargetItem);
  else downloadItem(ctxTargetItem);
});
document.getElementById('ctxRename').addEventListener('click', () => ctxTargetItem && renameItem(ctxTargetItem));
document.getElementById('ctxDelete').addEventListener('click', () => ctxTargetItem && deleteItem(ctxTargetItem));
document.getElementById('ctxStar').addEventListener('click', () => {
  if (!ctxTargetItem) return;
  toggleFavorite(itemKey(currentDrive, currentPath, ctxTargetItem.name), ctxTargetItem);
});

// ---------- Accès rapide ----------
function renderQuickAccess() {
  const wrap = document.getElementById('quickAccess');
  const grid = document.getElementById('quickAccessGrid');
  const recent = loadRecent().slice(0, 6);
  if (!recent.length || currentNav !== 'all') { wrap.classList.add('hidden'); return; }
  wrap.classList.remove('hidden');
  grid.innerHTML = '';
  recent.forEach(entry => {
    const card = document.createElement('div');
    card.className = 'quick-card';
    card.innerHTML = `
      <div class="file-icon ${entry.type}">${entry.type === 'dir' ? '📁' : iconFor({ name: entry.name, type: 'file' })}</div>
      <div class="quick-card-info">
        <div class="quick-card-name">${escapeHtml(entry.name)}</div>
        <div class="quick-card-meta">Disque ${entry.drive}</div>
      </div>
    `;
    card.addEventListener('click', () => {
      currentDrive = entry.drive;
      currentPath = entry.path;
      setActiveNav('all'); currentNav = 'all';
      loadFiles();
    });
    grid.appendChild(card);
  });
}

// ---------- Upload (avec panneau de progression) ----------
let uploadJobs = [];

function renderUploadPanel() {
  const panel = document.getElementById('uploadPanel');
  const list = document.getElementById('uploadList');
  if (uploadJobs.length === 0) { panel.classList.remove('show'); return; }
  panel.classList.add('show');
  const activeCount = uploadJobs.filter(j => j.status === 'uploading').length;
  document.getElementById('uploadPanelTitle').textContent = activeCount > 0
    ? `Import en cours (${activeCount} restant${activeCount > 1 ? 's' : ''})`
    : 'Import terminé';
  list.innerHTML = '';
  uploadJobs.forEach(job => {
    const row = document.createElement('div');
    row.className = 'upload-item';
    const statusLabel = job.status === 'done' ? '✓' : job.status === 'error' ? '✕' : job.progress + '%';
    let detailLine = '';
    if (job.status === 'uploading' && job.total) {
      const parts = [formatSize(job.loaded) + ' / ' + formatSize(job.total)];
      const speedStr = formatSpeed(job.speed);
      if (speedStr) parts.push(speedStr);
      const etaStr = formatEta(job.speed, job.total - job.loaded);
      if (etaStr) parts.push(etaStr);
      detailLine = `<div class="upload-item-detail">${parts.join(' • ')}</div>`;
    }
    row.innerHTML = `
      <div class="upload-item-top">
        <span class="upload-item-name">${escapeHtml(job.name)}</span>
        <span class="upload-item-status">${statusLabel}</span>
      </div>
      <div class="upload-item-bar"><div class="upload-item-bar-fill ${job.status}" style="width:${job.status === 'done' ? 100 : job.progress}%"></div></div>
      ${detailLine}
      ${job.status === 'error' ? `<div class="upload-item-error">${escapeHtml(job.errorMsg || 'Erreur')}</div>` : ''}
    `;
    list.appendChild(row);
  });
}
document.getElementById('uploadPanelClose').addEventListener('click', () => {
  uploadJobs = uploadJobs.filter(j => j.status === 'uploading');
  renderUploadPanel();
});

const UPLOAD_CHUNK_SIZE = 2 * 1024 * 1024; // 2 Mo par morceau (adapté aux connexions lentes/instables)
const UPLOAD_MAX_RETRIES = 4;

function uploadChunkXHR(chunkBlob, drive, path, filename, chunkIndex, totalChunks, offset, onProgress) {
  return new Promise((resolve, reject) => {
    const url = new URL(config.url);
    url.searchParams.set('action', 'uploadChunk');
    url.searchParams.set('token', config.token);
    url.searchParams.set('drive', drive);
    url.searchParams.set('path', path);
    url.searchParams.set('filename', filename);
    url.searchParams.set('chunkIndex', chunkIndex);
    url.searchParams.set('totalChunks', totalChunks);
    url.searchParams.set('offset', offset);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url.toString());
    xhr.setRequestHeader('Content-Type', 'application/octet-stream');
    xhr.timeout = 30000; // 30s par morceau : largement suffisant vu leur petite taille
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) onProgress(e.loaded);
    };
    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) { resolve(); return; }
      let msg = 'Erreur (' + xhr.status + ')';
      try { msg = JSON.parse(xhr.responseText).error || msg; } catch (e) {}
      reject(new Error(msg));
    };
    xhr.ontimeout = () => reject(new Error('Délai dépassé pour ce morceau'));
    xhr.onerror = () => reject(new Error('Erreur réseau'));
    xhr.send(chunkBlob);
  });
}

function delay(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }

async function uploadChunkWithRetry(chunkBlob, drive, path, filename, chunkIndex, totalChunks, offset, onProgress) {
  let lastError;
  for (let attempt = 1; attempt <= UPLOAD_MAX_RETRIES; attempt++) {
    try {
      await uploadChunkXHR(chunkBlob, drive, path, filename, chunkIndex, totalChunks, offset, onProgress);
      return;
    } catch (err) {
      lastError = err;
      if (attempt < UPLOAD_MAX_RETRIES) {
        onProgress(0); // remet la barre de ce morceau à 0 avant de réessayer
        await delay(800 * attempt); // attend un peu plus longtemps à chaque nouvel essai
      }
    }
  }
  throw lastError;
}

async function uploadFileXHR(file, drive, path, onProgress) {
  const totalChunks = Math.max(1, Math.ceil(file.size / UPLOAD_CHUNK_SIZE));
  let uploadedBefore = 0;
  let lastTime = performance.now();
  let lastLoaded = 0;
  let smoothedSpeed = 0;

  for (let i = 0; i < totalChunks; i++) {
    const start = i * UPLOAD_CHUNK_SIZE;
    const end = Math.min(file.size, start + UPLOAD_CHUNK_SIZE);
    const chunk = file.slice(start, end);

    await uploadChunkWithRetry(chunk, drive, path, file.name, i, totalChunks, start, (chunkLoaded) => {
      const loaded = uploadedBefore + chunkLoaded;
      const now = performance.now();
      const elapsed = (now - lastTime) / 1000;
      if (elapsed > 0.2) {
        const instantSpeed = (loaded - lastLoaded) / elapsed;
        smoothedSpeed = smoothedSpeed === 0 ? instantSpeed : (smoothedSpeed * 0.7 + instantSpeed * 0.3);
        lastLoaded = loaded;
        lastTime = now;
      }
      onProgress({
        loaded, total: file.size,
        percent: Math.round((loaded / file.size) * 100),
        speed: smoothedSpeed,
      });
    });

    uploadedBefore += (end - start);
  }

  onProgress({ loaded: file.size, total: file.size, percent: 100, speed: 0 });
}

async function runUploadJobs(jobs, doneMessage) {
  uploadJobs.push(...jobs);
  renderUploadPanel();

  for (const job of jobs) {
    try {
      await uploadFileXHR(job.file, currentDrive, job.destPath, (p) => {
        job.progress = p.percent;
        job.loaded = p.loaded;
        job.total = p.total;
        job.speed = p.speed;
        renderUploadPanel();
      });
      job.status = 'done';
    } catch (err) {
      job.status = 'error';
      job.errorMsg = err.message;
    }
    renderUploadPanel();
  }

  const anyError = jobs.some(j => j.status === 'error');
  showToast(anyError ? 'Certains fichiers n\'ont pas pu être envoyés' : doneMessage, anyError ? 'error' : 'ok');
  loadFiles();
  loadDrives();

  setTimeout(() => {
    uploadJobs = uploadJobs.filter(j => !jobs.includes(j) || j.status === 'error');
    renderUploadPanel();
  }, 3000);
}

async function uploadFiles(fileList) {
  const jobs = Array.from(fileList).map(file => ({
    id: Math.random().toString(36).slice(2), file, name: file.name,
    destPath: currentPath, progress: 0, status: 'uploading',
  }));
  await runUploadJobs(jobs, 'Import terminé');
}

async function uploadFolderFiles(fileList) {
  const files = Array.from(fileList);
  if (!files.length) return;

  // 1) Recense tous les sous-dossiers nécessaires (du plus haut niveau
  // au plus profond) et les crée un par un.
  const folderSet = new Set();
  files.forEach(f => {
    const relPath = f.webkitRelativePath || f.name;
    const parts = relPath.split('/');
    parts.pop();
    let acc = '';
    parts.forEach(p => { acc = acc ? acc + '/' + p : p; folderSet.add(acc); });
  });
  const foldersSorted = [...folderSet].sort((a, b) => a.split('/').length - b.split('/').length);

  showToast('Création de la structure de dossiers...', 'ok');
  for (const folder of foldersSorted) {
    const parts = folder.split('/');
    const name = parts.pop();
    const parentPath = currentPath ? [currentPath, ...parts].join('/') : parts.join('/');
    try {
      await api('mkdir', { drive: currentDrive, path: parentPath, name });
    } catch (err) {
      // Le dossier existe probablement déjà (409) : on continue sans bloquer.
    }
  }

  // 2) Upload chaque fichier dans son sous-dossier correspondant.
  const jobs = files.map(file => {
    const relPath = file.webkitRelativePath || file.name;
    const parts = relPath.split('/');
    parts.pop();
    const subPath = parts.join('/');
    const destPath = currentPath ? (subPath ? currentPath + '/' + subPath : currentPath) : subPath;
    return {
      id: Math.random().toString(36).slice(2), file, name: relPath,
      destPath, progress: 0, status: 'uploading',
    };
  });

  await runUploadJobs(jobs, 'Import du dossier terminé');
}

// ---------- Nouveau dossier ----------
async function createFolder() {
  const name = prompt('Nom du nouveau dossier :');
  if (!name) return;
  try {
    await api('mkdir', { drive: currentDrive, path: currentPath, name });
    showToast('Dossier créé', 'ok');
    loadFiles();
  } catch (err) {
    showToast('Création impossible : ' + err.message, 'error');
  }
}

// ---------- Statut ----------
// state : true (connecté), false (agent injoignable), 'pc-offline' (agent OK mais PC Windows éteint)
function setStatus(state) {
  const color = state === true ? 'var(--success)' : (state === 'pc-offline' ? 'var(--warn, #F5B942)' : 'var(--danger)');
  document.getElementById('statusDot').style.background = color;
  document.getElementById('statusDot').style.boxShadow = '0 0 6px ' + color;
  document.getElementById('statusText').textContent = state === true ? 'Connecté' : (state === 'pc-offline' ? 'PC distant indisponible' : 'Déconnecté');
}

function showPcOffline() {
  document.getElementById('fileArea').innerHTML = `
    <div class="empty-state">
      <div class="glyph">⏻</div>
      <div class="empty-title">PC distant indisponible</div>
      <p>Le PC qui héberge les disques est éteint ou hors réseau. La page se reconnectera toute seule dès qu'il sera de retour.</p>
    </div>`;
}

// Revérifie régulièrement : bascule automatiquement quand le PC revient ou s'éteint.
let pcWasOffline = false;
setInterval(async () => {
  try {
    const h = await api('health');
    if (!h.pcOnline && !pcWasOffline) { pcWasOffline = true; setStatus('pc-offline'); showPcOffline(); }
    else if (h.pcOnline && pcWasOffline) { await loadDrives(); if (currentDrive) loadFiles(); }
  } catch (e) { /* agent injoignable : géré par loadDrives */ }
}, 20000);

// ---------- Navigation latérale ----------
document.querySelectorAll('.nav-item').forEach(el => {
  el.addEventListener('click', () => {
    currentNav = el.dataset.nav;
    setActiveNav(currentNav);
    closeSidebar();
    document.querySelectorAll('.drive-card').forEach(c => c.classList.remove('active'));
    if (currentNav === 'recent') {
      renderRecentView();
    } else {
      renderFiles();
      renderQuickAccess();
    }
  });
});

function renderRecentView() {
  document.getElementById('quickAccess').classList.add('hidden');
  const area = document.getElementById('fileArea');
  const recent = loadRecent();
  if (!recent.length) {
    area.innerHTML = `
      <div class="empty-state">
        <div class="glyph">◷</div>
        <div class="empty-title">Aucun fichier récent</div>
        <p>Les fichiers que tu ouvres ou télécharges apparaîtront ici.</p>
      </div>`;
    return;
  }
  area.innerHTML = '';
  const grid = document.createElement('div');
  grid.className = 'file-grid';
  recent.forEach(entry => {
    const card = document.createElement('div');
    card.className = 'file-card';
    card.innerHTML = `
      <div class="file-icon ${entry.type}">${entry.type === 'dir' ? '📁' : iconFor({ name: entry.name, type: 'file' })}</div>
      <div class="file-name">${escapeHtml(entry.name)}</div>
      <div class="file-meta">Disque ${entry.drive}</div>
    `;
    card.addEventListener('click', () => {
      currentDrive = entry.drive;
      currentPath = entry.path;
      setActiveNav('all'); currentNav = 'all';
      loadFiles();
    });
    grid.appendChild(card);
  });
  area.appendChild(grid);
}

// ---------- Menu mobile (tiroir latéral) ----------
const sidebar = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');
function openSidebar() { sidebar.classList.add('open'); sidebarOverlay.classList.add('show'); }
function closeSidebar() { sidebar.classList.remove('open'); sidebarOverlay.classList.remove('show'); }
document.getElementById('menuToggleBtn').addEventListener('click', () => {
  sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
});
sidebarOverlay.addEventListener('click', closeSidebar);

// ---------- Événements UI ----------
document.getElementById('viewGridBtn').addEventListener('click', () => {
  viewMode = 'grid';
  document.getElementById('viewGridBtn').classList.add('active');
  document.getElementById('viewListBtn').classList.remove('active');
  renderFiles();
});
document.getElementById('viewListBtn').addEventListener('click', () => {
  viewMode = 'list';
  document.getElementById('viewListBtn').classList.add('active');
  document.getElementById('viewGridBtn').classList.remove('active');
  renderFiles();
});

document.getElementById('searchBox').addEventListener('input', renderFiles);
document.getElementById('refreshBtn').addEventListener('click', () => {
  if (currentNav === 'all' && currentDrive) {
    showToast('Rafraîchissement du dossier...', 'ok');
    loadFiles(true);
  }
});

document.getElementById('newBtn').addEventListener('click', (e) => {
  e.stopPropagation();
  document.getElementById('newMenu').classList.toggle('show');
});
document.addEventListener('click', () => document.getElementById('newMenu').classList.remove('show'));
document.getElementById('newFolderBtn').addEventListener('click', createFolder);
document.getElementById('uploadBtn').addEventListener('click', () => document.getElementById('fileInput').click());
document.getElementById('fileInput').addEventListener('change', (e) => {
  if (e.target.files.length) uploadFiles(e.target.files);
  e.target.value = '';
});
document.getElementById('uploadFolderBtn').addEventListener('click', () => document.getElementById('folderInput').click());
document.getElementById('folderInput').addEventListener('change', (e) => {
  if (e.target.files.length) uploadFolderFiles(e.target.files);
  e.target.value = '';
});

const content = document.getElementById('content');
content.addEventListener('dragover', (e) => { e.preventDefault(); content.classList.add('dragging'); });
content.addEventListener('dragleave', (e) => { if (e.target === content) content.classList.remove('dragging'); });
content.addEventListener('drop', (e) => {
  e.preventDefault();
  content.classList.remove('dragging');
  if (e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files);
});

// Settings modal
const modal = document.getElementById('settingsModal');
const backdrop = document.getElementById('settingsBackdrop');
document.getElementById('settingsBtn').addEventListener('click', () => {
  document.getElementById('cfgUrl').value = config.url;
  document.getElementById('cfgToken').value = config.token;
  modal.style.display = 'flex';
  backdrop.style.display = 'block';
});
document.getElementById('cfgCancel').addEventListener('click', () => { modal.style.display = 'none'; backdrop.style.display = 'none'; });
backdrop.addEventListener('click', () => { modal.style.display = 'none'; backdrop.style.display = 'none'; });
document.getElementById('cfgSave').addEventListener('click', () => {
  config.url = document.getElementById('cfgUrl').value.trim().replace(/\/?$/, '/');
  config.token = document.getElementById('cfgToken').value.trim();
  modal.style.display = 'none';
  backdrop.style.display = 'none';
  drives = []; currentDrive = null;
  loadDrives();
});

// ---------- Démarrage ----------
loadDrives();
</script>

</body>
</html>
