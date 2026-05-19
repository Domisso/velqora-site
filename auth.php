<?php
// ═══════════════════════════════════════════════════
// VELQORA — API: Autentykacja
// Endpoint: /api/auth.php
// ═══════════════════════════════════════════════════
require_once __DIR__ . '/config.php';
setApiHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? '';

match($action) {
    'register' => register($body),
    'login'    => login($body),
    'logout'   => logout(),
    'me'       => me(),
    'forgot'   => forgotPassword($body),
    'reset'    => resetPassword($body),
    'verify'   => verifyEmail($_GET['token'] ?? ''),
    default    => respond(['error' => 'Nieznana akcja'], 404),
};

// ── REJESTRACJA ───────────────────────────────────────
function register(array $d): void {
    $email    = trim(strtolower($d['email'] ?? ''));
    $password = $d['password'] ?? '';
    $first    = trim($d['first_name'] ?? '');
    $last     = trim($d['last_name'] ?? '');
    $firm     = trim($d['firm_name'] ?? '');

    // Walidacja
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        respond(['error' => 'Nieprawidłowy adres e-mail'], 422);
    if (strlen($password) < 8)
        respond(['error' => 'Hasło musi mieć minimum 8 znaków'], 422);
    if (empty($first) || empty($last))
        respond(['error' => 'Imię i nazwisko są wymagane'], 422);

    $db = getDB();

    // Sprawdź czy email istnieje
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) respond(['error' => 'Konto z tym adresem już istnieje'], 409);

    // Utwórz konto
    $hash        = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $verifyToken = bin2hex(random_bytes(32));
    $avatar      = strtoupper(substr($first, 0, 1) . substr($last, 0, 1));

    $stmt = $db->prepare("INSERT INTO users (email, password_hash, first_name, last_name, avatar, verify_token)
                          VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$email, $hash, $first, $last, $avatar, $verifyToken]);
    $userId = $db->lastInsertId();

    // Utwórz profil firmy jeśli podano
    if (!empty($firm)) {
        $stmt = $db->prepare("INSERT INTO companies (user_id, name) VALUES (?, ?)");
        $stmt->execute([$userId, $firm]);
    }

    // Wyślij e-mail weryfikacyjny
    sendVerificationEmail($email, $first, $verifyToken);

    // Zaloguj od razu (utwórz sesję)
    $token = createSession($userId);

    logActivity($userId, 'register', 'Nowe konto: ' . $email);

    respond([
        'success' => true,
        'message' => 'Konto utworzone! Sprawdź e-mail, aby zweryfikować adres.',
        'token'   => $token,
        'user'    => getUserData($userId),
    ], 201);
}

// ── LOGOWANIE ─────────────────────────────────────────
function login(array $d): void {
    $email    = trim(strtolower($d['email'] ?? ''));
    $password = $d['password'] ?? '';

    if (empty($email) || empty($password))
        respond(['error' => 'Podaj e-mail i hasło'], 422);

    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash']))
        respond(['error' => 'Nieprawidłowy e-mail lub hasło'], 401);

    // Odśwież hash jeśli potrzeba
    if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $user['id']]);
    }

    $token = createSession($user['id']);
    logActivity($user['id'], 'login', 'Logowanie z IP: ' . getClientIp());

    respond([
        'success' => true,
        'token'   => $token,
        'user'    => getUserData($user['id']),
    ]);
}

// ── WYLOGOWANIE ───────────────────────────────────────
function logout(): void {
    $headers = getallheaders();
    $auth    = $headers['Authorization'] ?? '';
    if (str_starts_with($auth, 'Bearer ')) {
        $token = substr($auth, 7);
        $db    = getDB();
        $db->prepare("DELETE FROM sessions WHERE token = ?")->execute([$token]);
    }
    respond(['success' => true, 'message' => 'Wylogowano']);
}

// ── DANE ZALOGOWANEGO UŻYTKOWNIKA ─────────────────────
function me(): void {
    $user = authUser();
    respond(['success' => true, 'user' => getUserData($user['user_id'])]);
}

