<?php
/**
 * Libre Search — Intelligent Web Scraper with AI Loop
 * PHP 8.3 | Hostinger Mutualisé Compatible
 * NO exec/shell_exec | cURL ONLY | dirname(__FILE__) paths | 0755/0644 perms
 */

define('ROOT_PATH', dirname(__FILE__));
define('DB_PATH', ROOT_PATH . '/data/libre_search.sqlite');
define('LOG_PATH', ROOT_PATH . '/data/error.log');
define('CONFIG_PATH', ROOT_PATH . '/data/config.json');

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
            summary TEXT,
            topics TEXT DEFAULT '[]',
            questions TEXT DEFAULT '[]',
            sources TEXT DEFAULT '[]',
            crawl_mode TEXT DEFAULT 'single',
            page_count INTEGER DEFAULT 1,
            conversation TEXT DEFAULT '[]',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        ensureColumn($pdo, 'summary', 'TEXT');
        ensureColumn($pdo, 'topics', "TEXT DEFAULT '[]'");
        ensureColumn($pdo, 'questions', "TEXT DEFAULT '[]'");
        ensureColumn($pdo, 'sources', "TEXT DEFAULT '[]'");
        ensureColumn($pdo, 'crawl_mode', "TEXT DEFAULT 'single'");
        ensureColumn($pdo, 'page_count', 'INTEGER DEFAULT 1');
        chmod(DB_PATH, 0644);
    }
    return $pdo;
}

function ensureColumn(PDO $pdo, string $column, string $definition): void {
    $columns = $pdo->query("PRAGMA table_info(sessions)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $existing) {
        if (($existing['name'] ?? '') === $column) {
            return;
        }
    }
    $pdo->exec("ALTER TABLE sessions ADD COLUMN $column $definition");
}

// ─── App configuration ───────────────────────────────────────────────────────
function loadConfig(): array {
    if (!is_file(CONFIG_PATH)) {
        return [];
    }
    $raw = file_get_contents(CONFIG_PATH);
    $config = json_decode($raw ?: '{}', true);
    return is_array($config) ? $config : [];
}

function saveConfig(array $config): void {
    file_put_contents(CONFIG_PATH, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod(CONFIG_PATH, 0600);
}

function normalizeKeys(string $raw): array {
    $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];
    $keys = [];
    foreach ($parts as $part) {
        $key = trim($part);
        if (strlen($key) >= 8) {
            $keys[] = $key;
        }
    }
    return array_values(array_unique($keys));
}

function hasMistralKey(): bool {
    return count(loadConfig()['mistral_keys'] ?? []) > 0;
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
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; LibreSearch/1.0; +https://web-4.art)',
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
        CURLOPT_USERAGENT      => 'LibreSearch/1.0 PHP-Agent',
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
    $keys = loadConfig()['mistral_keys'] ?? [];
    if (!$keys) {
        return '';
    }
    return $keys[array_rand($keys)];
}

function callMistral(array $messages, string $model = MISTRAL_MODEL, int $maxTokens = 1200): ?string {
    $apiKey = getMistralKey();
    if ($apiKey === '') {
        logError('Configuration: clé API Mistral manquante');
        return null;
    }

    $payload = [
        'model'       => $model,
        'max_tokens'  => $maxTokens,
        'temperature' => 0.65,
        'messages'    => $messages
    ];
    $headers = ['Authorization: Bearer ' . $apiKey];
    $res     = curlPost(MISTRAL_ENDPOINT, $payload, $headers, 28);

    if ($res['error']) {
        logError('cURL Mistral: ' . $res['error']);
        return null;
    }
    $data = json_decode($res['body'], true);
    return $data['choices'][0]['message']['content'] ?? null;
}

// ─── HTML → clean text ────────────────────────────────────────────────────────
function htmlToText(string $html, int $maxLength = 6000): string {
    // Remove scripts, styles, nav, footer noise
    $html = preg_replace('/<(script|style|nav|footer|header|aside)[^>]*>.*?<\/\1>/si', '', $html);
    $html = preg_replace('/<[^>]+>/', ' ', $html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s{2,}/', ' ', $html);
    return trim(mb_substr($text, 0, $maxLength, 'UTF-8'));
}

function normalizeUrl(string $baseUrl, string $href): ?string {
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript):/i', $href)) {
        return null;
    }
    $base = parse_url($baseUrl);
    if (!$base || empty($base['scheme']) || empty($base['host'])) {
        return null;
    }
    if (str_starts_with($href, '//')) {
        $href = $base['scheme'] . ':' . $href;
    } elseif (str_starts_with($href, '/')) {
        $href = $base['scheme'] . '://' . $base['host'] . $href;
    } elseif (!preg_match('/^https?:\/\//i', $href)) {
        $path = $base['path'] ?? '/';
        $dir = preg_replace('/\/[^\/]*$/', '/', $path);
        $href = $base['scheme'] . '://' . $base['host'] . $dir . $href;
    }

    $parts = parse_url($href);
    if (!$parts || empty($parts['scheme']) || empty($parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return null;
    }
    if (strtolower($parts['host']) !== strtolower($base['host'])) {
        return null;
    }

    $path = $parts['path'] ?? '/';
    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }
    $normalizedPath = '/' . implode('/', $segments);
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    return $parts['scheme'] . '://' . $parts['host'] . $normalizedPath . $query;
}

