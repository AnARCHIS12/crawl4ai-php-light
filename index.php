<?php
/**
 * LITECLAW v2 — Intelligent Web Scraper with AI Loop
 * PHP 8.3 | Hostinger Mutualisé Compatible
 * NO exec/shell_exec | cURL ONLY | dirname(__FILE__) paths | 0755/0644 perms
 */

define('ROOT_PATH', dirname(__FILE__));
define('DB_PATH', ROOT_PATH . '/data/liteclaw.sqlite');
define('LOG_PATH', ROOT_PATH . '/data/error.log');

define('MISTRAL_KEYS', [
    '5qaRRake',
    'o3rGShytu',
    'vEzQMFruXkF'
]);
define('MISTRAL_ENDPOINT', 'https://api.mistral.ai/v1/chat/completions');
define('MISTRAL_MODEL', 'mistral-small-2603'); // Fast Automate Turbo for scrape analysis

// ─── Init data dir ────────────────────────────────────────────────────────────
$dataDir = ROOT_PATH . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

// ─── SQLite Init ──────────────────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_key TEXT NOT NULL,
            url TEXT NOT NULL,
            scraped_content TEXT,
            conversation TEXT DEFAULT '[]',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        chmod(DB_PATH, 0644);
    }
    return $pdo;
}

// ─── cURL helper ──────────────────────────────────────────────────────────────
function curlGet(string $url, array $headers = [], int $timeout = 20): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 4,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; LiteClaw/2.0; +https://web-4.art)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => 'gzip, deflate',
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    return ['body' => $body ?: '', 'status' => $status, 'error' => $err];
}

function curlPost(string $url, array $payload, array $headers = [], int $timeout = 28): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'LiteClaw/2.0 PHP-Agent',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    return ['body' => $body ?: '', 'status' => $status, 'error' => $err];
}

// ─── Mistral key rotation ─────────────────────────────────────────────────────
function getMistralKey(): string {
    $keys = MISTRAL_KEYS;
    return $keys[array_rand($keys)];
}

function callMistral(array $messages, string $model = MISTRAL_MODEL, int $maxTokens = 1200): ?string {
    $payload = [
        'model'       => $model,
        'max_tokens'  => $maxTokens,
        'temperature' => 0.65,
        'messages'    => $messages
    ];
    $headers = ['Authorization: Bearer ' . getMistralKey()];
    $res     = curlPost(MISTRAL_ENDPOINT, $payload, $headers, 28);

    if ($res['error']) {
        logError('cURL Mistral: ' . $res['error']);
        return null;
    }
    $data = json_decode($res['body'], true);
    return $data['choices'][0]['message']['content'] ?? null;
}

