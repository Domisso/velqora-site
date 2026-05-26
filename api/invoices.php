<?php
// ═══════════════════════════════════════════════════
// VELQORA — API: Faktury
// Endpoint: /api/invoices.php
// ═══════════════════════════════════════════════════
require_once __DIR__ . '/config.php';
setApiHeaders();

$user   = authUser();
$method = $_SERVER['REQUEST_METHOD'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

match(true) {
    $method === 'GET'  && $action === 'list'   => listInvoices($user),
    $method === 'GET'  && $action === 'get'    => getInvoice($user, $id),
    $method === 'GET'  && $action === 'stats'  => invoiceStats($user),
    $method === 'POST' && $action === 'create' => createInvoice($user, $body),
    $method === 'PUT'  && $action === 'update' => updateInvoice($user, $id, $body),
    $method === 'POST' && $action === 'send'   => sendInvoice($user, $id),
    $method === 'POST' && $action === 'paid'   => markAsPaid($user, $id),
    $method === 'DELETE'                       => deleteInvoice($user, $id),
    default => respond(['error' => 'Nieznana akcja'], 404),
};

// ── LISTA FAKTUR ──────────────────────────────────────
function listInvoices(array $user): void {
    $db     = getDB();
    $status = $_GET['status'] ?? '';
    $limit  = min((int)($_GET['limit'] ?? 50), 100);
    $offset = (int)($_GET['offset'] ?? 0);

    $where  = "WHERE i.user_id = ?";
    $params = [$user['user_id']];

    if ($status) { $where .= " AND i.status = ?"; $params[] = $status; }

    $stmt = $db->prepare("SELECT i.*, c.name as client_name, c.email as client_email
                          FROM invoices i LEFT JOIN clients c ON i.client_id = c.id
                          $where ORDER BY i.created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();

    // Łączna liczba
    $stmt = $db->prepare("SELECT COUNT(*) FROM invoices i $where");
    $stmt->execute($params);
    $total = $stmt->fetchColumn();

    respond(['success' => true, 'invoices' => $invoices, 'total' => (int)$total]);
}

// ── POJEDYNCZA FAKTURA ────────────────────────────────
function getInvoice(array $user, int $id): void {
    $db   = getDB();
    $stmt = $db->prepare("SELECT i.*, c.name as client_name, c.nip as client_nip,
                                 c.email as client_email, c.address as client_address
                          FROM invoices i LEFT JOIN clients c ON i.client_id = c.id
                          WHERE i.id = ? AND i.user_id = ?");
    $stmt->execute([$id, $user['user_id']]);
    $invoice = $stmt->fetch();
    if (!$invoice) respond(['error' => 'Faktura nie istnieje'], 404);

    // Pobierz pozycje
    $stmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
    $stmt->execute([$id]);
    $invoice['items'] = $stmt->fetchAll();

    respond(['success' => true, 'invoice' => $invoice]);
}

// ── STATYSTYKI ────────────────────────────────────────
function invoiceStats(array $user): void {
    $db  = getDB();
    $uid = $user['user_id'];

    $stmt = $db->prepare("SELECT
        COUNT(*) as total,
        SUM(gross_amount) as total_gross,
        SUM(CASE WHEN status='paid' THEN gross_amount ELSE 0 END) as paid_gross,
        SUM(CASE WHEN status='overdue' THEN gross_amount ELSE 0 END) as overdue_gross,
        SUM(CASE WHEN MONTH(issue_date)=MONTH(NOW()) AND YEAR(issue_date)=YEAR(NOW()) THEN gross_amount ELSE 0 END) as this_month,
        COUNT(CASE WHEN status='overdue' THEN 1 END) as overdue_count
    FROM invoices WHERE user_id = ?");
    $stmt->execute([$uid]);
    respond(['success' => true, 'stats' => $stmt->fetch()]);
}

// ── UTWÓRZ FAKTURĘ ────────────────────────────────────
function createInvoice(array $user, array $d): void {
    // Walidacja
    if (empty($d['due_date']))   respond(['error' => 'Termin płatności jest wymagany'], 422);
    if (empty($d['service_desc']) && empty($d['items'])) respond(['error' => 'Opis usługi jest wymagany'], 422);

    $db  = getDB();
    $uid = $user['user_id'];

    // Generuj numer faktury
    $year  = date('Y');
    $month = date('m');
    $stmt  = $db->prepare("SELECT COUNT(*)+1 as next FROM invoices
                           WHERE user_id = ? AND YEAR(created_at)=? AND MONTH(created_at)=?");
    $stmt->execute([$uid, $year, $month]);
    $next   = $stmt->fetch()['next'];
    $number = $d['number'] ?? sprintf("FV/%s/%s/%03d", $year, $month, $next);

    // Oblicz kwoty
    $net   = (float)($d['net_amount'] ?? 0);
    $vat   = (int)($d['vat_rate'] ?? 23);
    $vatAmt= round($net * $vat / 100, 2);
    $gross = round($net + $vatAmt, 2);

    // Zapisz klienta jeśli nowy
    $clientId = $d['client_id'] ?? null;
    if (empty($clientId) && !empty($d['client_name'])) {
        $stmt = $db->prepare("INSERT INTO clients (user_id, name, nip, email, address)
                              VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$uid, $d['client_name'], $d['client_nip'] ?? null,
                        $d['client_email'] ?? null, $d['client_address'] ?? null]);
        $clientId = $db->lastInsertId();
    }

    $stmt = $db->prepare("INSERT INTO invoices
        (user_id, client_id, number, status, issue_date, due_date, service_desc,
         net_amount, vat_rate, vat_amount, gross_amount, currency, notes)
        VALUES (?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, 'PLN', ?)");
    $stmt->execute([
        $uid, $clientId, $number,
        $d['issue_date'] ?? date('Y-m-d'),
        $d['due_date'],
        $d['service_desc'] ?? '',
        $net, $vat, $vatAmt, $gross,
        $d['notes'] ?? null,
    ]);
    $invoiceId = $db->lastInsertId();

    // Zapisz pozycje jeśli przekazano
    if (!empty($d['items']) && is_array($d['items'])) {
        foreach ($d['items'] as $item) {
            $iNet   = (float)$item['unit_price'] * (float)$item['quantity'];
            $iGross = round($iNet * (1 + $vat / 100), 2);
            $stmt   = $db->prepare("INSERT INTO invoice_items
                (invoice_id, name, quantity, unit, unit_price, vat_rate, net_total, gross_total)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $invoiceId, $item['name'], $item['quantity'] ?? 1,
                $item['unit'] ?? 'szt.', $item['unit_price'], $vat, $iNet, $iGross,
            ]);
        }
    }

    respond(['success' => true, 'invoice_id' => $invoiceId, 'number' => $number], 201);
}

// ── ZAKTUALIZUJ FAKTURĘ ───────────────────────────────
function updateInvoice(array $user, int $id, array $d): void {
    $db   = getDB();
    $stmt = $db->prepare("SELECT id, status FROM invoices WHERE id = ? AND user_id = ?");
    $stmt->execute([$id, $user['user_id']]);
    $inv  = $stmt->fetch();
    if (!$inv) respond(['error' => 'Faktura nie istnieje'], 404);
    if ($inv['status'] === 'paid') respond(['error' => 'Opłacona faktura nie może być edytowana'], 422);

    $net   = (float)($d['net_amount'] ?? 0);
    $vat   = (int)($d['vat_rate'] ?? 23);
    $vatAmt= round($net * $vat / 100, 2);
    $gross = round($net + $vatAmt, 2);

    $db->prepare("UPDATE invoices SET service_desc=?, net_amount=?, vat_rate=?, vat_amount=?,
                  gross_amount=?, due_date=?, notes=? WHERE id=?")
       ->execute([$d['service_desc'] ?? '', $net, $vat, $vatAmt, $gross,
                  $d['due_date'] ?? date('Y-m-d'), $d['notes'] ?? null, $id]);

    respond(['success' => true]);
}

// ── WYŚLIJ FAKTURĘ E-MAILEM ───────────────────────────
function sendInvoice(array $user, int $id): void {
    $db   = getDB();
    $stmt = $db->prepare("SELECT i.*, c.email as client_email, c.name as client_name
                          FROM invoices i LEFT JOIN clients c ON i.client_id = c.id
                          WHERE i.id = ? AND i.user_id = ?");
    $stmt->execute([$id, $user['user_id']]);
    $inv  = $stmt->fetch();
    if (!$inv) respond(['error' => 'Faktura nie istnieje'], 404);

    $email = $inv['client_email'];
    if (empty($email)) respond(['error' => 'Klient nie ma podanego adresu e-mail'], 422);

    $body = "Dzień dobry,\n\nPrzesyłamy fakturę {$inv['number']} na kwotę {$inv['gross_amount']} PLN.\n"
          . "Termin płatności: {$inv['due_date']}\n\nDziękujemy za współpracę.\n\n" . APP_NAME;
    @mail($email, "Faktura {$inv['number']} — " . APP_NAME, $body,
          "From: " . APP_NAME . " <" . SMTP_FROM . ">\r\nContent-Type: text/plain; charset=utf-8");

    $db->prepare("UPDATE invoices SET status='sent', sent_at=NOW() WHERE id=?")->execute([$id]);
    respond(['success' => true, 'message' => "Faktura wysłana na $email"]);
}

// ── OZNACZ JAKO OPŁACONĄ ──────────────────────────────
function markAsPaid(array $user, int $id): void {
    $db = getDB();
    $db->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=? AND user_id=?")
       ->execute([$id, $user['user_id']]);
    respond(['success' => true]);
}

// ── USUŃ FAKTURĘ ──────────────────────────────────────
function deleteInvoice(array $user, int $id): void {
    $db = getDB();
    $db->prepare("DELETE FROM invoices WHERE id=? AND user_id=? AND status='draft'")
       ->execute([$id, $user['user_id']]);
    respond(['success' => true]);
}
