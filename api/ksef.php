<?php
// ═══════════════════════════════════════════════════
// VELQORA — Integracja KSeF
// Krajowy System e-Faktur (MF API)
// Endpoint: /api/ksef.php
// Dokumentacja MF: https://www.podatki.gov.pl/ksef
// ═══════════════════════════════════════════════════
require_once __DIR__ . '/config.php';
setApiHeaders();

$user   = authUser();
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? '';

match($action) {
    'session-init'       => ksefSessionInit($user, $body),
    'session-status'     => ksefSessionStatus($user),
    'session-terminate'  => ksefSessionTerminate($user),
    'send-invoice'       => ksefSendInvoice($user, $body),
    'send-batch'         => ksefSendBatch($user, $body),
    'get-invoice'        => ksefGetInvoice($user, $_GET['ksef_number'] ?? ''),
    'query-invoices'     => ksefQueryInvoices($user, $body),
    'status'             => ksefStatus($user),
    'upo'                => ksefGetUPO($user, $_GET['reference'] ?? ''),
    default              => respond(['error' => 'Nieznana akcja KSeF'], 404),
};

// ═══════════════════════════════════════════════════
// KONFIGURACJA KSeF
// ═══════════════════════════════════════════════════
// ŚRODOWISKO:
// - TEST:       https://ksef-test.mf.gov.pl/api
// - PRODUKCJA:  https://ksef.mf.gov.pl/api
const KSEF_ENV     = 'test'; // zmień na 'prod' przed startem!
const KSEF_API_URL = KSEF_ENV === 'prod'
    ? 'https://ksef.mf.gov.pl/api'
    : 'https://ksef-test.mf.gov.pl/api';