// ─── HTML → clean text ────────────────────────────────────────────────────────
function htmlToText(string $html): string {
    // Remove scripts, styles, nav, footer noise
    $html = preg_replace('/<(script|style|nav|footer|header|aside)[^>]*>.*?<\/\1>/si', '', $html);
    $html = preg_replace('/<[^>]+>/', ' ', $html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s{2,}/', ' ', $html);
    return trim(substr($text, 0, 6000)); // Cap at 6000 chars for context
}

function logError(string $msg): void {
    $line = date('[Y-m-d H:i:s] ') . $msg . "\n";
    @file_put_contents(LOG_PATH, $line, FILE_APPEND | LOCK_EX);
    @chmod(LOG_PATH, 0644);
}

// ─── AJAX Handlers ────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? ($_GET['action'] ?? '');

if (!$action) {
    // Serve HTML interface
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/interface.html');
    exit;
}

try {
    $db = getDB();

    // ── ACTION: scrape ─────────────────────────────────────────────────────────
    if ($action === 'scrape') {
        $url = filter_var(trim($input['url'] ?? ''), FILTER_VALIDATE_URL);
        if (!$url) { echo json_encode(['ok' => false, 'error' => 'URL invalide']); exit; }

        $res = curlGet($url, [], 18);
        if ($res['error'] || $res['status'] < 200 || $res['status'] >= 400) {
            echo json_encode(['ok' => false, 'error' => 'Impossible d\'accéder à cette URL (HTTP ' . $res['status'] . ')']);
            exit;
        }

        $text = htmlToText($res['body']);
        if (strlen($text) < 80) {
            echo json_encode(['ok' => false, 'error' => 'Contenu trop court ou page vide']);
            exit;
        }

        // Ask Mistral to summarize + generate 5 smart questions
        $systemPrompt = "Tu es un assistant d'analyse web expert. On te donne le contenu textuel d'une page web scrapée. 
Tu dois répondre UNIQUEMENT en JSON valide avec cette structure exacte (sans markdown, sans backticks) :
{
  \"summary\": \"Résumé clair et détaillé de la page en 3-5 phrases\",
  \"topics\": [\"topic1\", \"topic2\", \"topic3\"],
  \"questions\": [
    \"Question pertinente 1 sur le contenu ?\",
    \"Question pertinente 2 ?\",
    \"Question pertinente 3 ?\",
    \"Question pertinente 4 ?\",
    \"Question pertinente 5 ?\"
  ]
}";

        $userMsg = "Voici le contenu scrapé de la page : " . $url . "\n\n---\n" . $text . "\n---\n\nAnalyse et génère 5 questions pertinentes.";

        $raw = callMistral([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userMsg]
        ], 'mistral-small-2603', 900);

        $parsed = $raw ? json_decode($raw, true) : null;
        if (!$parsed || !isset($parsed['summary'])) {
            // Fallback if JSON parse fails
            $parsed = [
                'summary'   => $raw ?? 'Contenu analysé.',
                'topics'    => [],
                'questions' => ['Que contient cette page ?', 'Quelle est la source principale ?', 'Quelles informations clés peut-on extraire ?', 'Y a-t-il des données chiffrées ?', 'Quel est le public cible ?']
            ];
        }

        // Store session
        $sessionKey = bin2hex(random_bytes(8));
        $conversation = json_encode([
            ['role' => 'system', 'content' => "Tu es un assistant d'analyse web. Le contexte est la page : $url\n\nContenu:\n$text"],
            ['role' => 'assistant', 'content' => $parsed['summary']]
        ]);

        $stmt = $db->prepare("INSERT INTO sessions (session_key, url, scraped_content, conversation) VALUES (?,?,?,?)");
        $stmt->execute([$sessionKey, $url, $text, $conversation]);

        echo json_encode([
            'ok'         => true,
            'session'    => $sessionKey,
            'url'        => $url,
            'summary'    => $parsed['summary'],
            'topics'     => $parsed['topics'] ?? [],
            'questions'  => $parsed['questions'] ?? [],
        ]);
        exit;
    }

    // ── ACTION: ask ────────────────────────────────────────────────────────────
    if ($action === 'ask') {
        $sessionKey = trim($input['session'] ?? '');
        $question   = trim($input['question'] ?? '');

        if (!$sessionKey || !$question) {
            echo json_encode(['ok' => false, 'error' => 'Session ou question manquante']); exit;
        }

        $stmt = $db->prepare("SELECT * FROM sessions WHERE session_key = ? LIMIT 1");
        $stmt->execute([$sessionKey]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) { echo json_encode(['ok' => false, 'error' => 'Session expirée']); exit; }

        $conversation = json_decode($session['conversation'], true) ?? [];
        $conversation[] = ['role' => 'user', 'content' => $question];

        // System + follow-up question gen instructions appended
        $fullMessages = $conversation;
        // Add instruction to generate follow-up questions
        $fullMessages[] = [
            'role' => 'system',
            'content' => 'À la fin de ta réponse, ajoute toujours une section JSON séparée par le marqueur |||QUESTIONS||| contenant 4 nouvelles questions de suivi au format JSON array. Exemple: |||QUESTIONS|||["Q1?","Q2?","Q3?","Q4?"]'
        ];

        $raw = callMistral($fullMessages, 'mistral-medium-2505', 1100);

        if (!$raw) {
            echo json_encode(['ok' => false, 'error' => 'Erreur API Mistral']); exit;
        }

        // Parse answer + follow-up questions
        $answerText = $raw;
        $followUps  = [];
        if (strpos($raw, '|||QUESTIONS|||') !== false) {
            $parts      = explode('|||QUESTIONS|||', $raw, 2);
            $answerText = trim($parts[0]);
            $qRaw       = trim($parts[1] ?? '[]');
            $followUps  = json_decode($qRaw, true) ?? [];
        }

        // If no follow-ups parsed, generate defaults
        if (empty($followUps)) {
            $followUps = ['Peux-tu approfondir ce point ?', 'Y a-t-il d\'autres aspects à analyser ?', 'Quelles sont les implications ?', 'Peux-tu donner des exemples concrets ?'];
        }

        // Update conversation (keep it lean — max 10 turns)
        $conversation[] = ['role' => 'assistant', 'content' => $answerText];
        if (count($conversation) > 22) { // ~11 turns
            // Keep system context + last 10 exchanges
            $system = array_shift($conversation);
            $conversation = array_slice($conversation, -20);
            array_unshift($conversation, $system);
        }

        $stmt = $db->prepare("UPDATE sessions SET conversation=?, updated_at=CURRENT_TIMESTAMP WHERE session_key=?");
        $stmt->execute([json_encode($conversation), $sessionKey]);

        echo json_encode([
            'ok'        => true,
            'answer'    => $answerText,
            'questions' => array_slice($followUps, 0, 4),
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Action inconnue']);

} catch (Throwable $e) {
    logError($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur interne']);
}
