<?php
/**
 * Agent Nounours Files
 * ---------------------
 * API locale exposant les disques définis dans config.php.
 * Version LXC Proxmox : les disques du PC Windows sont des partages SMB
 * montés par l'hôte Proxmox puis transmis au conteneur. L'agent tourne
 * derrière nginx + php-fpm, lui-même derrière Nginx Proxy Manager.
 *
 * Actions disponibles (paramètre ?action=...) :
 *   - drives              : liste les disques configurés
 *   - list    (drive, path) : liste le contenu d'un dossier
 *   - download(drive, path) : télécharge un fichier
 *   - upload  (drive, path) : upload un fichier (POST multipart, champ "file")
 *   - mkdir   (drive, path, name) : crée un dossier
 *   - delete  (drive, path) : supprime un fichier ou un dossier vide
 */

require_once __DIR__ . '/config.php';

// Empêche les warnings/notices PHP (ex: dossiers système sans permission)
// de s'afficher et de corrompre les réponses JSON. On bufferise la sortie
// et on la nettoie juste avant chaque réponse JSON.
error_reporting(E_ALL);
ini_set('display_errors', '0');
// Outil personnel mono-utilisateur : pas de limite de temps ni de mémoire.
// Nécessaire pour les gros transferts (ISO, archives volumineuses...).
set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '-1');
ob_start();

