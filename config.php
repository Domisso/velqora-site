<?php
// ═══════════════════════════════════════════════════
// VELQORA — Konfiguracja
// Uzupełnij swoimi danymi przed wgraniem!
// ═══════════════════════════════════════════════════

// ── BAZA DANYCH (z panelu OVHCloud → MySQL) ──────────
define('DB_HOST', 'localhost');          // zazwyczaj "localhost" na OVH
define('DB_NAME', 'velqora_db');         // nazwa bazy z panelu OVH
define('DB_USER', 'velqora_user');       // użytkownik bazy z panelu OVH
define('DB_PASS', 'TWOJE_HASLO_DB');     // hasło bazy z panelu OVH
define('DB_CHARSET', 'utf8mb4');

// ── STRIPE ───────────────────────────────────────────
// Klucze z dashboard.stripe.com → Developers → API Keys
define('STRIPE_SECRET_KEY', 'sk_live_TWOJ_KLUCZ_STRIPE');        // klucz prywatny
define('STRIPE_PUBLISHABLE_KEY', 'pk_live_TWOJ_KLUCZ_PUBLICZNY'); // klucz publiczny
define('STRIPE_WEBHOOK_SECRET', 'whsec_TWOJ_WEBHOOK_SECRET');     // z Stripe → Webhooks

// ── STRIPE PRICE IDs ─────────────────────────────────
// Utwórz produkty w Stripe Dashboard → Products
define('STRIPE_PRICE_STARTER_MONTHLY', 'price_STARTER_MONTHLY_ID');
define('STRIPE_PRICE_STARTER_YEARLY',  'price_STARTER_YEARLY_ID');
define('STRIPE_PRICE_PRO_MONTHLY',     'price_PRO_MONTHLY_ID');
define('STRIPE_PRICE_PRO_YEARLY',      'price_PRO_YEARLY_ID');
define('STRIPE_PRICE_BUSINESS_MONTHLY','price_BUSINESS_MONTHLY_ID');
define('STRIPE_PRICE_BUSINESS_YEARLY', 'price_BUSINESS_YEARLY_ID');

// ── CLAUDE AI (Anthropic) ────────────────────────────
define('ANTHROPIC_API_KEY', 'sk-ant-TWOJ_KLUCZ_ANTHROPIC');

// ── SMTP (wysyłanie e-maili) ─────────────────────────
define('SMTP_HOST',     'ssl://smtp.gmail.com');    // lub serwer OVH
define('SMTP_PORT',     465);
define('SMTP_USER',     'kontakt@velqora.pl');
define('SMTP_PASS',     'TWOJE_HASLO_EMAIL');
define('SMTP_FROM',     'kontakt@velqora.pl');
define('SMTP_FROM_NAME','Velqora');

// ── APLIKACJA ─────────────────────────────────────────
define('APP_URL',       'https://velqora.pl');      // Twoja domena
define('APP_NAME',      'Velqora');
define('APP_ENV',       'production');               // 'development' lub 'production'
define('SESSION_EXPIRE', 60 * 60 * 24 * 30);        // 30 dni w sekundach
define('JWT_SECRET',    'WYGENERUJ_LOSOWY_STRING_64_ZNAKI');

// ── LIMITY PLANÓW ────────────────────────────────────
define('PLAN_LIMITS', serialize([
    'starter'  => ['ai_analyses' => 10,  'invoices' => 50,  'storage_mb' => 500],
    'pro'      => ['ai_analyses' => 50,  'invoices' => 9999,'storage_mb' => 5000],
    'business' => ['ai_analyses' => 9999,'invoices' => 9999,'storage_mb' => 50000],
]));

// ── POŁĄCZENIE Z BAZĄ ────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            if (APP_ENV === 'development') {
                die(json_encode(['error' => 'DB Error: ' . $e->getMessage()]));
            }
            die(json_encode(['error' => 'Błąd połączenia z bazą danych']));
        }
    }
    return $pdo;
}

// ── NAGŁÓWKI API ─────────────────────────────────────
function setApiHeaders(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: ' . APP_URL);
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
}

// ── ODPOWIEDŹ JSON ────────────────────────────────────
function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── WERYFIKACJA TOKENU ────────────────────────────────
function authUser(): array {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? '';
    if (!str_starts_with($auth, 'Bearer ')) respond(['error' => 'Brak autoryzacji'], 401);
    $token = substr($auth, 7);
    $db = getDB();
    $stmt = $db->prepare("SELECT s.user_id, u.email, u.first_name, u.last_name, u.plan, u.is_active
                          FROM sessions s JOIN users u ON s.user_id = u.id
                          WHERE s.token = ? AND s.expires_at > NOW() AND u.is_active = 1");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) respond(['error' => 'Sesja wygasła — zaloguj się ponownie'], 401);
    return $user;
}
