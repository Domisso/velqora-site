<?php
// ═══════════════════════════════════════════════════
// VELQORA — API: Klienci, Wydatki, Powiadomienia
// Endpoint: /api/clients.php | expenses.php | notifications.php
// ═══════════════════════════════════════════════════
// Ten plik to router — zapisz go jako 3 osobne pliki
// lub użyj ?module= do przełączania
// ═══════════════════════════════════════════════════
require_once __DIR__ . '/config.php';
setApiHeaders();

$user   = authUser();
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$module = $_GET['module'] ?? basename($_SERVER['PHP_SELF'], '.php');

match($module) {
    'clients'       => clientsRouter($user, $method, $action, $id, $body),
    'expenses'      => expensesRouter($user, $method, $action, $id, $body),
    'notifications' => notificationsRouter($user, $method, $action, $id, $body),
    'reports'       => reportsRouter($user, $method, $action, $body),
    default         => respond(['error' => 'Nieznany moduł'], 404),
};

// ═══════════════════════════════════════════════════
// KLIENCI
// ═══════════════════════════════════════════════════
function clientsRouter(array $user, string $method, string $action, int $id, array $body): void {
    $db  = getDB();
    $uid = $user['user_id'];

    if ($method === 'GET' && $action === 'list') {
        $search = $_GET['q'] ?? '';
        $where  = "WHERE user_id = ?";
        $params = [$uid];
        if ($search) { $where .= " AND (name LIKE ? OR nip LIKE ? OR email LIKE ?)"; $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]); }
        $stmt = $db->prepare("SELECT c.*, (SELECT COUNT(*) FROM invoices WHERE client_id=c.id) as invoice_count,
                              (SELECT SUM(gross_amount) FROM invoices WHERE client_id=c.id AND status='paid') as total_paid
                              FROM clients c $where ORDER BY name ASC LIMIT 100");
        $stmt->execute($params);
        respond(['success' => true, 'clients' => $stmt->fetchAll()]);
    }

    if ($method === 'GET' && $action === 'get' && $id) {
        $stmt = $db->prepare("SELECT * FROM clients WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $uid]);
        $c = $stmt->fetch();
        if (!$c) respond(['error' => 'Klient nie istnieje'], 404);
        respond(['success' => true, 'client' => $c]);
    }

    if ($method === 'POST' && $action === 'create') {
        if (empty($body['name'])) respond(['error' => 'Nazwa klienta jest wymagana'], 422);
        $db->prepare("INSERT INTO clients (user_id,name,nip,email,phone,address,city,postal_code,country,notes)
                      VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$uid, $body['name'], $body['nip']??null, $body['email']??null, $body['phone']??null,
                      $body['address']??null, $body['city']??null, $body['postal_code']??null,
                      $body['country']??'Polska', $body['notes']??null]);
        respond(['success' => true, 'id' => $db->lastInsertId()], 201);
    }

    if ($method === 'PUT' && $id) {
        $db->prepare("UPDATE clients SET name=?,nip=?,email=?,phone=?,address=?,city=?,postal_code=?,notes=?
                      WHERE id=? AND user_id=?")
           ->execute([$body['name']??'', $body['nip']??null, $body['email']??null, $body['phone']??null,
                      $body['address']??null, $body['city']??null, $body['postal_code']??null,
                      $body['notes']??null, $id, $uid]);
        respond(['success' => true]);
    }

    if ($method === 'DELETE' && $id) {
        $db->prepare("DELETE FROM clients WHERE id=? AND user_id=?")->execute([$id, $uid]);
        respond(['success' => true]);
    }

    respond(['error' => 'Nieznana akcja'], 404);
}