header('Access-Control-Allow-Origin: *'); // à restreindre à didikaria.fr en prod
header('Access-Control-Allow-Headers: X-Auth-Token, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---- Authentification par jeton ----
function checkAuth(): void
{
    $token = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? ($_GET['token'] ?? '');
    if (!hash_equals(AUTH_TOKEN, $token)) {
        jsonError('Non autorisé', 401);
    }
}

function jsonError(string $message, int $code = 400): void
{
    if (ob_get_length() !== false) { ob_clean(); }
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

function jsonSuccess($data): void
{
    if (ob_get_length() !== false) { ob_clean(); }
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Résout un chemin (drive + sous-chemin relatif) en chemin réel absolu,
 * en empêchant toute sortie du disque (protection path traversal).
 */
// ---- Disponibilité du PC Windows (serveur SMB) ----
// Test rapide du port 445 : évite qu'une requête reste bloquée de longues
// secondes sur un partage SMB dont le PC est éteint.
function pcOnline(): bool
{
    static $online = null;
    if ($online !== null) return $online;
    if (!defined('SMB_HOST') || SMB_HOST === '') return $online = true;
    $fp = @fsockopen(SMB_HOST, 445, $errno, $errstr, 1.5);
    if ($fp) { fclose($fp); return $online = true; }
    return $online = false;
}

function requirePcOnline(): void
{
    if (!pcOnline()) {
        jsonError('PC distant indisponible (éteint ou hors réseau)', 503);
    }
}

// Un disque est « monté » si son dossier est sur un autre système de fichiers
// que son dossier parent. Sinon c'est le dossier vide du conteneur : le
// partage SMB n'est pas (encore) monté sur l'hôte.
function driveMounted(string $root): bool
{
    if (!defined('REQUIRE_MOUNT') || !REQUIRE_MOUNT) return true;
    $a = @stat($root);
    $b = @stat(dirname($root));
    return $a !== false && $b !== false && $a['dev'] !== $b['dev'];
}

function normalizeSlashes(string $p): string
{
    return rtrim(str_replace('\\', '/', $p), '/');
}

function isInside(string $path, string $root): bool
{
    $path = normalizeSlashes($path);
    $root = normalizeSlashes($root);
    return $path === $root || strpos($path, $root . '/') === 0;
}

function resolvePath(string $drive, string $relativePath): string
{
    $drives = DRIVES;
    if (!isset($drives[$drive])) {
        jsonError("Disque inconnu : $drive", 404);
    }

    $root = normalizeSlashes($drives[$drive]);
    $relativePath = trim(str_replace('\\', '/', $relativePath), '/');

    // Interdit toute remontée de dossier, y compris pour un chemin qui
    // n'existe pas encore (mkdir, upload) où realpath() ne peut rien vérifier.
    foreach (explode('/', $relativePath) as $segment) {
        if ($segment === '..' || $segment === '.' || strpos($segment, "\0") !== false) {
            jsonError('Chemin invalide', 400);
        }
    }

    if (!driveMounted($root)) {
        jsonError("Le disque $drive n'est pas monté (PC éteint ou partage indisponible)", 503);
    }

    $candidate = $relativePath === '' ? $root : $root . '/' . $relativePath;
    $realRoot = realpath($root);
    $realCandidate = realpath($candidate);

    if ($realRoot === false) {
        jsonError("Le disque $drive est introuvable", 503);
    }

    // Chemin pas encore existant (ex: mkdir) : on valide le dossier parent.
    if ($realCandidate === false) {
        $realParent = realpath(dirname($candidate));
        if ($realParent === false || !isInside($realParent, $realRoot)) {
            jsonError('Chemin invalide', 400);
        }
        return $candidate;
    }

    if (!isInside($realCandidate, $realRoot)) {
        jsonError('Accès refusé en dehors du disque', 403);
    }

    return $realCandidate;
}

checkAuth();

// ---- Cache de listings (fichier JSON par disque) ----
function cacheFile(string $drive): string
{
    return CACHE_DIR . '/' . $drive . '.json';
}

function loadDriveCache(string $drive): array
{
    $file = cacheFile($drive);
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function saveDriveCache(string $drive, array $data): void
{
    if (!is_dir(CACHE_DIR)) { @mkdir(CACHE_DIR, 0777, true); }
    @file_put_contents(cacheFile($drive), json_encode($data), LOCK_EX);
}

// Découpe un chemin relatif "dossier/sous-dossier/nom" en [parent, nom]
function splitParentName(string $relPath): array
{
    $relPath = trim(str_replace('\\', '/', $relPath), '/');
    if ($relPath === '') return ['', ''];
    $slash = strrpos($relPath, '/');
    if ($slash === false) return ['', $relPath];
    return [substr($relPath, 0, $slash), substr($relPath, $slash + 1)];
}

function cacheUpsertItem(string $drive, string $path, string $name, array $itemData): void
{
    $cache = loadDriveCache($drive);
    if (!isset($cache[$path])) return; // dossier jamais scanné : rien à mettre à jour
    $cache[$path]['items'][$name] = $itemData;
    saveDriveCache($drive, $cache);
}

function cacheRemoveItem(string $drive, string $path, string $name): void
{
    $cache = loadDriveCache($drive);
    if (isset($cache[$path]['items'][$name])) {
        unset($cache[$path]['items'][$name]);
        saveDriveCache($drive, $cache);
    }
}

function cacheRenameItem(string $drive, string $path, string $oldName, string $newName, array $itemData): void
{
    $cache = loadDriveCache($drive);
    if (!isset($cache[$path])) return;
    unset($cache[$path]['items'][$oldName]);
    $cache[$path]['items'][$newName] = $itemData;
    saveDriveCache($drive, $cache);
}

// Ajoute récursivement le contenu d'un dossier à une archive ZIP.
// Ignore silencieusement les sous-dossiers illisibles ou les entrées
// système listées dans SKIP_ENTRIES, plutôt que de faire échouer
// toute l'archive à cause d'un seul élément inaccessible.
function addFolderToZip(ZipArchive $zip, string $realBase, string $zipBase, string $relative = ''): void
{
    $currentReal = $relative === '' ? $realBase : $realBase . '/' . $relative;
    $entries = @scandir($currentReal);
    if ($entries === false) return;

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (in_array(strtolower($entry), SKIP_ENTRIES, true)) continue;

        $entryReal = $currentReal . '/' . $entry;
        $entryRelative = $relative === '' ? $entry : $relative . '/' . $entry;

        $stat = @stat($entryReal);
        if ($stat === false) continue;
        $isDir = ($stat['mode'] & 0170000) === 0040000;

        if ($isDir) {
            $zip->addEmptyDir($zipBase . '/' . $entryRelative);
            addFolderToZip($zip, $realBase, $zipBase, $entryRelative);
        } else {
            @$zip->addFile($entryReal, $zipBase . '/' . $entryRelative);
        }
    }
}

$action = $_GET['action'] ?? '';

// Toutes les actions qui touchent aux disques exigent que le PC réponde.
if (!in_array($action, ['drives', 'diskinfo', 'health'], true)) {
    requirePcOnline();
}

switch ($action) {

    case 'health':
        jsonSuccess(['agent' => true, 'pcOnline' => pcOnline()]);
        break;

    case 'drives':
        jsonSuccess(['drives' => array_keys(DRIVES), 'pcOnline' => pcOnline()]);
        break;

    case 'diskinfo': {
        $info = [];
        $online = pcOnline();
        foreach (DRIVES as $letter => $root) {
            if (!$online || !driveMounted(normalizeSlashes($root))) {
                $info[$letter] = ['total' => null, 'free' => null, 'used' => null];
                continue;
            }
            $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';
            $total = @disk_total_space($normalizedRoot);
            $free = @disk_free_space($normalizedRoot);
            $info[$letter] = [
                'total' => $total !== false ? $total : null,
                'free' => $free !== false ? $free : null,
                'used' => ($total !== false && $free !== false) ? $total - $free : null,
            ];
        }
        jsonSuccess(['disks' => $info]);
        break;
    }

    case 'list': {
        $drive = $_GET['drive'] ?? '';
        $path = $_GET['path'] ?? '';
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $limit = min(1000, max(50, (int)($_GET['limit'] ?? 500)));
        $forceRefresh = isset($_GET['refresh']);
        $real = resolvePath($drive, $path);

        if (!is_dir($real)) {
            jsonError('Dossier introuvable', 404);
        }

        $cache = loadDriveCache($drive);
        $entry = $cache[$path] ?? null;
        $isFresh = $entry && !empty($entry['complete'])
            && (time() - ($entry['scannedAt'] ?? 0) < CACHE_TTL)
            && !$forceRefresh;

        if ($isFresh) {
            // Dossier déjà scanné récemment : réponse directe depuis le
            // cache, sans toucher au disque du tout.
            $names = array_keys($entry['items']);
            sort($names, SORT_FLAG_CASE | SORT_STRING);
            $total = count($names);
            $pageNames = array_slice($names, $offset, $limit);
            $items = [];
            foreach ($pageNames as $name) {
                $items[] = array_merge(['name' => $name], $entry['items'][$name]);
            }
            jsonSuccess([
                'drive' => $drive, 'path' => $path, 'items' => $items,
                'offset' => $offset, 'limit' => $limit, 'total' => $total,
                'hasMore' => ($offset + $limit) < $total,
                'cached' => true,
            ]);
            break;
        }

        // Cache absent, périmé, ou scan précédent incomplet : on relit le
        // dossier. Si on repart de la première page, on repart d'un cache
        // vide pour ce dossier (ça permet aussi de détecter les fichiers
        // supprimés en dehors de l'agent, ex: via l'Explorateur Windows).
        $persistCache = true;
        if ($offset === 0) {
            $cache[$path] = ['items' => [], 'complete' => false, 'scannedAt' => null];
            saveDriveCache($drive, $cache);
            $entry = $cache[$path];
        } elseif ($entry === null) {
            // Page demandée directement sans être passé par offset=0 :
            // on scanne sans écrire dans le cache pour ne pas le corrompre.
            $entry = ['items' => [], 'complete' => false, 'scannedAt' => null];
            $persistCache = false;
        }

        $names = [];
        foreach (scandir($real) as $scanEntry) {
            if ($scanEntry === '.' || $scanEntry === '..') continue;
            if (in_array(strtolower($scanEntry), SKIP_ENTRIES, true)) continue;
            $names[] = $scanEntry;
        }
        sort($names, SORT_FLAG_CASE | SORT_STRING);
        $total = count($names);
        $pageNames = array_slice($names, $offset, $limit);

        $items = [];
        foreach ($pageNames as $name) {
            $fullPath = $real . '/' . $name;
            $stat = @stat($fullPath);
            if ($stat === false) continue;
            $isDir = ($stat['mode'] & 0170000) === 0040000;
            $data = ['type' => $isDir ? 'dir' : 'file', 'size' => $isDir ? null : $stat['size'], 'modified' => $stat['mtime']];
            $items[] = array_merge(['name' => $name], $data);
            $entry['items'][$name] = $data;
        }

        $hasMore = ($offset + $limit) < $total;
        if (!$hasMore) {
            $entry['complete'] = true;
            $entry['scannedAt'] = time();
        }

        if ($persistCache) {
            $cache = loadDriveCache($drive); // relecture au cas où modifié entre-temps
            $cache[$path] = $entry;
            saveDriveCache($drive, $cache);
        }

        jsonSuccess([
            'drive' => $drive, 'path' => $path, 'items' => $items,
            'offset' => $offset, 'limit' => $limit, 'total' => $total,
            'hasMore' => $hasMore, 'cached' => false,
        ]);
        break;
    }

    case 'download': {
        $drive = $_GET['drive'] ?? '';
        $path = $_GET['path'] ?? '';
        $real = resolvePath($drive, $path);

        if (!is_file($real)) {
            jsonError('Fichier introuvable', 404);
        }

        // Coupe la mise en tampon de sortie (utile pour protéger le JSON,
        // mais catastrophique ici : elle chargerait tout le fichier en
        // mémoire avant de l'envoyer). On la désactive pour streamer
        // directement, et on retire la limite de temps pour les gros
        // fichiers/connexions lentes.
        if (ob_get_length() !== false) { ob_end_clean(); }
        set_time_limit(0);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($real) . '"');
        header('Content-Length: ' . filesize($real));
        readfile($real);
        exit;
    }

    case 'downloadZip': {
        if (!class_exists('ZipArchive')) {
            jsonError('Extension PHP zip non disponible sur ce serveur', 500);
        }
        $drive = $_GET['drive'] ?? '';
        $path = $_GET['path'] ?? '';
        $real = resolvePath($drive, $path);

        if (!is_dir($real)) {
            jsonError('Dossier introuvable', 404);
        }

        $zipBaseName = basename(rtrim($real, '/'));
        if ($zipBaseName === '') $zipBaseName = 'Disque_' . $drive;

        $tempZipPath = sys_get_temp_dir() . '/nounoursfiles_' . uniqid() . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            jsonError('Impossible de créer l\'archive', 500);
        }

        set_time_limit(0); // un gros dossier peut prendre du temps à compresser
        addFolderToZip($zip, $real, $zipBaseName);
        $zip->close();

        if (!is_file($tempZipPath)) {
            jsonError('Échec de la création de l\'archive', 500);
        }

        if (ob_get_length() !== false) { ob_end_clean(); }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipBaseName . '.zip"');
        header('Content-Length: ' . filesize($tempZipPath));
        readfile($tempZipPath);
        @unlink($tempZipPath);
        exit;
    }

    case 'uploadChunk': {
        // Upload en morceaux (comme Nextcloud/OneDrive) : chaque tranche
        // part dans sa propre requête courte, ce qui évite qu'une requête
        // unique de plusieurs minutes se fasse couper par un routeur/box
        // qui referme les connexions inactives. Les morceaux sont ajoutés
        // directement dans un fichier ".nfpart" sur le disque de
        // destination (pas de copie ultérieure entre disques).
        $drive = $_GET['drive'] ?? '';
        $path = $_GET['path'] ?? '';
        $filename = basename(str_replace(['\\', '/'], '', $_GET['filename'] ?? ''));
        $chunkIndex = (int)($_GET['chunkIndex'] ?? -1);
        $totalChunks = (int)($_GET['totalChunks'] ?? 0);
        $offset = (int)($_GET['offset'] ?? -1);

        if ($filename === '' || $chunkIndex < 0 || $totalChunks <= 0 || $offset < 0) {
            jsonError('Paramètres de morceau invalides', 400);
        }

        $real = resolvePath($drive, $path);
        if (!is_dir($real)) {
            jsonError('Dossier de destination introuvable', 404);
        }

        $partPath = $real . '/' . $filename . '.nfpart';
        // Premier morceau : on repart toujours d'un fichier neuf (efface
        // un essai précédent resté en plan). Écriture à l'offset exact
        // (plutôt qu'un simple "ajout à la suite") pour qu'une reprise
        // automatique d'un morceau en échec réécrive au même endroit au
        // lieu de dupliquer des données.
        $output = @fopen($partPath, ($chunkIndex === 0) ? 'wb' : 'c+b');
        $input = fopen('php://input', 'rb');
        if (!$input || !$output) {
            jsonError('Impossible d\'écrire le fragment', 500);
        }
        fseek($output, $offset);

        $written = stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);

        if ($written === false) {
            jsonError('Échec de l\'écriture du fragment', 500);
        }

        if ($chunkIndex === $totalChunks - 1) {
            // Dernier morceau reçu : on finalise (renommage instantané,
            // même disque, pas de copie).
            $finalPath = $real . '/' . $filename;
            if (!@rename($partPath, $finalPath)) {
                jsonError('Échec de la finalisation du fichier', 500);
            }
            $stat = @stat($finalPath);
            if ($stat !== false) {
                cacheUpsertItem($drive, $path, $filename, [
                    'type' => 'file',
                    'size' => $stat['size'],
                    'modified' => $stat['mtime'],
                ]);
            }
            jsonSuccess(['success' => true, 'complete' => true, 'name' => $filename]);
        } else {
            jsonSuccess(['success' => true, 'complete' => false, 'chunkIndex' => $chunkIndex]);
        }
        break;
    }

    case 'uploadRaw': {
        // Upload en flux direct (contournement du mécanisme standard de
        // PHP qui stocke d'abord le fichier dans un dossier temporaire
        // puis le copie vers la destination — coûteux et lent pour les
        // gros fichiers entre deux disques différents). Ici, on écrit
        // directement dans le fichier final au fur et à mesure que les
        // données arrivent du réseau.
        $drive = $_GET['drive'] ?? '';
        $path = $_GET['path'] ?? '';
        $filename = basename(str_replace(['\\', '/'], '', $_GET['filename'] ?? ''));

        if ($filename === '') {
            jsonError('Nom de fichier manquant', 400);
        }

        $real = resolvePath($drive, $path);
        if (!is_dir($real)) {
            jsonError('Dossier de destination introuvable', 404);
        }

        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : null;
        if ($contentLength !== null && $contentLength > MAX_UPLOAD_SIZE) {
            jsonError('Fichier trop volumineux', 413);
        }

        $destPath = $real . '/' . $filename;
        $input = fopen('php://input', 'rb');
        $output = @fopen($destPath, 'wb');
        if (!$input || !$output) {
            jsonError('Impossible d\'écrire le fichier de destination', 500);
        }

        $written = stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);

        if ($written === false || ($contentLength !== null && $written !== $contentLength)) {
            @unlink($destPath);
            jsonError('Échec de l\'écriture du fichier (transfert interrompu)', 500);
        }

        $stat = @stat($destPath);
        if ($stat !== false) {
            cacheUpsertItem($drive, $path, $filename, [
                'type' => 'file',
                'size' => $stat['size'],
                'modified' => $stat['mtime'],
            ]);
        }

        jsonSuccess(['success' => true, 'name' => $filename, 'size' => $written]);
        break;
    }

    case 'upload': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonError('Méthode non autorisée', 405);
        }
        $drive = $_POST['drive'] ?? '';
        $path = $_POST['path'] ?? '';
        $real = resolvePath($drive, $path);

        if (!is_dir($real)) {
            jsonError('Dossier de destination introuvable', 404);
        }
        if (!isset($_FILES['file'])) {
            jsonError('Aucun fichier reçu', 400);
        }
        if ($_FILES['file']['size'] > MAX_UPLOAD_SIZE) {
            jsonError('Fichier trop volumineux', 413);
        }

        $destName = basename($_FILES['file']['name']);
        $destPath = $real . '/' . $destName;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
            jsonError('Échec de l\'upload', 500);
        }

        $stat = @stat($destPath);
        if ($stat !== false) {
            cacheUpsertItem($drive, $path, $destName, [
                'type' => 'file',
                'size' => $stat['size'],
                'modified' => $stat['mtime'],
            ]);
        }

        jsonSuccess(['success' => true, 'name' => $destName]);
        break;
    }

    case 'mkdir': {
        $drive = $_GET['drive'] ?? ($_POST['drive'] ?? '');
        $path = $_GET['path'] ?? ($_POST['path'] ?? '');
        $name = $_GET['name'] ?? ($_POST['name'] ?? '');

        if ($name === '' || strpbrk($name, '\\/:*?"<>|') !== false) {
            jsonError('Nom de dossier invalide', 400);
        }

        $real = resolvePath($drive, $path);
        $newDir = $real . '/' . $name;

        if (file_exists($newDir)) {
            jsonError('Ce dossier existe déjà', 409);
        }
        if (!mkdir($newDir)) {
            jsonError('Échec de la création du dossier', 500);
        }

        cacheUpsertItem($drive, $path, $name, [
            'type' => 'dir',
            'size' => null,
            'modified' => time(),
        ]);

        jsonSuccess(['success' => true]);
        break;
    }

    case 'delete': {
        $drive = $_GET['drive'] ?? ($_POST['drive'] ?? '');
        $path = $_GET['path'] ?? ($_POST['path'] ?? '');
        $real = resolvePath($drive, $path);

        if (is_dir($real)) {
            if (count(scandir($real)) > 2) { // que . et ..
                jsonError('Le dossier n\'est pas vide', 409);
            }
            if (!rmdir($real)) {
                jsonError('Échec de la suppression du dossier', 500);
            }
        } elseif (is_file($real)) {
            if (!unlink($real)) {
                jsonError('Échec de la suppression du fichier', 500);
            }
        } else {
            jsonError('Introuvable', 404);
        }

        [$parentPath, $itemName] = splitParentName($path);
        cacheRemoveItem($drive, $parentPath, $itemName);

        jsonSuccess(['success' => true]);
        break;
    }

    case 'rename': {
        $drive = $_GET['drive'] ?? ($_POST['drive'] ?? '');
        $path = $_GET['path'] ?? ($_POST['path'] ?? '');
        $newName = $_GET['newName'] ?? ($_POST['newName'] ?? '');

        if ($newName === '' || strpbrk($newName, '\\/:*?"<>|') !== false) {
            jsonError('Nom invalide', 400);
        }

        $real = resolvePath($drive, $path);
        if (!file_exists($real)) {
            jsonError('Introuvable', 404);
        }

        $newPath = dirname($real) . '/' . $newName;
        if (file_exists($newPath)) {
            jsonError('Un élément porte déjà ce nom', 409);
        }
        if (!rename($real, $newPath)) {
            jsonError('Échec du renommage', 500);
        }

        [$parentPath, $oldName] = splitParentName($path);
        $stat = @stat($newPath);
        if ($stat !== false) {
            $isDir = ($stat['mode'] & 0170000) === 0040000;
            cacheRenameItem($drive, $parentPath, $oldName, $newName, [
                'type' => $isDir ? 'dir' : 'file',
                'size' => $isDir ? null : $stat['size'],
                'modified' => $stat['mtime'],
            ]);
        }

        jsonSuccess(['success' => true]);
        break;
    }

    default:
        jsonError('Action inconnue', 400);
}
