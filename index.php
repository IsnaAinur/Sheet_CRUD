<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$appConfig = [];
$configPath = __DIR__ . '/config.php';
if (file_exists($configPath)) {
    $loadedConfig = require $configPath;
    if (is_array($loadedConfig)) {
        $appConfig = $loadedConfig;
    }
}

// Konstanta API
const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const GOOGLE_SHEETS_API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

define('GOOGLE_SHEET_ID', (string) ($appConfig['google_sheet_id'] ?? ''));
define('GOOGLE_SHEET_NAME', (string) ($appConfig['google_sheet_name'] ?? 'Sheet1'));
define('GOOGLE_SERVICE_ACCOUNT_FILE', (string) ($appConfig['google_service_account_file'] ?? (__DIR__ . '/google-service-account.json')));

// Helper HTML Escape
function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Service Account
function getGoogleServiceAccount(): array
{
    if (!file_exists(GOOGLE_SERVICE_ACCOUNT_FILE)) {
        throw new RuntimeException('File service account Google belum ditemukan.');
    }

    $decoded = json_decode((string) file_get_contents(GOOGLE_SERVICE_ACCOUNT_FILE), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Isi file service account Google tidak valid.');
    }

    foreach (['client_email', 'private_key'] as $requiredKey) {
        if (empty($decoded[$requiredKey])) {
            throw new RuntimeException('Field ' . $requiredKey . ' belum ada di file service account.');
        }
    }

    return $decoded;
}

// base64 URL
function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

// ambil access token
function getGoogleAccessToken(): string
{
    static $cachedToken = null;
    static $expiresAt = 0;

    if ($cachedToken !== null && time() < $expiresAt - 60) {
        return $cachedToken;
    }

    $serviceAccount = getGoogleServiceAccount();
    $issuedAt = time();

    $jwtHeader = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $jwtClaimSet = base64UrlEncode(json_encode([
        'iss' => $serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/spreadsheets',
        'aud' => GOOGLE_TOKEN_URL,
        'exp' => $issuedAt + 3600,
        'iat' => $issuedAt,
    ]));

    $unsignedJwt = $jwtHeader . '.' . $jwtClaimSet;

    $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
    $signature = '';
    openssl_sign($unsignedJwt, $signature, $privateKey, 'sha256');

    $assertion = $unsignedJwt . '.' . base64UrlEncode($signature);

    $payload = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $assertion,
    ]);

    $ch = curl_init(GOOGLE_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $response, true);
    if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded) || empty($decoded['access_token'])) {
        throw new RuntimeException('Google OAuth gagal.');
    }

    $cachedToken = (string) $decoded['access_token'];
    $expiresAt = $issuedAt + (int) ($decoded['expires_in'] ?? 3600);

    return $cachedToken;
}

// Fungsi Request ke Google Sheets API
function googleApiRequest(string $method, string $url, ?array $payload = null): array
{
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . getGoogleAccessToken(),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $response, true);

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException('Google Sheets API error (' . $statusCode . '): ' . $response);
    }

    return is_array($decoded) ? $decoded : [];
}

// Helper URL Range
function getSheetRange(string $range): string
{
    return GOOGLE_SHEETS_API_BASE . '/' . rawurlencode(GOOGLE_SHEET_ID) . '/values/' . rawurlencode($range);
}

// Header Spreadsheet
function ensureSheetHeader(): void
{
    $range = GOOGLE_SHEET_NAME . '!A1:C1';
    $result = googleApiRequest('GET', getSheetRange($range));
    $values = $result['values'][0] ?? [];

    if ($values === ['name', 'email', 'status']) {
        return;
    }

    googleApiRequest('PUT', getSheetRange($range) . '?valueInputOption=RAW', [
        'range' => $range,
        'majorDimension' => 'ROWS',
        'values' => [['name', 'email', 'status']],
    ]);
}

