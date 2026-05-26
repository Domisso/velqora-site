<?php
// ═══════════════════════════════════════════════════
// VELQORA — API: Analiza AI (Anthropic Claude)
// Endpoint: /api/ai.php
// ═══════════════════════════════════════════════════
require_once __DIR__ . '/config.php';
setApiHeaders();

$user   = authUser();
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? '';

match($action) {
    'analyze'  => analyzeDocument($user, $body),
    'ask'      => askQuestion($user, $body),
    'compare'  => compareDocuments($user, $body),
    'history'  => analysisHistory($user),
    'delete'   => deleteAnalysis($user, (int)($_GET['id'] ?? 0)),
    default    => respond(['error' => 'Nieznana akcja'], 404),
};

// ── ANALIZA DOKUMENTU ─────────────────────────────────
function analyzeDocument(array $user, array $body): void {
    $db  = getDB();
    $uid = $user['user_id'];

    // Sprawdź limit planu
    checkAiLimit($db, $uid, $user['plan']);

    $filename = $body['filename'] ?? 'dokument.pdf';
    $b64      = $body['file_base64'] ?? '';
    $mimeType = $body['mime_type'] ?? 'application/pdf';

    if (empty($b64)) respond(['error' => 'Brak zawartości pliku'], 422);

    // Zapisz dokument w bazie
    $docId = saveDocument($db, $uid, $filename, $b64, $mimeType);

    // Zbuduj wiadomość do Claude
    $messages = [];
    if ($mimeType === 'application/pdf') {
        $messages[] = [
            'role'    => 'user',
            'content' => [
                ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]],
                ['type' => 'text', 'text' => 'Przeprowadź pełną analizę tego dokumentu zgodnie z instrukcjami.'],
            ],
        ];
    } else {
        $text = base64_decode($b64);
        $messages[] = ['role' => 'user', 'content' => "Przeanalizuj poniższy dokument:\n\n" . $text];
    }

    // Wywołaj Anthropic API
    $analysis = callClaude($messages, SYSTEM_PROMPT_ANALYSIS);

    // Zapisz analizę
    $db->prepare("UPDATE ai_documents SET analysis = ?, status = 'done' WHERE id = ?")
       ->execute([$analysis, $docId]);

    // Zapisz do historii chatu
    $db->prepare("INSERT INTO ai_chat (document_id, role, content) VALUES (?, 'user', ?), (?, 'assistant', ?)")
       ->execute([$docId, 'Analiza dokumentu: ' . $filename, $docId, $analysis]);

    // Zwiększ licznik AI
    incrementAiUsage($db, $uid);

    respond([
        'success'     => true,
        'document_id' => $docId,
        'analysis'    => $analysis,
    ]);
}

// ── PYTANIE O DOKUMENT ────────────────────────────────
function askQuestion(array $user, array $body): void {
    $db      = getDB();
    $docId   = (int)($body['document_id'] ?? 0);
    $question= trim($body['question'] ?? '');

    if (!$docId || empty($question)) respond(['error' => 'Podaj document_id i question'], 422);

    // Sprawdź właściciela
    $stmt = $db->prepare("SELECT id, analysis FROM ai_documents WHERE id = ? AND user_id = ?");
    $stmt->execute([$docId, $user['user_id']]);
    $doc = $stmt->fetch();
    if (!$doc) respond(['error' => 'Dokument nie istnieje'], 404);

    // Pobierz historię chatu
    $stmt = $db->prepare("SELECT role, content FROM ai_chat WHERE document_id = ? ORDER BY created_at ASC");
    $stmt->execute([$docId]);
    $history = $stmt->fetchAll();

    // Zbuduj historię wiadomości
    $messages = [];
    foreach ($history as $msg) {
        $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $question];

    // Wywołaj Claude
    $answer = callClaude($messages, SYSTEM_PROMPT_ANALYSIS);

    // Zapisz w historii
    $db->prepare("INSERT INTO ai_chat (document_id, role, content) VALUES (?, 'user', ?), (?, 'assistant', ?)")
       ->execute([$docId, $question, $docId, $answer]);

    // Zwiększ licznik pytań
    $db->prepare("UPDATE ai_documents SET questions = questions + 1 WHERE id = ?")->execute([$docId]);

    respond(['success' => true, 'answer' => $answer]);
}