function extractInternalLinks(string $html, string $baseUrl, int $limit = 4): array {
    preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\']/i', $html, $matches);
    $links = [];
    foreach ($matches[1] ?? [] as $href) {
        $url = normalizeUrl($baseUrl, $href);
        if ($url && $url !== normalizeUrl($baseUrl, $baseUrl)) {
            $links[$url] = true;
        }
        if (count($links) >= $limit) {
            break;
        }
    }
    return array_keys($links);
}

function fetchPages(string $url, bool $multiPage): array {
    $first = curlGet($url, [], 18);
    if ($first['error'] || $first['status'] < 200 || $first['status'] >= 400) {
        return ['ok' => false, 'error' => 'Impossible d\'accéder à cette URL (HTTP ' . $first['status'] . ')'];
    }

    $queue = [$url];
    if ($multiPage) {
        $queue = array_merge($queue, extractInternalLinks($first['body'], $url, 4));
    }

    $pages = [];
    $seen = [];
    foreach ($queue as $pageUrl) {
        if (isset($seen[$pageUrl])) {
            continue;
        }
        $seen[$pageUrl] = true;
        $res = $pageUrl === $url ? $first : curlGet($pageUrl, [], 12);
        if ($res['error'] || $res['status'] < 200 || $res['status'] >= 400) {
            continue;
        }
        $text = htmlToText($res['body'], $multiPage ? 3000 : 6000);
        if (strlen($text) >= 80) {
            $pages[] = ['url' => $pageUrl, 'text' => $text];
        }
        if (count($pages) >= ($multiPage ? 5 : 1)) {
            break;
        }
    }

    if (!$pages) {
        return ['ok' => false, 'error' => 'Contenu trop court ou page vide'];
    }

    return ['ok' => true, 'pages' => $pages];
}

function buildContext(array $pages): string {
    $chunks = [];
    foreach ($pages as $i => $page) {
        $chunks[] = '[Source ' . ($i + 1) . '] ' . $page['url'] . "\n" . $page['text'];
    }
    return mb_substr(implode("\n\n---\n\n", $chunks), 0, 14000, 'UTF-8');
}

function keywordTokens(string $text): array {
    $text = mb_strtolower($text, 'UTF-8');
    preg_match_all('/[\p{L}\p{N}]{4,}/u', $text, $matches);
    $stop = ['avec','dans','pour','cette','quel','quelle','quels','quelles','plus','sont','etre','être','cela','page','contenu','analyse','peux','peut','faire','avoir','leurs','cette'];
    $tokens = [];
    foreach ($matches[0] ?? [] as $token) {
        if (!in_array($token, $stop, true)) {
            $tokens[$token] = true;
        }
    }
    return array_keys($tokens);
}