// Fungsi Read
function fetchSheetRows(): array
{
    ensureSheetHeader();
    $range = GOOGLE_SHEET_NAME . '!A2:C';
    $result = googleApiRequest('GET', getSheetRange($range));
    $values = $result['values'] ?? [];
    $rows = [];

    foreach ($values as $index => $row) {
        $rows[] = [
            'id' => (string) ($index + 2),
            'name' => (string) ($row[0] ?? ''),
            'email' => (string) ($row[1] ?? ''),
            'status' => (string) ($row[2] ?? 'active'),
        ];
    }

    return $rows;
}

// Fungsi Create
function appendSheetRow(array $payload): void
{
    ensureSheetHeader();
    $range = GOOGLE_SHEET_NAME . '!A:C';

    googleApiRequest(
        'POST',
        getSheetRange($range) . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS',
        [
            'values' => [[
                $payload['name'] ?? '',
                $payload['email'] ?? '',
                $payload['status'] ?? 'active',
            ]]
        ]
    );
}

// Fungsi Update
function updateSheetRow(string $rowId, array $payload): void
{
    $rowNumber = (int) $rowId;
    if ($rowNumber < 2) {
        throw new RuntimeException('ID baris tidak valid untuk update.');
    }

    $range = GOOGLE_SHEET_NAME . '!A' . $rowNumber . ':C' . $rowNumber;

    googleApiRequest('PUT', getSheetRange($range) . '?valueInputOption=RAW', [
        'range' => $range,
        'majorDimension' => 'ROWS',
        'values' => [[
            $payload['name'] ?? '',
            $payload['email'] ?? '',
            $payload['status'] ?? 'active',
        ]],
    ]);
}

// Fungsi Delete
function deleteSheetRow(string $rowId): void
{
    $rowNumber = (int) $rowId;
    if ($rowNumber < 2) {
        throw new RuntimeException('ID baris tidak valid untuk delete.');
    }

    $sheetMeta = googleApiRequest('GET', GOOGLE_SHEETS_API_BASE . '/' . rawurlencode(GOOGLE_SHEET_ID));
    $sheetId = null;

    foreach (($sheetMeta['sheets'] ?? []) as $sheet) {
        if (($sheet['properties']['title'] ?? '') === GOOGLE_SHEET_NAME) {
            $sheetId = $sheet['properties']['sheetId'] ?? null;
            break;
        }
    }

    if ($sheetId === null) {
        throw new RuntimeException('Sheet tidak ditemukan.');
    }

    googleApiRequest('POST', GOOGLE_SHEETS_API_BASE . '/' . rawurlencode(GOOGLE_SHEET_ID) . ':batchUpdate', [
        'requests' => [[
            'deleteDimension' => [
                'range' => [
                    'sheetId' => (int) $sheetId,
                    'dimension' => 'ROWS',
                    'startIndex' => $rowNumber - 1,
                    'endIndex' => $rowNumber,
                ],
            ],
        ]],
    ]);
}

// Fungsi Status Label dan Filter
function getStatusLabel(array $row): string 
{
    return (string) ($row['status'] ?? 'active');
}

function filterSheetRows(array $rows, string $search, string $status): array 
{ 
    return array_values(array_filter($rows, static function (array $row) use ($search, $status): bool { 
        $matchesSearch = true; 
        $matchesStatus = true; 
        
        if ($search !== '') { 
            $haystack = strtolower( 
                trim( 
                    (string) ($row['name'] ?? '') . ' ' . 
                    (string) ($row['email'] ?? '') . ' ' . 
                    (string) ($row['status'] ?? '') 
                ) 
            ); 
            $matchesSearch = str_contains($haystack, strtolower($search)); 
        } 

        if ($status !== '' && $status !== 'all') { 
            $matchesStatus = strtolower(getStatusLabel($row)) === strtolower($status); 
        } 

        return $matchesSearch && $matchesStatus; 
    })); 
} 