// ── RESET HASŁA — WYŚLIJ LINK ─────────────────────────
function forgotPassword(array $d): void {
    $email = trim(strtolower($d['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        respond(['error' => 'Nieprawidłowy adres e-mail'], 422);

    $db   = getDB();
    $stmt = $db->prepare("SELECT id, first_name FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Zawsze odpowiadaj OK (bezpieczeństwo — nie ujawniaj czy email istnieje)
    if ($user) {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 godzina
        $db->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?")
           ->execute([$token, $expires, $user['id']]);
        sendPasswordResetEmail($email, $user['first_name'], $token);
    }

    respond(['success' => true, 'message' => 'Jeśli konto istnieje, wysłaliśmy link do resetowania hasła.']);
}

// ── RESET HASŁA — USTAW NOWE ──────────────────────────
function resetPassword(array $d): void {
    $token    = $d['token'] ?? '';
    $password = $d['password'] ?? '';

    if (strlen($password) < 8)
        respond(['error' => 'Hasło musi mieć minimum 8 znaków'], 422);

    $db   = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) respond(['error' => 'Link wygasł lub jest nieprawidłowy. Wygeneruj nowy.'], 400);

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $db->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?")
       ->execute([$hash, $user['id']]);

    // Usuń wszystkie sesje — wymuś ponowne logowanie
    $db->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$user['id']]);

    respond(['success' => true, 'message' => 'Hasło zostało zmienione. Zaloguj się ponownie.']);
}

// ── WERYFIKACJA E-MAIL ────────────────────────────────
function verifyEmail(string $token): void {
    if (empty($token)) respond(['error' => 'Brak tokenu'], 400);

    $db   = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE verify_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) respond(['error' => 'Nieprawidłowy lub już użyty token weryfikacyjny'], 400);

    $db->prepare("UPDATE users SET email_verified = 1, verify_token = NULL WHERE id = ?")
       ->execute([$user['id']]);

    // Przekieruj na stronę logowania z sukcesem
    header('Location: ' . APP_URL . '/login.html?verified=1');
    exit;
}

// ── HELPERS ───────────────────────────────────────────
function createSession(int $userId): string {
    $token   = bin2hex(random_bytes(48));
    $expires = date('Y-m-d H:i:s', time() + SESSION_EXPIRE);
    $db      = getDB();
    $db->prepare("INSERT INTO sessions (user_id, token, ip, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)")
       ->execute([$userId, $token, getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? '', $expires]);
    return $token;
}

function getUserData(int $userId): array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT u.id, u.email, u.first_name, u.last_name, u.phone, u.avatar,
                                 u.plan, u.plan_expires_at, u.email_verified, u.two_fa_enabled,
                                 u.created_at, c.name as company_name, c.nip
                          FROM users u LEFT JOIN companies c ON c.user_id = u.id
                          WHERE u.id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: [];
}

function getClientIp(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_CLIENT_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
}

function logActivity(int $userId, string $action, string $details = ''): void {
    try {
        $db = getDB();
        $db->prepare("INSERT INTO activity_logs (user_id, action, details, ip) VALUES (?, ?, ?, ?)")
           ->execute([$userId, $action, $details, getClientIp()]);
    } catch (Exception $e) { /* nie przerywaj głównej akcji */ }
}

function sendVerificationEmail(string $to, string $name, string $token): void {
    $link    = APP_URL . '/api/auth.php?action=verify&token=' . $token;
    $subject = 'Potwierdź adres e-mail — Velqora';
    $body    = "Cześć $name,\n\nKliknij poniższy link, aby zweryfikować adres e-mail:\n$link\n\nLink wygaśnie po 24 godzinach.\n\nZespół Velqora";
    sendEmail($to, $subject, $body);
}

function sendPasswordResetEmail(string $to, string $name, string $token): void {
    $link    = APP_URL . '/reset-password.html?token=' . $token;
    $subject = 'Reset hasła — Velqora';
    $body    = "Cześć $name,\n\nOtrzymaliśmy prośbę o reset hasła.\nKliknij link (ważny 1 godzinę):\n$link\n\nJeśli to nie Ty — zignoruj tę wiadomość.\n\nZespół Velqora";
    sendEmail($to, $subject, $body);
}

function sendEmail(string $to, string $subject, string $body): void {
    $headers  = "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">\r\n";
    $headers .= "Reply-To: " . SMTP_FROM . "\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    // Dla OVHCloud shared hosting mail() zazwyczaj działa bez konfiguracji SMTP
    @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}