function buildCitations(array $pages, string $query, int $limit = 3): array {
    $tokens = keywordTokens($query);
    $scored = [];
    foreach ($pages as $page) {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $page['text']) ?: [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (mb_strlen($sentence, 'UTF-8') < 50) {
                continue;
            }
            $lower = mb_strtolower($sentence, 'UTF-8');
            $score = 0;
            foreach ($tokens as $token) {
                if (str_contains($lower, $token)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scored[] = ['score' => $score, 'url' => $page['url'], 'excerpt' => mb_substr($sentence, 0, 260, 'UTF-8')];
            }
        }
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    $citations = [];
    $seen = [];
    foreach ($scored as $item) {
        $key = $item['url'] . '|' . $item['excerpt'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        unset($item['score']);
        $citations[] = $item;
        if (count($citations) >= $limit) {
            break;
        }
    }
    return $citations;
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
    // ── ACTION: config_status ─────────────────────────────────────────────────
    if ($action === 'config_status') {
        echo json_encode(['ok' => true, 'configured' => hasMistralKey()]);
        exit;
    }

    // ── ACTION: save_config ───────────────────────────────────────────────────
    if ($action === 'save_config') {
        $keys = normalizeKeys((string)($input['mistral_key'] ?? ''));
        if (!$keys) {
            echo json_encode(['ok' => false, 'error' => 'Clé API Mistral invalide']);
            exit;
        }

        $config = loadConfig();
        $config['mistral_keys'] = $keys;
        $config['updated_at'] = date(DATE_ATOM);
        saveConfig($config);

        echo json_encode(['ok' => true, 'configured' => true]);
        exit;
    }

    $db = getDB();

    // ── ACTION: history ────────────────────────────────────────────────────────
    if ($action === 'history') {
        $stmt = $db->query("SELECT session_key, url, summary, crawl_mode, page_count, created_at, updated_at
            FROM sessions
            ORDER BY updated_at DESC
            LIMIT 20");
        echo json_encode(['ok' => true, 'items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ── ACTION: get_session ────────────────────────────────────────────────────
    if ($action === 'get_session') {
        $sessionKey = trim($input['session'] ?? '');
        if (!$sessionKey) { echo json_encode(['ok' => false, 'error' => 'Session manquante']); exit; }

        $stmt = $db->prepare("SELECT * FROM sessions WHERE session_key = ? LIMIT 1");
        $stmt->execute([$sessionKey]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) { echo json_encode(['ok' => false, 'error' => 'Session introuvable']); exit; }

        $conversation = json_decode($session['conversation'] ?? '[]', true) ?: [];
        $messages = [];
        foreach ($conversation as $message) {
            if (($message['role'] ?? '') !== 'system') {
                $messages[] = $message;
            }
        }
        if ($messages && ($messages[0]['role'] ?? '') === 'assistant' && ($messages[0]['content'] ?? '') === ($session['summary'] ?? '')) {
            array_shift($messages);
        }

        $sources = json_decode($session['sources'] ?? '[]', true) ?: [];
        $citations = buildCitations($sources, ($session['summary'] ?? '') . ' ' . implode(' ', json_decode($session['topics'] ?? '[]', true) ?: []), 3);
        echo json_encode([
            'ok'         => true,
            'session'    => $session['session_key'],
            'url'        => $session['url'],
            'summary'    => $session['summary'] ?: '',
            'topics'     => json_decode($session['topics'] ?? '[]', true) ?: [],
            'questions'  => json_decode($session['questions'] ?? '[]', true) ?: [],
            'sources'    => array_map(fn($page) => ['url' => $page['url'] ?? ''], $sources),
            'citations'  => $citations,
            'messages'   => $messages,
            'crawl_mode' => $session['crawl_mode'] ?? 'single',
            'page_count' => (int)($session['page_count'] ?? 1),
            'created_at' => $session['created_at'],
        ]);
        exit;
    }

    // ── ACTION: scrape ─────────────────────────────────────────────────────────
    if ($action === 'scrape') {
        $url = filter_var(trim($input['url'] ?? ''), FILTER_VALIDATE_URL);
        if (!$url) { echo json_encode(['ok' => false, 'error' => 'URL invalide']); exit; }
        if (!hasMistralKey()) { echo json_encode(['ok' => false, 'error' => 'Clé API Mistral manquante. Ouvrez CONFIG pour l’ajouter.']); exit; }
        $multiPage = !empty($input['multiPage']);

        $fetched = fetchPages($url, $multiPage);
        if (!$fetched['ok']) { echo json_encode($fetched); exit; }
        $pages = $fetched['pages'];
        $text = buildContext($pages);

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

        $modeLabel = $multiPage ? 'plusieurs pages internes du même site' : 'une page';
        $userMsg = "Voici le contenu scrapé de $modeLabel depuis : " . $url . "\n\n---\n" . $text . "\n---\n\nAnalyse et génère 5 questions pertinentes.";

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
        $summary = $parsed['summary'] ?? 'Contenu analysé.';
        $topics = $parsed['topics'] ?? [];
        $questions = $parsed['questions'] ?? [];
        $citations = buildCitations($pages, $summary . ' ' . implode(' ', $topics), 3);
        $conversation = json_encode([
            ['role' => 'system', 'content' => "Tu es un assistant d'analyse web. Le contexte est la page : $url\n\nContenu:\n$text"],
            ['role' => 'assistant', 'content' => $summary]
        ]);

        $stmt = $db->prepare("INSERT INTO sessions (session_key, url, scraped_content, summary, topics, questions, sources, crawl_mode, page_count, conversation) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $sessionKey,
            $url,
            $text,
            $summary,
            json_encode($topics),
            json_encode($questions),
            json_encode($pages),
            $multiPage ? 'multi' : 'single',
            count($pages),
            $conversation
        ]);

        echo json_encode([
            'ok'         => true,
            'session'    => $sessionKey,
            'url'        => $url,
            'summary'    => $summary,
            'topics'     => $topics,
            'questions'  => $questions,
            'sources'    => array_map(fn($page) => ['url' => $page['url']], $pages),
            'citations'  => $citations,
            'crawl_mode' => $multiPage ? 'multi' : 'single',
            'page_count' => count($pages),
        ]);
        exit;
    }

    // ── ACTION: ask ────────────────────────────────────────────────────────────
    if ($action === 'ask') {
        $sessionKey = trim($input['session'] ?? '');
        $question   = trim($input['question'] ?? '');
        if (!hasMistralKey()) { echo json_encode(['ok' => false, 'error' => 'Clé API Mistral manquante. Ouvrez CONFIG pour l’ajouter.']); exit; }

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

        $sources = json_decode($session['sources'] ?? '[]', true) ?: [];
        $citations = buildCitations($sources, $question . ' ' . $answerText, 3);

        echo json_encode([
            'ok'        => true,
            'answer'    => $answerText,
            'questions' => array_slice($followUps, 0, 4),
            'citations' => $citations,
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Action inconnue']);

} catch (Throwable $e) {
    logError($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur interne']);
}
