<?php
// ═══════════════════════════════════════════════════
// VELQORA — API: Płatności Stripe
// Endpoint: /api/payments.php
// ═══════════════════════════════════════════════════
require_once __DIR__ . '/config.php';
setApiHeaders();

$user   = authUser();
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? '';

match($action) {
    'create-checkout' => createCheckout($user, $body),
    'portal'          => createPortal($user),
    'history'         => paymentHistory($user),
    'status'          => paymentStatus($user),
    default           => respond(['error' => 'Nieznana akcja'], 404),
};

// ── UTWÓRZ SESJĘ STRIPE CHECKOUT ──────────────────────
function createCheckout(array $user, array $body): void {
    $plan    = $body['plan'] ?? '';
    $billing = $body['billing'] ?? 'monthly';

    $priceMap = [
        'starter_monthly'  => STRIPE_PRICE_STARTER_MONTHLY,
        'starter_yearly'   => STRIPE_PRICE_STARTER_YEARLY,
        'pro_monthly'      => STRIPE_PRICE_PRO_MONTHLY,
        'pro_yearly'       => STRIPE_PRICE_PRO_YEARLY,
        'business_monthly' => STRIPE_PRICE_BUSINESS_MONTHLY,
        'business_yearly'  => STRIPE_PRICE_BUSINESS_YEARLY,
    ];

    $priceKey = $plan . '_' . $billing;
    if (!isset($priceMap[$priceKey]))
        respond(['error' => 'Nieprawidłowy plan lub okres rozliczeniowy'], 422);

    $priceId = $priceMap[$priceKey];

    // Kwoty dla logów
    $amounts = [
        'starter_monthly' => 79,   'starter_yearly'  => 758,
        'pro_monthly'     => 199,  'pro_yearly'       => 1910,
        'business_monthly'=> 499,  'business_yearly'  => 4790,
    ];

    // Pobierz lub utwórz Stripe Customer
    $customerId = getOrCreateStripeCustomer($user);

    // Wywołaj Stripe API przez cURL
    $sessionData = stripeRequest('POST', '/checkout/sessions', [
        'customer'                => $customerId,
        'mode'                    => 'subscription',
        'payment_method_types[]'  => 'card',
        'line_items[0][price]'    => $priceId,
        'line_items[0][quantity]' => '1',
        'success_url'             => APP_URL . '/dashboard.html?payment=success&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'              => APP_URL . '/dashboard.html?payment=cancelled',
        'locale'                  => 'pl',
        'billing_address_collection' => 'required',
        'customer_update[address]'   => 'auto',
        'metadata[user_id]'       => (string)$user['user_id'],
        'metadata[plan]'          => $plan,
        'metadata[billing]'       => $billing,
        'subscription_data[metadata][user_id]' => (string)$user['user_id'],
        'subscription_data[metadata][plan]'    => $plan,
    ]);

    if (isset($sessionData['error']))
        respond(['error' => 'Błąd Stripe: ' . $sessionData['error']['message']], 500);

    // Zapisz sesję w bazie
    $db = getDB();
    $db->prepare("INSERT INTO payments (user_id, stripe_session_id, plan, billing, amount, status)
                  VALUES (?, ?, ?, ?, ?, 'pending')")
       ->execute([$user['user_id'], $sessionData['id'], $plan, $billing, $amounts[$priceKey]]);

    respond([
        'success'    => true,
        'session_id' => $sessionData['id'],
        'url'        => $sessionData['url'],
    ]);
}

// ── PORTAL KLIENTA STRIPE (zarządzanie subskrypcją) ───
function createPortal(array $user): void {
    $customerId = getOrCreateStripeCustomer($user);

    $portal = stripeRequest('POST', '/billing_portal/sessions', [
        'customer'   => $customerId,
        'return_url' => APP_URL . '/dashboard.html',
    ]);

    if (isset($portal['error']))
        respond(['error' => 'Błąd tworzenia portalu'], 500);

    respond(['success' => true, 'url' => $portal['url']]);
}

// ── HISTORIA PŁATNOŚCI ────────────────────────────────
function paymentHistory(array $user): void {
    $db   = getDB();
    $stmt = $db->prepare("SELECT id, plan, billing, amount, currency, status,
                                 period_start, period_end, created_at
                          FROM payments WHERE user_id = ?
                          ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$user['user_id']]);
    respond(['success' => true, 'payments' => $stmt->fetchAll()]);
}

// ── STATUS SUBSKRYPCJI ────────────────────────────────
function paymentStatus(array $user): void {
    $db   = getDB();
    $stmt = $db->prepare("SELECT plan, plan_expires_at FROM users WHERE id = ?");
    $stmt->execute([$user['user_id']]);
    $u = $stmt->fetch();

    $limits = unserialize(PLAN_LIMITS);
    $plan   = $u['plan'];

    // Zlicz zużycie AI w tym miesiącu
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM ai_documents
                          WHERE user_id = ? AND MONTH(created_at) = MONTH(NOW())
                          AND YEAR(created_at) = YEAR(NOW())");
    $stmt->execute([$user['user_id']]);
    $aiUsed = $stmt->fetch()['cnt'];

    respond([
        'success'    => true,
        'plan'       => $plan,
        'expires_at' => $u['plan_expires_at'],
        'limits'     => $limits[$plan],
        'usage'      => ['ai_analyses' => (int)$aiUsed],
    ]);
}

// ── HELPERS STRIPE ────────────────────────────────────
function getOrCreateStripeCustomer(array $user): string {
    $db   = getDB();
    $stmt = $db->prepare("SELECT stripe_customer_id, email, first_name, last_name
                          FROM users WHERE id = ?");
    $stmt->execute([$user['user_id']]);
    $u = $stmt->fetch();

    if (!empty($u['stripe_customer_id'])) return $u['stripe_customer_id'];

    // Utwórz nowego klienta w Stripe
    $customer = stripeRequest('POST', '/customers', [
        'email' => $u['email'],
        'name'  => $u['first_name'] . ' ' . $u['last_name'],
        'metadata[user_id]' => (string)$user['user_id'],
        'preferred_locales[]' => 'pl',
    ]);

    if (isset($customer['error']))
        respond(['error' => 'Błąd tworzenia klienta Stripe'], 500);

    $db->prepare("UPDATE users SET stripe_customer_id = ? WHERE id = ?")
       ->execute([$customer['id'], $user['user_id']]);

    return $customer['id'];
}

function stripeRequest(string $method, string $endpoint, array $data = []): array {
    $ch = curl_init('https://api.stripe.com/v1' . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER     => ['Stripe-Version: 2024-06-20'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?? ['error' => ['message' => 'Brak odpowiedzi Stripe']];
}