// Fungsi Sorting tepat di bawah filterSheetRows()
function sortSheetRows(array $rows, string $sortBy, string $sortDirection): array
{
    usort($rows, static function (array $left, array $right) use ($sortBy, $sortDirection): int {
        $leftValue = match ($sortBy) {
            'id' => (int) ($left['id'] ?? 0),
            'email' => strtolower((string) ($left['email'] ?? '')),
            'status' => strtolower((string) ($left['status'] ?? '')),
            default => strtolower((string) ($left['name'] ?? '')),
        };

        $rightValue = match ($sortBy) {
            'id' => (int) ($right['id'] ?? 0),
            'email' => strtolower((string) ($right['email'] ?? '')),
            'status' => strtolower((string) ($right['status'] ?? '')),
            default => strtolower((string) ($right['name'] ?? '')),
        };

        $result = $leftValue <=> $rightValue;

        return $sortDirection === 'desc' ? -$result : $result;
    });

    return $rows;
}

function exportRowsAsCsv(array $rows): never 
{ 
    header('Content-Type: text/csv; charset=UTF-8'); 
    header('Content-Disposition: attachment; filename="google-sheets-data.csv"'); 
    $output = fopen('php://output', 'wb'); 
    if ($output === false) { 
        exit; 
    } 
    fwrite($output, "\xEF\xBB\xBF"); 
    fputcsv($output, ['id', 'name', 'email', 'status']); 
    foreach ($rows as $row) { 
        fputcsv($output, [ 
            (string) ($row['id'] ?? ''), 
            (string) ($row['name'] ?? ''), 
            (string) ($row['email'] ?? ''), 
            (string) ($row['status'] ?? ''), 
        ]); 
    } 
    fclose($output); 
    exit;
}

// Variabel Awal untuk Interface
$flash = null;
$flashClass = 'info';
$errorCatch = null;

$filteredItems = []; 
$searchQuery = trim((string) ($_GET['search'] ?? '')); 
$statusFilter = trim((string) ($_GET['status'] ?? 'all')); 
$allowedStatuses = ['all', 'active', 'inactive', 'pending']; 
if (!in_array($statusFilter, $allowedStatuses, true)) { 
    $statusFilter = 'all'; 
}

// Variabel Sorting dan Validasi Input Sorting
$sortBy = trim((string) ($_GET['sort_by'] ?? 'name'));
$sortDirection = trim((string) ($_GET['sort_dir'] ?? 'asc'));
$allowedSortFields = ['id', 'name', 'email', 'status'];
$allowedSortDirections = ['asc', 'desc'];

if (!in_array($sortBy, $allowedSortFields, true)) {
    $sortBy = 'name';
}

if (!in_array($sortDirection, $allowedSortDirections, true)) {
    $sortDirection = 'asc';
}

try {
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($requestMethod === 'POST') {
        $formAction = $_POST['form_action'] ?? '';
        $rowId = trim((string) ($_POST['row_id'] ?? ''));
        $payload = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'status' => trim((string) ($_POST['status'] ?? 'active')),
        ];

        if ($formAction === 'create') {
            appendSheetRow($payload);
            $flashClass = 'success';
            $flash = 'Baris baru berhasil ditambahkan ke Google Sheets.';
        }

        if ($formAction === 'update' && $rowId !== '') {
            updateSheetRow($rowId, $payload);
            $flashClass = 'success';
            $flash = 'Baris berhasil diperbarui.';
        }

        if ($formAction === 'delete' && $rowId !== '') {
            deleteSheetRow($rowId);
            $flashClass = 'warning';
            $flash = 'Baris berhasil dihapus.';
        }
    }
} catch (Exception $e) {
    $flashClass = 'error';
    $flash = $e->getMessage();
}

try {
    $items = fetchSheetRows(); 
    $filteredItems = filterSheetRows($items, $searchQuery, $statusFilter); 
    
    // Sorting Setelah Filter
    $filteredItems = sortSheetRows($filteredItems, $sortBy, $sortDirection);

    if (isset($_GET['export']) && $_GET['export'] === 'csv') { 
        exportRowsAsCsv($filteredItems); 
    } 
} catch (Throwable $exception) {
    $flashClass = 'error';
    $flash = $exception->getMessage();
    $errorCatch = $exception->getMessage();
    $items = [];
    $filteredItems = []; 
}