// ── PORÓWNANIE DOKUMENTÓW ─────────────────────────────
function compareDocuments(array $user, array $body): void {
    $db  = getDB();
    checkAiLimit($db, $user['user_id'], $user['plan']);

    $fileA    = $body['file_a'] ?? [];
    $fileB    = $body['file_b'] ?? [];

    if (empty($fileA['base64']) || empty($fileB['base64']))
        respond(['error' => 'Wymagane oba dokumenty (file_a i file_b)'], 422);

    $content = [];

    // Dodaj dokument A
    if (($fileA['mime'] ?? '') === 'application/pdf') {
        $content[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $fileA['base64']]];
        $content[] = ['type' => 'text', 'text' => 'To jest DOKUMENT A: ' . ($fileA['name'] ?? 'Dokument A')];
    } else {
        $content[] = ['type' => 'text', 'text' => "DOKUMENT A ({$fileA['name']}):\n" . base64_decode($fileA['base64'])];
    }

    // Dodaj dokument B
    if (($fileB['mime'] ?? '') === 'application/pdf') {
        $content[] = ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $fileB['base64']]];
        $content[] = ['type' => 'text', 'text' => 'To jest DOKUMENT B: ' . ($fileB['name'] ?? 'Dokument B')];
    } else {
        $content[] = ['type' => 'text', 'text' => "DOKUMENT B ({$fileB['name']}):\n" . base64_decode($fileB['base64'])];
    }

    $content[] = ['type' => 'text', 'text' => 'Porównaj oba dokumenty zgodnie z instrukcjami.'];

    $comparison = callClaude([['role' => 'user', 'content' => $content]], SYSTEM_PROMPT_COMPARE);
    incrementAiUsage($db, $user['user_id']);

    respond(['success' => true, 'comparison' => $comparison]);
}

// ── HISTORIA ANALIZ ───────────────────────────────────
function analysisHistory(array $user): void {
    $db   = getDB();
    $stmt = $db->prepare("SELECT id, filename, status, questions, created_at,
                          LEFT(analysis, 200) as analysis_preview
                          FROM ai_documents WHERE user_id = ? AND status = 'done'
                          ORDER BY created_at DESC LIMIT 30");
    $stmt->execute([$user['user_id']]);
    respond(['success' => true, 'documents' => $stmt->fetchAll()]);
}

// ── USUŃ ANALIZĘ ──────────────────────────────────────
function deleteAnalysis(array $user, int $id): void {
    $db = getDB();
    $db->prepare("DELETE FROM ai_documents WHERE id = ? AND user_id = ?")->execute([$id, $user['user_id']]);
    respond(['success' => true]);
}

// ── ANTHROPIC API ─────────────────────────────────────
function callClaude(array $messages, string $system): string {
    $payload = json_encode([
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 2000,
        'system'     => $system,
        'messages'   => $messages,
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: pdfs-2024-09-25',
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) respond(['error' => 'Błąd połączenia z AI: ' . $error], 503);

    $data = json_decode($response, true);
    if (!isset($data['content'][0]['text']))
        respond(['error' => 'Błąd odpowiedzi AI: ' . ($data['error']['message'] ?? 'Nieznany błąd')], 500);

    return $data['content'][0]['text'];
}

// ── HELPERS ───────────────────────────────────────────
function checkAiLimit(PDO $db, int $uid, string $plan): void {
    $limits = unserialize(PLAN_LIMITS);
    $limit  = $limits[$plan]['ai_analyses'] ?? 10;
    $stmt   = $db->prepare("SELECT COUNT(*) FROM ai_documents WHERE user_id = ?
                            AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())");
    $stmt->execute([$uid]);
    if ($stmt->fetchColumn() >= $limit)
        respond(['error' => "Osiągnięto limit analiz AI dla planu " . ucfirst($plan) . " ($limit/mies.). Ulepsz plan."], 403);
}

function saveDocument(PDO $db, int $uid, string $filename, string $b64, string $mime): int {
    $size = strlen(base64_decode($b64));
    $db->prepare("INSERT INTO ai_documents (user_id, filename, file_path, file_size, mime_type, status)
                  VALUES (?, ?, ?, ?, ?, 'processing')")
       ->execute([$uid, $filename, 'base64_in_memory', $size, $mime]);
    return $db->lastInsertId();
}

function incrementAiUsage(PDO $db, int $uid): void {
    // Opcjonalne: logowanie użycia
}

const SYSTEM_PROMPT_ANALYSIS = 'Jesteś ekspertem prawnym i finansowym. Analizujesz dokumenty dla prawników i księgowych.
Twoja analiza ZAWSZE zawiera:
1. **TYP DOKUMENTU** — zidentyfikuj rodzaj dokumentu
2. **STRESZCZENIE** — kluczowe informacje w 3-5 zdaniach
3. **STRONY / PODMIOTY** — kto jest wymieniony
4. **KLUCZOWE KLAUZULE / POZYCJE** — ważne zapisy, kwoty, daty
5. **RYZYKA I UWAGI** — czerwone flagi prawne lub finansowe
6. **REKOMENDACJE** — co warto sprawdzić lub doprecyzować
Odpowiadaj po polsku. Bądź precyzyjny i profesjonalny.';

const SYSTEM_PROMPT_COMPARE = 'Jesteś ekspertem prawnym. Porównujesz dwa dokumenty.
Twoja analiza zawiera:
1. **PODOBIEŃSTWA** — co mają wspólnego
2. **KLUCZOWE RÓŻNICE** — punkt po punkcie
3. **ZMIANY KORZYSTNE** — co jest lepsze w dok. B vs A
4. **ZMIANY NIEKORZYSTNE** — co jest gorsze w dok. B vs A
5. **REKOMENDACJA** — który dokument jest korzystniejszy
Odpowiadaj po polsku, precyzyjnie.';
