<?php
/**
 * common.php – navet för MinSida-verktygen
 * -----------------------------------------------------------------------
 * Varje verktyg börjar med:   require __DIR__ . '/common.php';
 * och får då databas, inloggning, CSRF-skydd, meny och hjälpfunktioner.
 *
 * Tre inloggningslägen (AUTH_MODE):
 *   'login' – inbyggd inloggning med användarnamn och lösenords-hash
 *   'll2'   – delar inloggning/session med ll2.php (MinSida på nalle.fyi)
 *   'demo'  – ingen inloggning; varje besökare får en egen sandlåda med
 *             exempeldata som rensas efter DEMO_TTL_HOURS timmar
 * -----------------------------------------------------------------------
 */

// Filen ska bara inkluderas, aldrig öppnas direkt i webbläsaren.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit;
}

// ============================================================
// INSTÄLLNINGAR – ligger i config.php (som inte checkas in i git).
// Kopiera config.example.php till config.php och fyll i.
// ============================================================
if (!is_file(__DIR__ . '/config.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die("Saknar config.php.\nKopiera config.example.php till config.php och fyll i dina uppgifter (se README.md).");
}
require __DIR__ . '/config.php';

// ============================================================
// Nedan behöver normalt inte ändras.
// ============================================================

date_default_timezone_set(TIMEZONE);

// ============================================================
// SESSION
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    if (SESSION_NAME !== '') {
        session_name(SESSION_NAME);
    }
    if (AUTH_MODE === 'll2') {
        // Exakt som ll2.php, så att sessionen delas.
        session_set_cookie_params(SESSION_LIFETIME);
    } else {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

// ============================================================
// INLOGGNING
// ============================================================
function isLoggedIn(): bool {
    if (AUTH_MODE === 'demo') {
        return true;
    }
    if (empty($_SESSION['user_id']) || empty($_SESSION['login_time'])) {
        return false;
    }
    if ((time() - $_SESSION['login_time']) > SESSION_LIFETIME) {
        unset($_SESSION['user_id'], $_SESSION['login_time']);
        return false;
    }
    return true;
}

function currentUserId(): int {
    if (AUTH_MODE === 'demo') {
        return demoUserId();
    }
    if (AUTH_MODE === 'login') {
        return 1;
    }
    return (int)($_SESSION['user_id'] ?? 0);
}

// Vart inloggning/utloggning skickas.
function authEndpoint(): string {
    return AUTH_MODE === 'll2' ? 'll2.php' : basename($_SERVER['SCRIPT_NAME'] ?? '');
}

// ============================================================
// SKYDD MOT FÖRFALSKADE ANROP (CSRF)
// ============================================================
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function checkCsrf(): bool {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals(csrfToken(), $sent);
}

// ============================================================
// DATABAS
// ============================================================
function getDb(): mysqli {
    static $db = null;
    if ($db === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $db = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        } catch (Throwable $e) {
            http_response_code(500);
            die('Databasanslutning misslyckades. Kontrollera databasuppgifterna i inställningarna.');
        }
        mysqli_set_charset($db, 'utf8mb4');
    }
    return $db;
}

// Kör en fråga med parametrar. $types som i bind_param, t.ex. 'isi'.
function dbRun(string $sql, string $types = '', array $params = []): mysqli_stmt {
    $stmt = mysqli_prepare(getDb(), $sql);
    if ($types !== '') {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    return $stmt;
}

function dbAll(string $sql, string $types = '', array $params = []): array {
    $res = mysqli_stmt_get_result(dbRun($sql, $types, $params));
    return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
}

function dbOne(string $sql, string $types = '', array $params = []): ?array {
    $rows = dbAll($sql, $types, $params);
    return $rows[0] ?? null;
}

function dbInsertId(): int {
    return (int)mysqli_insert_id(getDb());
}

// ============================================================
// SMÅ HJÄLPFUNKTIONER
// ============================================================
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function nowSql(): string {
    return date('Y-m-d H:i:s');
}

function fmtDate(?string $dt): string {
    return $dt ? date('Y-m-d H:i', strtotime($dt)) : '';
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Startar en AJAX-hantering: JSON-svar, kräver inloggning och giltig CSRF-token.
function startJsonApi(): void {
    ini_set('display_errors', '0');
    set_exception_handler(function (Throwable $e) {
        jsonResponse(['success' => false, 'error' => 'Serverfel: ' . $e->getMessage()], 500);
    });
    if (!isLoggedIn()) {
        jsonResponse(['success' => false, 'error' => 'Du är inte inloggad längre. Ladda om sidan och logga in.'], 401);
    }
    if (!checkCsrf()) {
        jsonResponse(['success' => false, 'error' => 'Ogiltig säkerhetstoken. Ladda om sidan.'], 403);
    }
}

// ============================================================
// DEMOLÄGE – en egen sandlåda per besökare
// ============================================================
// Varje besökare får ett eget användar-ID (rad i DEMO_TABLE) och en kopia
// av exempeldatan (som ligger under UserID 0). Sandlådor som inte använts
// på DEMO_TTL_HOURS timmar rensas bort. Funktionerna demoClone() och
// demoPurge() finns i demo.php i respektive verktygs mapp.

function demoUserId(): int {
    static $uid = null;
    if ($uid !== null) {
        return $uid;
    }
    $key = 'demo_uid_' . DEMO_TABLE;
    $id = (int)($_SESSION[$key] ?? 0);
    if ($id > 0 && dbOne('SELECT ID FROM `' . DEMO_TABLE . '` WHERE ID = ?', 'i', [$id])) {
        if (time() - (int)($_SESSION[$key . '_touch'] ?? 0) > 600) {
            dbRun('UPDATE `' . DEMO_TABLE . '` SET Senast = ? WHERE ID = ?', 'si', [nowSql(), $id]);
            $_SESSION[$key . '_touch'] = time();
        }
        return $uid = $id;
    }
    demoCleanup();
    dbRun('INSERT INTO `' . DEMO_TABLE . '` (Skapad, Senast) VALUES (?, ?)', 'ss', [nowSql(), nowSql()]);
    $id = dbInsertId();
    demoClone($id);
    $_SESSION[$key] = $id;
    $_SESSION[$key . '_touch'] = time();
    return $uid = $id;
}

function demoCleanup(): void {
    $limit = date('Y-m-d H:i:s', time() - DEMO_TTL_HOURS * 3600);
    $old = array_map('intval', array_column(
        dbAll('SELECT ID FROM `' . DEMO_TABLE . '` WHERE Senast < ? LIMIT 500', 's', [$limit]), 'ID'
    ));
    foreach (array_chunk($old, 100) as $chunk) {
        demoPurge($chunk);
        dbRun('DELETE FROM `' . DEMO_TABLE . '` WHERE ID IN (' . implode(',', $chunk) . ')');
    }
}

// "1,2,3" för IN (...) – bara heltal > 0, så mallen (UserID 0) rörs aldrig.
function demoIdList(array $uids): string {
    $ids = array_values(array_filter(array_map('intval', $uids), function ($u) { return $u > 0; }));
    return $ids ? implode(',', $ids) : '-1';
}

// ============================================================
// INBYGGD INLOGGNING (AUTH_MODE = 'login')
// ============================================================
function handleBuiltinAuth(): void {
    $action = $_POST['action'] ?? '';

    if ($action === 'make_hash') {
        if (LOGIN_PASSWORD_HASH !== '') {
            jsonResponse(['success' => false, 'error' => 'Lösenord är redan inställt.'], 403);
        }
        $pw = (string)($_POST['password'] ?? '');
        if (mb_strlen($pw) < 10) {
            jsonResponse(['success' => false, 'error' => 'Välj ett lösenord med minst 10 tecken.']);
        }
        jsonResponse(['success' => true, 'hash' => password_hash($pw, PASSWORD_DEFAULT)]);
    }

    if ($action === 'login') {
        $user = (string)($_POST['username'] ?? '');
        $pw = (string)($_POST['password'] ?? '');
        $ok = LOGIN_PASSWORD_HASH !== ''
            && hash_equals(mb_strtolower(LOGIN_USER), mb_strtolower(trim($user)))
            && password_verify($pw, LOGIN_PASSWORD_HASH);
        if (!$ok) {
            sleep(2);   // bromsar gissningsförsök
            jsonResponse(['success' => false, 'error' => 'Fel användarnamn eller lösenord.']);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = 1;
        $_SESSION['login_time'] = time();
        jsonResponse(['success' => true]);
    }

    if ($action === 'logout') {
        unset($_SESSION['user_id'], $_SESSION['login_time']);
        session_regenerate_id(true);
        jsonResponse(['success' => true]);
    }
}

// ============================================================
// GEMENSAMT GRÄNSSNITT
// ============================================================

// Meny överst på varje verktyg (+ demoruta eller Logga ut).
function renderNav(string $current): void { ?>
    <nav class="minsida-nav">
        <?php foreach (MINSIDA_TOOLS as $file => $label): ?>
            <a href="<?= h($file) ?>"<?= $file === $current ? ' class="active"' : '' ?>><?= h($label) ?></a>
        <?php endforeach; ?>
        <?php if (AUTH_MODE !== 'demo'): ?>
            <a href="#" class="nav-logout" id="logout-link">Logga ut</a>
        <?php endif; ?>
    </nav>
    <?php if (AUTH_MODE === 'demo'): ?>
        <div class="demo-banner">
            <strong>Demo</strong> – testa fritt! Dina ändringar syns bara för dig och raderas efter <?= (int)DEMO_TTL_HOURS ?> timmar.
            <a href="?demo_reset=1" onclick="return confirm('Börja om med ny exempeldata? Dina ändringar försvinner.')">Börja om</a>
        </div>
    <?php else: ?>
    <script>
        document.getElementById('logout-link').addEventListener('click', function (e) {
            e.preventDefault();
            const fd = new FormData();
            fd.append('action', 'logout');
            fetch(<?= json_encode(authEndpoint()) ?>, { method: 'POST', body: fd }).then(() => location.reload());
        });
    </script>
    <?php endif;
}

// Hel inloggningssida (eller förstagångsguide om lösenord saknas).
function renderLoginPage(string $title): void {
    $setup = AUTH_MODE === 'login' && LOGIN_PASSWORD_HASH === '';
    ?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> – <?= $setup ? 'kom igång' : 'logga in' ?></title>
    <link rel="stylesheet" href="minsida.css">
</head>
<body class="login-body">
    <div class="login-box<?= $setup ? ' setup-box' : '' ?>">
        <h2><?= h($title) ?></h2>
        <?php if ($setup): ?>
            <p class="small">Inget lösenord är inställt än. Välj ett lösenord här så skapas en <em>hash</em> (en säker kontrollsumma) som du klistrar in i <code>config.php</code> som <code>LOGIN_PASSWORD_HASH</code>.</p>
            <form id="hash-form">
                <label>Önskat lösenord (minst 10 tecken)
                    <input type="password" name="password" autocomplete="new-password" required minlength="10">
                </label>
                <button type="submit" class="primary-btn">Skapa hash</button>
                <p class="error-text" id="hash-msg"></p>
            </form>
            <textarea id="hash-out" class="hash-out" readonly hidden></textarea>
        <?php else: ?>
            <form id="login-form">
                <label>Användarnamn
                    <input type="text" name="username" autocomplete="username" required autofocus>
                </label>
                <label>Lösenord
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <button type="submit" class="primary-btn">Logga in</button>
                <p class="error-text" id="login-msg"></p>
            </form>
        <?php endif; ?>
    </div>
    <script>
        const AUTH_URL = <?= json_encode(authEndpoint()) ?>;
        function send(form, action) {
            const fd = new FormData(form);
            fd.append('action', action);
            return fetch(AUTH_URL, { method: 'POST', body: fd }).then(r => r.json());
        }
        const loginForm = document.getElementById('login-form');
        if (loginForm) {
            loginForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const msg = document.getElementById('login-msg');
                msg.textContent = 'Loggar in...';
                send(this, 'login')
                    .then(data => { if (data.success) location.reload(); else msg.textContent = data.error || 'Fel vid inloggning.'; })
                    .catch(err => { msg.textContent = 'Nätverksfel: ' + err.message; });
            });
        }
        const hashForm = document.getElementById('hash-form');
        if (hashForm) {
            hashForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const msg = document.getElementById('hash-msg');
                send(this, 'make_hash').then(data => {
                    if (!data.success) { msg.textContent = data.error; return; }
                    const out = document.getElementById('hash-out');
                    out.value = "define('LOGIN_PASSWORD_HASH', '" + data.hash + "');";
                    out.hidden = false;
                    out.select();
                    msg.textContent = 'Klart! Ersätt raden med LOGIN_PASSWORD_HASH i config.php med raden ovan, spara och ladda om sidan.';
                    msg.className = 'small';
                });
            });
        }
    </script>
</body>
</html>
<?php }

// ============================================================
// KÖRS VID VARJE SIDVISNING
// ============================================================
if (AUTH_MODE === 'login' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && in_array($_POST['action'] ?? '', ['login', 'logout', 'make_hash'], true)) {
    handleBuiltinAuth();
}

if (AUTH_MODE === 'demo') {
    require __DIR__ . '/demo.php';
    if (isset($_GET['demo_reset'])) {
        $demoUid = demoUserId();
        demoPurge([$demoUid]);
        demoClone($demoUid);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? './', '?'));
        exit;
    }
}