// Logika interface tampilan
$formMode = 'create';
$selectedItem = ['id' => '', 'name' => '', 'email' => '', 'status' => 'active'];

if (isset($_GET['edit']) && $_GET['edit'] !== '') {
    $editId = $_GET['edit'];
    foreach ($items as $item) {
        if ($item['id'] === $editId) {
            $selectedItem = $item;
            $formMode = 'update';
            break;
        }
    }
}

// Perhitungan statistik
$totalItems = count($filteredItems); 
$allItemsCount = count($items); 
$activeItems = count(array_filter($filteredItems, static fn (array $item): bool => 
    strtolower(getStatusLabel($item)) === 'active')); 
$statusGroups = count(array_unique(array_map(static fn (array $item): string => 
    strtolower(getStatusLabel($item)), $filteredItems))); 
$heroItem = $detailItem ?? ($items[0] ?? null); 
$performers = array_slice($filteredItems, 0, 3); 
$renderStamp = date('Y-m-d H:i:s'); 

// Ubah URL Export CSV agar mendukung parameter sorting
$exportUrl = 'index.php?export=csv&search=' . urlencode($searchQuery) . '&status=' . 
    urlencode($statusFilter) . '&sort_by=' . urlencode($sortBy) . '&sort_dir=' . urlencode($sortDirection); 

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard CRUD Google Sheets</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <div class="shell">
        
        <aside class="sidebar">
            <div class="brand">
                <div class="brand-mark">
                    <span></span><span></span><span></span>
                    <span></span><span></span><span></span>
                </div>
                <h2 class="brand-name">Sheets</h2>
                <p class="brand-sub">Web Service</p>
            </div>

            <nav class="side-nav">
                <a href="index.php" class="side-link active">
                    <div class="side-icon">DB</div>
                    Main Dashboard
                </a>
                <a href="https://docs.google.com/spreadsheets/d/<?= h(GOOGLE_SHEET_ID) ?>" target="_blank" class="side-link">
                    <div class="side-icon">GS</div>
                    Spreadsheet
                </a>
            </nav>

            <div class="profile-chip">
                <div class="avatar">Dev</div>
                <strong>Isna Ainur</strong>
                <p>Full-Stack Developer</p>
            </div>
        </aside>

        <main class="main-panel">
            
            <header class="topbar">
                <div class="topbar-left">
                    <?php if ($formMode === 'update'): ?>
                        <a href="index.php" class="back-link">Back</a>
                    <?php endif; ?>
                    <nav class="top-menu">
                        <a href="index.php" class="current">Overview</a>
                        <a href="#">Analytics</a>
                        <a href="#">Settings</a>
                    </nav>
                </div>
                <div class="members">
                    <div class="member-stack">
                        <span>U1</span>
                        <span>U2</span>
                        <span>U3</span>
                    </div>
                    <strong>Active Session</strong>
                </div>
            </header>

            <?php if ($flash !== null): ?>
                <div class="flash <?= h($flashClass) ?>">
                    <strong>Sistem Informasi:</strong> <?= h($flash) ?>
                </div>
            <?php endif; ?>

            <?php if ($errorCatch !== null): ?>
                <div class="flash error">
                    <strong>Gagal sinkronisasi API:</strong> <?= h($errorCatch) ?>
                </div>
            <?php endif; ?>

            <div class="dashboard">
                
                <section class="hero-panel">
                    <div class="hero-card">
                        <div class="hero-copy">
                            <p class="eyebrow">Google Sheets Web Service</p>
                            <h1>Integration</h1>
                            
                            <div class="metric-list">
                                <div class="metric-item">
                                    <div class="metric-icon">Σ</div>
                                    <div>
                                        <small>Total Filtered Rows</small>
                                        <strong><?= h((string) $totalItems) ?></strong>
                                    </div>
                                </div>
                            </div>
                            
                            <a href="https://docs.google.com/spreadsheets/d/<?= h(GOOGLE_SHEET_ID) ?>" target="_blank" class="cta-link">Open Sheet</a>
                        </div>
                        
                        <div class="hero-illustration">
                            <div class="orbit orbit-a"></div>
                            <div class="orbit orbit-b"></div>
                            <div class="desk"></div>
                            <div class="figure">
                                <div class="figure-head"></div>
                                <div class="figure-body"></div>
                                <div class="figure-leg left"></div>
                                <div class="figure-leg right"></div>
                            </div>
                        </div>
                    </div>

                    <div class="rate-card">
                        <p class="card-kicker">Spreadsheet Info</p>
                        <div class="rate-score rate-score-sheet">
                            <strong><?= h((string) $activeItems) ?></strong>
                            <span>Active (Filtered)</span>
                        </div>
                        <div class="sheet-meta">
                            <p><strong>Target Name:</strong> <span><?= h(GOOGLE_SHEET_NAME) ?></span></p>
                            <p><strong>Total Unfiltered:</strong> <span><?= h((string) $allItemsCount) ?> rows</span></p>
                        </div>
                        <div class="gauge">
                            <div class="gauge-ring"></div>
                            <div class="gauge-needle"></div>
                        </div>
                        <div class="tip-box">
                            <div class="tip-icon">i</div>
                            <p>Data tersimpan langsung tanpa menggunakan database SQL konvensional.</p>
                        </div>
                    </div>
                </section>

                <section class="summary-grid">
                    <div class="dashboard-card">
                        <div class="card-heading">
                            <h3>Filtered Rows</h3>
                            <div class="soft-badge">R</div>
                        </div>
                        <div class="money-line">
                            <strong><?= h((string) $totalItems) ?></strong>
                            <span>Dari total <?= h((string) $allItemsCount) ?></span>
                        </div>
                    </div>
                    <div class="dashboard-card">
                        <div class="card-heading">
                            <h3>Active in Filter</h3>
                            <div class="soft-badge">A</div>
                        </div>
                        <div class="money-line">
                            <strong><?= h((string) $activeItems) ?></strong>
                            <span>Pengguna aktif</span>
                        </div>
                    </div>
                    
                    <div class="dashboard-card">
                        <div class="card-heading">
                            <h3>Total all rows</h3>
                            <div class="soft-badge">T</div>
                        </div>
                        <div class="money-line">
                            <strong><?= h((string) $allItemsCount) ?></strong>
                            <span>Total semua data</span>
                        </div>
                    </div>
                </section>

                <div class="content-grid">
                    
                    <section class="dashboard-card">
                        <div class="section-head">
                            <h2><?= $formMode === 'update' ? 'Modifikasi Baris #' . h((string) $selectedItem['id']) : 'Input Data Spreadsheet' ?></h2>
                        </div>
                        
                        <form method="post" class="form">
                            <input type="hidden" name="form_action" value="<?= h($formMode) ?>">
                            <input type="hidden" name="row_id" value="<?= h((string) ($selectedItem['id'] ?? '')) ?>">

                            <div>
                                <label for="name">Name</label>
                                <input type="text" id="name" name="name" value="<?= h($selectedItem['name'] ?? '') ?>" placeholder="Ketik nama lengkap..." required>
                            </div>

                            <div>
                                <label for="email">Email</label>
                                <input type="email" id="email" name="email" value="<?= h($selectedItem['email'] ?? '') ?>" placeholder="alamat@domain.com" required>
                            </div>

                            <div>
                                <label for="status">Status Verifikasi</label>
                                <select id="status" name="status" required>
                                    <option value="active" <?= ($selectedItem['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= ($selectedItem['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    <option value="pending" <?= ($selectedItem['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending</option>
                                </select>
                            </div>

                            <div class="actions" style="margin-top: 10px;">
                                <button type="submit" class="primary">Simpan Record</button>
                                <?php if ($formMode === 'update'): ?>
                                    <a href="index.php" class="ghost">Batal</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </section>

                    <section class="dashboard-card table-card">
                        <div class="table-head" style="display: flex; flex-direction: column; gap: 12px; align-items: stretch;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <h2>Database Records</h2>
                                <div class="table-actions" style="display: flex; gap: 8px;">
                                    <a href="<?= h($exportUrl) ?>" class="link-btn primary" style="padding: 8px 14px; font-size: 0.85rem; border-radius: 6px; text-decoration: none;">Export CSV</a>
                                    <a href="index.php" class="link-btn ghost" style="padding: 8px 14px; font-size: 0.85rem; border-radius: 6px; text-decoration: none; border: 1px solid var(--border);">Reset Filter</a>
                                </div>
                            </div>
                            
                            <form method="get" class="form filter-grid" style="background: rgba(0,0,0,0.02); padding: 12px; border-radius: 8px; border: 1px solid var(--border);">
                                <div style="min-width: 200px;">
                                    <label>Cari data</label>
                                    <input type="text" name="search" value="<?= h($searchQuery) ?>" placeholder="Kata kunci...">
                                </div>

                                <div style="min-width: 150px;">
                                    <label>Filter status</label>
                                    <select name="status">
                                        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Semua Status</option>
                                        <?php 
                                        $statuses = ['active', 'inactive', 'pending'];
                                        foreach ($statuses as $stat): 
                                        ?>
                                            <option value="<?= h($stat) ?>" <?= $statusFilter === $stat ? 'selected' : '' ?>>
                                                <?= h(ucfirst($stat)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="sort_by">Urutkan berdasarkan</label>
                                    <select id="sort_by" name="sort_by">
                                        <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Name</option>
                                        <option value="email" <?= $sortBy === 'email' ? 'selected' : '' ?>>Email</option>
                                        <option value="status" <?= $sortBy === 'status' ? 'selected' : '' ?>>Status</option>
                                        <option value="id" <?= $sortBy === 'id' ? 'selected' : '' ?>>ID</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="sort_dir">Arah urutan</label>
                                    <select id="sort_dir" name="sort_dir">
                                        <option value="asc" <?= $sortDirection === 'asc' ? 'selected' : '' ?>>A-Z / Kecil-Besar</option>
                                        <option value="desc" <?= $sortDirection === 'desc' ? 'selected' : '' ?>>Z-A / Besar-Kecil</option>
                                    </select>
                                </div>

                                <div style="display: flex;">
                                    <button type="submit" class="primary" style="width: 100%;">Terapkan</button>
                                </div>
                            </form>
                        </div>
                        
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID Baris</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Status</th>
                                        <th>Aksi Terintegrasi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($filteredItems)): ?>
                                        <tr>
                                            <td colspan="5" style="text-align: center; color: var(--muted); padding: 30px 0;">Tidak ada baris data atau spreadsheet kosong.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($filteredItems as $item): ?>
                                            <tr>
                                                <td><strong>#<?= h((string) $item['id']) ?></strong></td>
                                                <td><?= h($item['name']) ?></td>
                                                <td><span style="font-size:0.9rem; color:var(--muted);"><?= h($item['email']) ?></span></td>
                                                <td>
                                                    <span class="status-pill">
                                                        <?= h($item['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="action-cell">
                                                        <a href="index.php?edit=<?= h((string) $item['id']) ?>" class="link-btn" style="padding: 6px 12px; font-size: 0.82rem;">Edit</a>
                                                        
                                                        <form method="post" action="index.php" onsubmit="return confirm('Hapus data baris ke-<?= h((string) $item['id']) ?> dari cloud spreadsheet?');">
                                                            <input type="hidden" name="form_action" value="delete">
                                                            <input type="hidden" name="row_id" value="<?= h((string) $item['id']) ?>">
                                                            <button type="submit" class="danger" style="padding: 6px 12px; font-size: 0.82rem;">Delete</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>

                </div>
            </div>

        </main>
    </div>

</body>
</html>