// ═══════════════════════════════════════════════════
// 1. INICJALIZACJA SESJI KSeF
// ═══════════════════════════════════════════════════
function ksefSessionInit(array $user, array $body): void {
    $db      = getDB();
    $company = getCompany($db, $user['user_id']);
    if (!$company) respond(['error' => 'Uzupełnij dane firmy (NIP) w Ustawieniach'], 422);

    $nip     = preg_replace('/\D/', '', $company['nip']);
    $token   = $body['token'] ?? getKsefToken($db, $user['user_id']);

    if (empty($token)) respond(['error' => 'Brak tokenu autoryzacyjnego KSeF. Wygeneruj token w bramce MF.'], 422);

    // Zbuduj XML inicjalizacji sesji
    $xml = buildSessionInitXml($nip, $token);

    // Wyślij do KSeF API
    $response = ksefRequest('POST', '/online/Session/InitToken', $xml, 'application/octet-stream');

    if (!isset($response['sessionToken'])) {
        respond(['error' => 'Błąd inicjalizacji sesji KSeF: ' . ($response['exception']['description'] ?? 'Nieznany błąd')], 500);
    }

    // Zapisz token sesji w bazie
    $sessionToken = $response['sessionToken']['token'];
    $expires      = date('Y-m-d H:i:s', time() + 3600); // sesja ważna 1h
    $db->prepare("INSERT INTO ksef_sessions (user_id, session_token, nip, expires_at)
                  VALUES (?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE session_token=?, expires_at=?")
       ->execute([$user['user_id'], $sessionToken, $nip, $expires, $sessionToken, $expires]);

    respond([
        'success'       => true,
        'session_token' => $sessionToken,
        'expires_at'    => $expires,
        'message'       => 'Sesja KSeF zainicjalizowana pomyślnie',
    ]);
}

// ═══════════════════════════════════════════════════
// 2. STATUS SESJI
// ═══════════════════════════════════════════════════
function ksefSessionStatus(array $user): void {
    $db      = getDB();
    $session = getKsefSession($db, $user['user_id']);

    if (!$session) respond(['success' => true, 'active' => false, 'message' => 'Brak aktywnej sesji KSeF']);

    $response = ksefRequest('GET', '/online/Session/Status', null, 'application/json',
                            $session['session_token']);

    respond([
        'success'       => true,
        'active'        => true,
        'session_token' => $session['session_token'],
        'expires_at'    => $session['expires_at'],
        'processing'    => $response['processingCode'] ?? null,
    ]);
}

// ═══════════════════════════════════════════════════
// 3. ZAMKNIJ SESJĘ
// ═══════════════════════════════════════════════════
function ksefSessionTerminate(array $user): void {
    $db      = getDB();
    $session = getKsefSession($db, $user['user_id']);
    if (!$session) respond(['success' => true, 'message' => 'Brak aktywnej sesji']);

    ksefRequest('GET', '/online/Session/Terminate', null, 'application/json',
                $session['session_token']);

    $db->prepare("DELETE FROM ksef_sessions WHERE user_id = ?")->execute([$user['user_id']]);
    respond(['success' => true, 'message' => 'Sesja KSeF zakończona']);
}

// ═══════════════════════════════════════════════════
// 4. WYŚLIJ FAKTURĘ DO KSeF
// ═══════════════════════════════════════════════════
function ksefSendInvoice(array $user, array $body): void {
    $invoiceId = (int)($body['invoice_id'] ?? 0);
    if (!$invoiceId) respond(['error' => 'Podaj invoice_id'], 422);

    $db      = getDB();
    $session = getActiveSession($db, $user['user_id']);
    $company = getCompany($db, $user['user_id']);

    // Pobierz fakturę z bazy
    $stmt = $db->prepare("SELECT i.*, c.name as client_name, c.nip as client_nip,
                                 c.address as client_address, c.city as client_city,
                                 c.postal_code as client_postal
                          FROM invoices i LEFT JOIN clients c ON i.client_id = c.id
                          WHERE i.id = ? AND i.user_id = ?");
    $stmt->execute([$invoiceId, $user['user_id']]);
    $invoice = $stmt->fetch();
    if (!$invoice) respond(['error' => 'Faktura nie istnieje'], 404);

    // Pobierz pozycje
    $stmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
    $stmt->execute([$invoiceId]);
    $invoice['items'] = $stmt->fetchAll();

    // Zbuduj XML FA(2) — format KSeF
    $fa2xml = buildFA2Xml($invoice, $company);

    // Zakoduj w Base64
    $xmlBase64 = base64_encode($fa2xml);

    // Wyślij do KSeF
    $payload = json_encode([
        'invoiceHash' => [
            'hashSHA' => [
                'algorithm' => 'SHA-256',
                'encoding'  => 'Base64',
                'value'     => base64_encode(hash('sha256', $fa2xml, true)),
            ],
            'fileSize' => strlen($fa2xml),
        ],
        'invoicePayload' => [
            'type'       => 'plain',
            'invoiceBody'=> $xmlBase64,
        ],
    ]);

    $response = ksefRequest('PUT', '/online/Invoice/Send', $payload, 'application/json',
                            $session['session_token']);

    if (!isset($response['elementReferenceNumber'])) {
        respond(['error' => 'Błąd wysyłki do KSeF: ' . ($response['exception']['description'] ?? 'Nieznany błąd')], 500);
    }

    $ksefRef  = $response['elementReferenceNumber'];
    $ksefNum  = $response['ksefReferenceNumber'] ?? null;

    // Zapisz numer KSeF w bazie
    $db->prepare("UPDATE invoices SET ksef_reference = ?, ksef_number = ?,
                  ksef_status = 'sent', ksef_sent_at = NOW() WHERE id = ?")
       ->execute([$ksefRef, $ksefNum, $invoiceId]);

    // Zapisz log
    $db->prepare("INSERT INTO ksef_logs (user_id, invoice_id, action, ksef_reference, status, request_xml)
                  VALUES (?, ?, 'send', ?, 'sent', ?)")
       ->execute([$user['user_id'], $invoiceId, $ksefRef, $fa2xml]);

    respond([
        'success'          => true,
        'ksef_reference'   => $ksefRef,
        'ksef_number'      => $ksefNum,
        'message'          => 'Faktura wysłana do KSeF pomyślnie',
    ]);
}

// ═══════════════════════════════════════════════════
// 5. WYŚLIJ PAKIET FAKTUR (BATCH)
// ═══════════════════════════════════════════════════
function ksefSendBatch(array $user, array $body): void {
    $invoiceIds = $body['invoice_ids'] ?? [];
    if (empty($invoiceIds)) respond(['error' => 'Podaj listę invoice_ids'], 422);
    if (count($invoiceIds) > 100) respond(['error' => 'Maksymalnie 100 faktur w jednym pakiecie'], 422);

    $db      = getDB();
    $company = getCompany($db, $user['user_id']);
    $session = getActiveSession($db, $user['user_id']);

    $results  = [];
    $success  = 0;
    $failed   = 0;

    foreach ($invoiceIds as $invoiceId) {
        try {
            $stmt = $db->prepare("SELECT i.*, c.name as client_name, c.nip as client_nip,
                                         c.address as client_address
                                  FROM invoices i LEFT JOIN clients c ON i.client_id = c.id
                                  WHERE i.id = ? AND i.user_id = ?");
            $stmt->execute([$invoiceId, $user['user_id']]);
            $invoice = $stmt->fetch();
            if (!$invoice) { $failed++; continue; }

            $stmt = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
            $stmt->execute([$invoiceId]);
            $invoice['items'] = $stmt->fetchAll();

            $fa2xml    = buildFA2Xml($invoice, $company);
            $xmlBase64 = base64_encode($fa2xml);
            $payload   = json_encode([
                'invoiceHash'    => ['hashSHA' => ['algorithm'=>'SHA-256','encoding'=>'Base64','value'=>base64_encode(hash('sha256',$fa2xml,true))],'fileSize'=>strlen($fa2xml)],
                'invoicePayload' => ['type'=>'plain','invoiceBody'=>$xmlBase64],
            ]);

            $response = ksefRequest('PUT', '/online/Invoice/Send', $payload, 'application/json', $session['session_token']);

            if (isset($response['elementReferenceNumber'])) {
                $db->prepare("UPDATE invoices SET ksef_reference=?,ksef_status='sent',ksef_sent_at=NOW() WHERE id=?")
                   ->execute([$response['elementReferenceNumber'], $invoiceId]);
                $results[] = ['id' => $invoiceId, 'status' => 'sent', 'ref' => $response['elementReferenceNumber']];
                $success++;
            } else {
                $results[] = ['id' => $invoiceId, 'status' => 'error', 'error' => $response['exception']['description'] ?? 'Błąd'];
                $failed++;
            }
        } catch (Exception $e) {
            $results[] = ['id' => $invoiceId, 'status' => 'error', 'error' => $e->getMessage()];
            $failed++;
        }
    }

    respond([
        'success' => true,
        'summary' => ['sent' => $success, 'failed' => $failed, 'total' => count($invoiceIds)],
        'results' => $results,
    ]);
}

// ═══════════════════════════════════════════════════
// 6. POBIERZ FAKTURĘ Z KSeF
// ═══════════════════════════════════════════════════
function ksefGetInvoice(array $user, string $ksefNumber): void {
    if (empty($ksefNumber)) respond(['error' => 'Podaj numer KSeF'], 422);
    $db       = getDB();
    $session  = getActiveSession($db, $user['user_id']);
    $response = ksefRequest('GET', '/online/Invoice/Get/' . urlencode($ksefNumber),
                             null, 'application/json', $session['session_token']);
    respond(['success' => true, 'invoice' => $response]);
}

// ═══════════════════════════════════════════════════
// 7. ZAPYTANIE O FAKTURY (ZAKUPOWE/SPRZEDAŻOWE)
// ═══════════════════════════════════════════════════
function ksefQueryInvoices(array $user, array $body): void {
    $db      = getDB();
    $session = getActiveSession($db, $user['user_id']);
    $company = getCompany($db, $user['user_id']);

    $type      = $body['type'] ?? 'sales'; // sales | purchase
    $dateFrom  = $body['date_from'] ?? date('Y-m-01') . 'T00:00:00';
    $dateTo    = $body['date_to']   ?? date('Y-m-d')  . 'T23:59:59';
    $nip       = preg_replace('/\D/', '', $company['nip']);

    $queryXml = buildQueryXml($nip, $type, $dateFrom, $dateTo);
    $response = ksefRequest('POST', '/online/Query/Invoice/Sync', $queryXml,
                             'application/octet-stream', $session['session_token']);

    $invoices = $response['invoiceHeaderList'] ?? [];

    respond([
        'success'        => true,
        'count'          => count($invoices),
        'invoices'       => $invoices,
        'query_period'   => ['from' => $dateFrom, 'to' => $dateTo],
    ]);
}

// ═══════════════════════════════════════════════════
// 8. STATUS INTEGRACJI KSeF
// ═══════════════════════════════════════════════════
function ksefStatus(array $user): void {
    $db      = getDB();
    $session = getKsefSession($db, $user['user_id']);
    $company = getCompany($db, $user['user_id']);

    // Statystyki
    $stmt = $db->prepare("SELECT
        COUNT(*) as total,
        SUM(CASE WHEN ksef_status='sent' THEN 1 ELSE 0 END) as sent,
        SUM(CASE WHEN ksef_status IS NULL OR ksef_status='pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN ksef_status='error' THEN 1 ELSE 0 END) as errors
    FROM invoices WHERE user_id = ? AND status != 'draft'");
    $stmt->execute([$user['user_id']]);
    $stats = $stmt->fetch();

    respond([
        'success'        => true,
        'session_active' => $session && strtotime($session['expires_at']) > time(),
        'session_expires'=> $session['expires_at'] ?? null,
        'environment'    => KSEF_ENV,
        'api_url'        => KSEF_API_URL,
        'company_nip'    => $company ? $company['nip'] : null,
        'stats'          => $stats,
    ]);
}

// ═══════════════════════════════════════════════════
// 9. POBIERZ UPO (Urzędowe Poświadczenie Odbioru)
// ═══════════════════════════════════════════════════
function ksefGetUPO(array $user, string $reference): void {
    if (empty($reference)) respond(['error' => 'Podaj numer referencyjny'], 422);
    $db       = getDB();
    $session  = getActiveSession($db, $user['user_id']);
    $response = ksefRequest('GET', '/online/Invoice/SendStatus/' . urlencode($reference),
                             null, 'application/json', $session['session_token']);

    if (isset($response['processingCode']) && $response['processingCode'] === 200) {
        // Zaktualizuj numer KSeF w bazie
        if (!empty($response['invoiceStatus']['ksefReferenceNumber'])) {
            $db->prepare("UPDATE invoices SET ksef_number = ?, ksef_status = 'accepted'
                          WHERE ksef_reference = ? AND user_id = ?")
               ->execute([$response['invoiceStatus']['ksefReferenceNumber'], $reference, $user['user_id']]);
        }
    }

    respond(['success' => true, 'upo' => $response]);
}

// ═══════════════════════════════════════════════════
// BUILDER FA(2) XML — Format KSeF
// ═══════════════════════════════════════════════════
function buildFA2Xml(array $inv, array $company): string {
    $vatMap   = ['23' => 'A', '8' => 'B', '5' => 'C', '0' => 'D', 'ZW' => 'ZW'];
    $vatCode  = $vatMap[(string)$inv['vat_rate']] ?? 'A';
    $sellerNip= preg_replace('/\D/', '', $company['nip'] ?? '');
    $buyerNip = preg_replace('/\D/', '', $inv['client_nip'] ?? '');
    $issueDate= date('Y-m-d', strtotime($inv['issue_date']));
    $dueDate  = date('Y-m-d', strtotime($inv['due_date']));
    $net      = number_format((float)$inv['net_amount'], 2, '.', '');
    $vat      = number_format((float)$inv['vat_amount'], 2, '.', '');
    $gross    = number_format((float)$inv['gross_amount'], 2, '.', '');

    // Pozycje faktury
    $lines = '';
    $items = $inv['items'] ?? [['name' => $inv['service_desc'] ?? 'Usługa', 'quantity' => 1, 'unit' => 'szt.', 'unit_price' => $inv['net_amount'], 'net_total' => $inv['net_amount'], 'gross_total' => $inv['gross_amount']]];
    foreach ($items as $i => $item) {
        $lp    = $i + 1;
        $lNet  = number_format((float)$item['net_total'], 2, '.', '');
        $lGross= number_format((float)$item['gross_total'], 2, '.', '');
        $lPrice= number_format((float)$item['unit_price'], 2, '.', '');
        $lQty  = number_format((float)$item['quantity'], 2, '.', '');
        $lines .= "
        <fa:WierszFaktury>
            <fa:NrWiersza>$lp</fa:NrWiersza>
            <fa:P_6A>" . htmlspecialchars($item['name']) . "</fa:P_6A>
            <fa:P_7>" . htmlspecialchars($item['unit'] ?? 'szt.') . "</fa:P_7>
            <fa:P_8A>$lQty</fa:P_8A>
            <fa:P_9A>$lPrice</fa:P_9A>
            <fa:P_11>$lNet</fa:P_11>
            <fa:P_12>$vatCode</fa:P_12>
        </fa:WierszFaktury>";
    }

    return '<?xml version="1.0" encoding="UTF-8"?>
<Faktura xmlns:fa="http://crd.gov.pl/wzor/2023/06/29/12648/"
         xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <fa:Naglowek>
        <fa:KodFormularza kodSystemowy="FA (2)" wersjaSchemy="1-0E">FA</fa:KodFormularza>
        <fa:WariantFormularza>2</fa:WariantFormularza>
        <fa:DataWytworzeniaFa>' . date('Y-m-d\TH:i:s') . '</fa:DataWytworzeniaFa>
        <fa:SystemInfo>Velqora 1.0</fa:SystemInfo>
    </fa:Naglowek>
    <fa:Podmiot1>
        <fa:DaneIdentyfikacyjne>
            <fa:NIP>' . $sellerNip . '</fa:NIP>
            <fa:Nazwa>' . htmlspecialchars($company['name']) . '</fa:Nazwa>
        </fa:DaneIdentyfikacyjne>
        <fa:Adres>
            <fa:AdresL1>' . htmlspecialchars(($company['address'] ?? '') . ', ' . ($company['postal_code'] ?? '') . ' ' . ($company['city'] ?? '')) . '</fa:AdresL1>
        </fa:Adres>
    </fa:Podmiot1>
    <fa:Podmiot2>
        <fa:DaneIdentyfikacyjne>' .
            (!empty($buyerNip) ? "<fa:NIP>$buyerNip</fa:NIP>" : '<fa:BrakID>true</fa:BrakID>') . '
            <fa:Nazwa>' . htmlspecialchars($inv['client_name'] ?? 'Klient') . '</fa:Nazwa>
        </fa:DaneIdentyfikacyjne>
        <fa:Adres>
            <fa:AdresL1>' . htmlspecialchars($inv['client_address'] ?? '') . '</fa:AdresL1>
        </fa:Adres>
    </fa:Podmiot2>
    <fa:Fa>
        <fa:KodWaluty>PLN</fa:KodWaluty>
        <fa:P_1>' . $issueDate . '</fa:P_1>
        <fa:P_2>' . htmlspecialchars($inv['number']) . '</fa:P_2>
        <fa:P_15>' . $gross . '</fa:P_15>
        ' . $lines . '
        <fa:SumaWartosci>
            <fa:P_13_' . $vatCode . '>' . $net . '</fa:P_13_' . $vatCode . '>
            <fa:P_14_' . $vatCode . '>' . $vat . '</fa:P_14_' . $vatCode . '>
        </fa:SumaWartosci>
        <fa:Platnosc>
            <fa:TerminPlatnosci>
                <fa:Termin>' . $dueDate . '</fa:Termin>
            </fa:TerminPlatnosci>
            <fa:FormaPlatnosci>6</fa:FormaPlatnosci>' .
            (!empty($company['iban']) ? '<fa:RachunekBankowy><fa:NrRB>' . preg_replace('/\s/', '', $company['iban']) . '</fa:NrRB></fa:RachunekBankowy>' : '') . '
        </fa:Platnosc>
        <fa:Adnotacje>
            <fa:P_16>2</fa:P_16>
            <fa:P_17>2</fa:P_17>
            <fa:P_18>2</fa:P_18>
            <fa:P_18A>2</fa:P_18A>
            <fa:P_19>2</fa:P_19>
            <fa:P_22>2</fa:P_22>
            <fa:P_23>2</fa:P_23>
        </fa:Adnotacje>
    </fa:Fa>
</Faktura>';
}

// ═══════════════════════════════════════════════════
// BUILDER XML — Inicjalizacja sesji
// ═══════════════════════════════════════════════════
function buildSessionInitXml(string $nip, string $token): string {
    $ts = date('Y-m-d\TH:i:s\Z', gmtime());
    return '<?xml version="1.0" encoding="UTF-8"?>
<ns3:InitSessionTokenRequest xmlns:ns3="http://ksef.mf.gov.pl/schema/gtw/svc/online/types/v2">
    <ns3:Context>
        <ns3:Challenge>
            <ns3:Timestamp>' . $ts . '</ns3:Timestamp>
        </ns3:Challenge>
        <ns3:Identifier xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                        xsi:type="ns3:SubjectIdentifierByCompanyType">
            <ns3:Identifier>' . $nip . '</ns3:Identifier>
        </ns3:Identifier>
        <ns3:DocumentType>
            <ns3:Service>KSeF</ns3:Service>
            <ns3:FormCode>
                <ns3:SystemCode>FA (2)</ns3:SystemCode>
                <ns3:SchemaVersion>1-0E</ns3:SchemaVersion>
                <ns3:TargetNamespace>http://crd.gov.pl/wzor/2023/06/29/12648/</ns3:TargetNamespace>
                <ns3:Value>FA</ns3:Value>
            </ns3:FormCode>
        </ns3:DocumentType>
    </ns3:Context>
    <ns3:Token>' . $token . '</ns3:Token>
</ns3:InitSessionTokenRequest>';
}

// ═══════════════════════════════════════════════════
// BUILDER XML — Zapytanie o faktury
// ═══════════════════════════════════════════════════
function buildQueryXml(string $nip, string $type, string $dateFrom, string $dateTo): string {
    $role = $type === 'purchase' ? 'buyer' : 'seller';
    return '<?xml version="1.0" encoding="UTF-8"?>
<ns3:QueryCriteriaInvoiceRequest xmlns:ns3="http://ksef.mf.gov.pl/schema/gtw/svc/online/query/request/v2">
    <ns3:QueryCriteria>
        <ns3:SubjectType>' . $role . '</ns3:SubjectType>
        <ns3:DateRange>
            <ns3:StartDate>' . $dateFrom . '</ns3:StartDate>
            <ns3:EndDate>' . $dateTo . '</ns3:EndDate>
        </ns3:DateRange>
    </ns3:QueryCriteria>
</ns3:QueryCriteriaInvoiceRequest>';
}

// ═══════════════════════════════════════════════════
// HTTP CLIENT — Żądania do KSeF API
// ═══════════════════════════════════════════════════
function ksefRequest(string $method, string $endpoint, ?string $body, string $contentType, ?string $sessionToken = null): array {
    $url     = KSEF_API_URL . $endpoint;
    $headers = ['Content-Type: ' . $contentType, 'Accept: application/json'];
    if ($sessionToken) $headers[] = 'SessionToken: ' . $sessionToken;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($method === 'POST' || $method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) respond(['error' => 'Błąd połączenia z KSeF: ' . $error], 503);

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : ['raw' => $response, 'http_code' => $httpCode];
}

// ═══════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════
function getCompany(PDO $db, int $userId): ?array {
    $stmt = $db->prepare("SELECT * FROM companies WHERE user_id = ? LIMIT 1");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function getKsefSession(PDO $db, int $userId): ?array {
    $stmt = $db->prepare("SELECT * FROM ksef_sessions WHERE user_id = ? AND expires_at > NOW() LIMIT 1");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function getActiveSession(PDO $db, int $userId): array {
    $session = getKsefSession($db, $userId);
    if (!$session) respond(['error' => 'Brak aktywnej sesji KSeF. Zainicjuj sesję najpierw.'], 401);
    return $session;
}

function getKsefToken(PDO $db, int $userId): string {
    $stmt = $db->prepare("SELECT ksef_token FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch()['ksef_token'] ?? '';
}

if (!function_exists('gmtime')) {
    function gmtime(): int { return time(); }
}
