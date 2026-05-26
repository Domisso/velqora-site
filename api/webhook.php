<?php
// ═══════════════════════════════════════════════════
// VELQORA — Stripe Webhook
// URL w Stripe Dashboard: https://velqora.pl/api/webhook.php
// Zdarzenia: checkout.session.completed,
//            customer.subscription.updated,
//            customer.subscription.deleted,
//            invoice.payment_failed
// ═══════════════════════════════════════════════════
require_once __DIR__ . '/config.php';

// Webhook musi być dostępny bez autoryzacji
header('Content-Type: application/json');

$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// ── WERYFIKACJA PODPISU ────────────────────────────────
try {
    $event = verifyWebhook($payload, $sigHeader, STRIPE_WEBHOOK_SECRET);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Webhook verification failed: ' . $e->getMessage()]);
    exit;
}

// ── OBSŁUGA ZDARZEŃ ───────────────────────────────────
try {
    $db = getDB();
    switch ($event['type']) {

        // Płatność zakończona sukcesem
        case 'checkout.session.completed':
            handleCheckoutCompleted($db, $event['data']['object']);
            break;

        // Subskrypcja aktywna / zmieniona
        case 'customer.subscription.updated':
            handleSubscriptionUpdated($db, $event['data']['object']);
            break;

        // Subskrypcja anulowana
        case 'customer.subscription.deleted':
            handleSubscriptionDeleted($db, $event['data']['object']);
            break;

        // Płatność nieudana
        case 'invoice.payment_failed':
            handlePaymentFailed($db, $event['data']['object']);
            break;

        // Faktura zapłacona (odnowienie subskrypcji)
        case 'invoice.payment_succeeded':
            handleInvoicePaid($db, $event['data']['object']);
            break;
    }

    http_response_code(200);
    echo json_encode(['received' => true]);

} catch (Exception $e) {
    logWebhookError($event['type'] ?? 'unknown', $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}

// ─────────────────────────────────────────────────────
// HANDLERY
// ─────────────────────────────────────────────────────

function handleCheckoutCompleted(PDO $db, array $session): void {
    $sessionId = $session['id'];
    $meta      = $session['metadata'] ?? [];
    $userId    = (int)($meta['user_id'] ?? 0);
    $plan      = $meta['plan'] ?? 'starter';
    $billing   = $meta['billing'] ?? 'monthly';

    if (!$userId) return;

    // Ustaw datę wygaśnięcia planu
    $days = $billing === 'yearly' ? 366 : 31;
    $expires = date('Y-m-d H:i:s', strtotime("+$days days"));

    // Zaktualizuj użytkownika
    $db->prepare("UPDATE users SET plan = ?, plan_expires_at = ? WHERE id = ?")
       ->execute([$plan, $expires, $userId]);

    // Zaktualizuj płatność
    $db->prepare("UPDATE payments SET status = 'paid', period_start = CURDATE(),
                  period_end = DATE_ADD(CURDATE(), INTERVAL $days DAY)
                  WHERE stripe_session_id = ?")
       ->execute([$sessionId]);

    // Wyślij powiadomienie
    addNotification($db, $userId, 'payment',
        '✅ Plan aktywowany!',
        'Twój plan ' . ucfirst($plan) . ' jest aktywny do ' . date('d.m.Y', strtotime($expires))
    );

    // Wyślij e-mail potwierdzający
    $stmt = $db->prepare("SELECT email, first_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if ($user) {
        sendConfirmationEmail($user['email'], $user['first_name'], $plan, $billing);
    }
}

function handleSubscriptionUpdated(PDO $db, array $sub): void {
    $customerId = $sub['customer'];
    $status     = $sub['status'];
    $planId     = $sub['items']['data'][0]['price']['id'] ?? '';

    $stmt = $db->prepare("SELECT id FROM users WHERE stripe_customer_id = ?");
    $stmt->execute([$customerId]);
    $user = $stmt->fetch();
    if (!$user) return;

    $plan = getPlanFromPriceId($planId);

    if (in_array($status, ['active', 'trialing'])) {
        $periodEnd = date('Y-m-d H:i:s', $sub['current_period_end']);
        $db->prepare("UPDATE users SET plan = ?, plan_expires_at = ? WHERE id = ?")
           ->execute([$plan, $periodEnd, $user['id']]);
    }
}

function handleSubscriptionDeleted(PDO $db, array $sub): void {
    $customerId = $sub['customer'];
    $stmt = $db->prepare("SELECT id, first_name, email FROM users WHERE stripe_customer_id = ?");
    $stmt->execute([$customerId]);
    $user = $stmt->fetch();
    if (!$user) return;

    // Downgrade na starter
    $db->prepare("UPDATE users SET plan = 'starter', plan_expires_at = NULL WHERE id = ?")
       ->execute([$user['id']]);

    addNotification($db, $user['id'], 'warning',
        '⚠️ Subskrypcja anulowana',
        'Twoja subskrypcja została anulowana. Konto zostało przełączone na plan Starter.'
    );
}

function handlePaymentFailed(PDO $db, array $invoice): void {
    $customerId = $invoice['customer'];
    $stmt = $db->prepare("SELECT id, first_name, email FROM users WHERE stripe_customer_id = ?");
    $stmt->execute([$customerId]);
    $user = $stmt->fetch();
    if (!$user) return;

    addNotification($db, $user['id'], 'error',
        '❌ Płatność nieudana',
        'Nie udało się pobrać płatności. Zaktualizuj metodę płatności w ustawieniach.'
    );

    // E-mail z informacją
    $body = "Cześć {$user['first_name']},\n\nNie udało się pobrać płatności za Velqora.\n"
          . "Zaloguj się i zaktualizuj metodę płatności:\n" . APP_URL . "/dashboard.html\n\nZespół Velqora";
    @mail($user['email'], 'Płatność nieudana — Velqora', $body,
          "From: Velqora <kontakt@velqora.pl>\r\nContent-Type: text/plain; charset=utf-8");
}

function handleInvoicePaid(PDO $db, array $invoice): void {
    $customerId = $invoice['customer'];
    $stmt = $db->prepare("SELECT id FROM users WHERE stripe_customer_id = ?");
    $stmt->execute([$customerId]);
    $user = $stmt->fetch();
    if (!$user) return;

    // Zapisz odnowioną płatność
    $amount = ($invoice['amount_paid'] ?? 0) / 100;
    $db->prepare("INSERT INTO payments (user_id, stripe_session_id, plan, billing, amount, status, period_start, period_end)
                  SELECT ?, ?, plan, billing, ?, 'paid', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 31 DAY)
                  FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT 1")
       ->execute([$user['id'], $invoice['id'], $amount, $user['id']]);

    addNotification($db, $user['id'], 'payment',
        '✅ Płatność zakończona sukcesem',
        'Subskrypcja odnowiona. Kwota: ' . number_format($amount, 2) . ' zł'
    );
}

// ─────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────

function verifyWebhook(string $payload, string $sigHeader, string $secret): array {
    if (empty($sigHeader)) throw new Exception('Brak nagłówka Stripe-Signature');

    $parts    = [];
    $elements = explode(',', $sigHeader);
    foreach ($elements as $element) {
        [$key, $value] = explode('=', $element, 2);
        $parts[$key] = $value;
    }

    $timestamp      = $parts['t'] ?? 0;
    $signedPayload  = $timestamp . '.' . $payload;
    $expectedSig    = hash_hmac('sha256', $signedPayload, $secret);

    if (!hash_equals($expectedSig, $parts['v1'] ?? ''))
        throw new Exception('Nieprawidłowy podpis');

    if (abs(time() - (int)$timestamp) > 300)
        throw new Exception('Webhook zbyt stary');

    return json_decode($payload, true) ?? throw new Exception('Nieprawidłowy JSON');
}

function getPlanFromPriceId(string $priceId): string {
    $map = [
        STRIPE_PRICE_STARTER_MONTHLY  => 'starter',
        STRIPE_PRICE_STARTER_YEARLY   => 'starter',
        STRIPE_PRICE_PRO_MONTHLY      => 'pro',
        STRIPE_PRICE_PRO_YEARLY       => 'pro',
        STRIPE_PRICE_BUSINESS_MONTHLY => 'business',
        STRIPE_PRICE_BUSINESS_YEARLY  => 'business',
    ];
    return $map[$priceId] ?? 'starter';
}

function addNotification(PDO $db, int $userId, string $type, string $title, string $msg): void {
    $db->prepare("INSERT INTO notifications (user_id, type, title, message) VALUES (?, ?, ?, ?)")
       ->execute([$userId, $type, $title, $msg]);
}

function sendConfirmationEmail(string $to, string $name, string $plan, string $billing): void {
    $planNames = ['starter' => 'Starter', 'pro' => 'Pro', 'business' => 'Business'];
    $planName  = $planNames[$plan] ?? $plan;
    $body = "Cześć $name,\n\nDziękujemy za zakup planu $planName!\n\n"
          . "Twoje konto jest aktywne. Zaloguj się:\n" . APP_URL . "/login.html\n\n"
          . "W razie pytań pisz: kontakt@velqora.pl\n\nZespół Velqora";
    @mail($to, "Plan $planName aktywny — Velqora", $body,
          "From: Velqora <kontakt@velqora.pl>\r\nContent-Type: text/plain; charset=utf-8");
}

function logWebhookError(string $eventType, string $error): void {
    $log = date('[Y-m-d H:i:s]') . " EVENT: $eventType | ERROR: $error\n";
    @file_put_contents(__DIR__ . '/../logs/webhook_errors.log', $log, FILE_APPEND);
}