// ═══════════════════════════════════════════════════
// WYDATKI
// ═══════════════════════════════════════════════════
function expensesRouter(array $user, string $method, string $action, int $id, array $body): void {
    $db  = getDB();
    $uid = $user['user_id'];

    if ($method === 'GET' && $action === 'list') {
        $month = $_GET['month'] ?? date('Y-m');
        $stmt  = $db->prepare("SELECT * FROM expenses WHERE user_id = ?
                               AND DATE_FORMAT(date,'%Y-%m') = ?
                               ORDER BY date DESC");
        $stmt->execute([$uid, $month]);
        $expenses = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT SUM(amount) as total, SUM(vat_amount) as total_vat,
                              COUNT(*) as count FROM expenses WHERE user_id=? AND DATE_FORMAT(date,'%Y-%m')=?");
        $stmt->execute([$uid, $month]);
        $stats = $stmt->fetch();
        respond(['success' => true, 'expenses' => $expenses, 'stats' => $stats]);
    }

    if ($method === 'GET' && $action === 'categories') {
        $stmt = $db->prepare("SELECT category, SUM(amount) as total, COUNT(*) as count
                              FROM expenses WHERE user_id=? AND YEAR(date)=YEAR(NOW())
                              GROUP BY category ORDER BY total DESC");
        $stmt->execute([$uid]);
        respond(['success' => true, 'categories' => $stmt->fetchAll()]);
    }

    if ($method === 'POST' && $action === 'create') {
        if (empty($body['description']) || empty($body['amount']))
            respond(['error' => 'Opis i kwota są wymagane'], 422);
        $vat = round((float)$body['amount'] * 0.23, 2);
        $db->prepare("INSERT INTO expenses (user_id,category,description,amount,vat_amount,currency,date,notes)
                      VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$uid, $body['category']??'Inne', $body['description'], $body['amount'],
                      $body['vat_amount']??$vat, $body['currency']??'PLN',
                      $body['date']??date('Y-m-d'), $body['notes']??null]);
        respond(['success' => true, 'id' => $db->lastInsertId()], 201);
    }

    if ($method === 'DELETE' && $id) {
        $db->prepare("DELETE FROM expenses WHERE id=? AND user_id=?")->execute([$id, $uid]);
        respond(['success' => true]);
    }

    respond(['error' => 'Nieznana akcja'], 404);
}

// ═══════════════════════════════════════════════════
// POWIADOMIENIA
// ═══════════════════════════════════════════════════
function notificationsRouter(array $user, string $method, string $action, int $id, array $body): void {
    $db  = getDB();
    $uid = $user['user_id'];

    if ($action === 'list') {
        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 30");
        $stmt->execute([$uid]);
        respond(['success' => true, 'notifications' => $stmt->fetchAll()]);
    }

    if ($action === 'count') {
        $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $stmt->execute([$uid]);
        respond(['success' => true, 'unread' => (int)$stmt->fetchColumn()]);
    }

    if ($action === 'read') {
        $notifId = $body['id'] ?? null;
        if ($notifId) {
            $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$notifId, $uid]);
        }
        respond(['success' => true]);
    }

    if ($action === 'read-all') {
        $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$uid]);
        respond(['success' => true]);
    }

    respond(['error' => 'Nieznana akcja'], 404);
}

// ═══════════════════════════════════════════════════
// RAPORTY
// ═══════════════════════════════════════════════════
function reportsRouter(array $user, string $method, string $action, array $body): void {
    $db  = getDB();
    $uid = $user['user_id'];

    if ($action === 'dashboard') {
        $month = $_GET['month'] ?? date('Y-m');
        [$year, $mon] = explode('-', $month);

        // Przychody
        $stmt = $db->prepare("SELECT COALESCE(SUM(gross_amount),0) as revenue,
                              COALESCE(SUM(vat_amount),0) as vat_collected,
                              COUNT(*) as invoice_count
                              FROM invoices WHERE user_id=? AND status='paid'
                              AND YEAR(issue_date)=? AND MONTH(issue_date)=?");
        $stmt->execute([$uid, $year, $mon]);
        $revenue = $stmt->fetch();

        // Koszty
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount),0) as expenses,
                              COALESCE(SUM(vat_amount),0) as vat_paid
                              FROM expenses WHERE user_id=?
                              AND YEAR(date)=? AND MONTH(date)=?");
        $stmt->execute([$uid, $year, $mon]);
        $costs = $stmt->fetch();

        // Faktury nierozliczone
        $stmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(gross_amount),0) as amount
                              FROM invoices WHERE user_id=? AND status IN('sent','overdue')");
        $stmt->execute([$uid]);
        $pending = $stmt->fetch();

        // Wykres 12 miesięcy
        $stmt = $db->prepare("SELECT DATE_FORMAT(issue_date,'%Y-%m') as month,
                              SUM(gross_amount) as revenue
                              FROM invoices WHERE user_id=? AND status='paid'
                              AND issue_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                              GROUP BY month ORDER BY month ASC");
        $stmt->execute([$uid]);
        $chart = $stmt->fetchAll();

        // VAT do zapłaty (różnica)
        $vatDue = max(0, (float)$revenue['vat_collected'] - (float)$costs['vat_paid']);

        respond([
            'success'  => true,
            'month'    => $month,
            'revenue'  => $revenue,
            'costs'    => $costs,
            'pending'  => $pending,
            'vat_due'  => $vatDue,
            'profit'   => (float)$revenue['revenue'] - (float)$costs['expenses'],
            'chart'    => $chart,
        ]);
    }

    if ($action === 'jpk-data') {
        $month = $_GET['month'] ?? date('Y-m');
        [$year, $mon] = explode('-', $month);

        $stmt = $db->prepare("SELECT i.number, i.issue_date, i.net_amount, i.vat_amount, i.gross_amount,
                              i.vat_rate, c.name as client_name, c.nip as client_nip
                              FROM invoices i LEFT JOIN clients c ON i.client_id = c.id
                              WHERE i.user_id=? AND YEAR(i.issue_date)=? AND MONTH(i.issue_date)=?
                              AND i.status != 'draft' ORDER BY i.issue_date ASC");
        $stmt->execute([$uid, $year, $mon]);
        $invoices = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT description, date, amount, vat_amount FROM expenses
                              WHERE user_id=? AND YEAR(date)=? AND MONTH(date)=?");
        $stmt->execute([$uid, $year, $mon]);
        $expenses = $stmt->fetchAll();

        respond(['success' => true, 'invoices' => $invoices, 'expenses' => $expenses, 'month' => $month]);
    }

    respond(['error' => 'Nieznana akcja raportu'], 404);
}
