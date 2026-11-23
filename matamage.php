<?php
/**
 * matamage.php – Mat-å-Mage
 * -----------------------------------------------------------------------
 * Logga handlingar (t.ex. "Oboj") och konsekvenser (t.ex. "Gasig") med ett
 * knapptryck, och hitta samband mellan dem.
 *
 * Vyer:
 *   matamage.php            – logga (knappar + logg)
 *   matamage.php?analys=1   – vad händer efter en handling / före en konsekvens
 *   matamage.php?knappar=1  – lägg till, ändra, sortera och arkivera knappar
 *   matamage.php?export=1   – export för AI eller som CSV
 *
 * Analysen räknar TILLFÄLLEN (loggar av samma knapp inom "sammanslagning"
 * timmar räknas som ett) och jämför med hur ofta det andra brukar hända
 * vid samma tid på dygnet andra dagar (tar hänsyn till dygnsrytm). Ett
 * binomialtest markerar det som sannolikt inte är slump.
 *
 * Tabeller: se matamage.sql
 * -----------------------------------------------------------------------
 */

require __DIR__ . '/common.php';

const MAM_PAGE    = 'matamage.php';
const MAM_FARGER  = ['' => 'Ingen', 'gron' => 'Grön', 'gul' => 'Gul', 'orange' => 'Orange', 'rod' => 'Röd',
                     'rosa' => 'Rosa', 'lila' => 'Lila', 'bla' => 'Blå', 'brun' => 'Brun', 'gra' => 'Grå'];
const MAM_STYRKA  = [1 => 'Lite', 2 => 'Mycket', 3 => 'Kraftigt'];
const MAM_DAGAR   = ['söndag', 'måndag', 'tisdag', 'onsdag', 'torsdag', 'fredag', 'lördag'];
const MAM_FONSTER = [24, 48, 72, 96, 120, 168];
const MAM_INTERVALL = [6, 12, 24];
const MAM_SAMMANSLAG = [0, 1, 2, 3, 4, 6, 8, 12];

// ============================================================
// APP-MANIFEST (ikon på hemskärmen) – kräver ingen inloggning
// ============================================================
if (isset($_GET['manifest'])) {
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode([
        'name'             => 'Mat-å-Mage',
        'short_name'       => 'Mat-å-Mage',
        'start_url'        => MAM_PAGE,
        'scope'            => './',
        'display'          => 'standalone',
        'background_color' => '#f4f4f2',
        'theme_color'      => '#f4f4f2',
        'icons'            => [
            ['src' => 'mam-ikon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => 'mam-ikon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// ============================================================
// HJÄLPFUNKTIONER
// ============================================================

function mamClean(string $s, int $max): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    $s = mb_substr($s, 0, $max);
    return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}

function mamSettings(int $uid): array {
    $s = dbOne('SELECT * FROM MamInstallningar WHERE UserID = ?', 'i', [$uid]);
    return [
        'namn_handling'   => $s['NamnHandling'] ?? 'Mat',
        'namn_konsekvens' => $s['NamnKonsekvens'] ?? 'Mage',
        'fonster'         => (int)($s['Fonster'] ?? 72),
        'intervall'       => (int)($s['Intervall'] ?? 12),
        'sammanslag'      => (int)($s['Sammanslag'] ?? 3),
        'dolj_handling'   => array_filter(array_map('intval', explode(',', $s['DoljHandling'] ?? ''))),
        'dolj_konsekvens' => array_filter(array_map('intval', explode(',', $s['DoljKonsekvens'] ?? ''))),
    ];
}

function mamEnsureSettings(int $uid): void {
    dbRun('INSERT IGNORE INTO MamInstallningar (UserID) VALUES (?)', 'i', [$uid]);
}

function mamButtons(int $uid, bool $archived = false): array {
    return dbAll(
        'SELECT k.*, (SELECT COUNT(*) FROM MamLogg l WHERE l.KnappID = k.ID) AS Antal
         FROM MamKnappar k WHERE k.UserID = ? AND k.Arkiverad = ?
         ORDER BY k.Typ, k.Ordning, k.Namn',
        'ii', [$uid, $archived ? 1 : 0]
    );
}

function mamButtonForClient(array $k): array {
    return ['id' => (int)$k['ID'], 'typ' => $k['Typ'], 'namn' => $k['Namn'], 'farg' => $k['Farg']];
}

function mamOwnButton(int $id, int $uid): ?array {
    return dbOne('SELECT * FROM MamKnappar WHERE ID = ? AND UserID = ?', 'ii', [$id, $uid]);
}

// Loggar från och med midnatt för $days dagar sedan (1 = i dag).
function mamRecent(int $uid, int $days): array {
    $from = date('Y-m-d 00:00:00', strtotime('-' . max(0, $days - 1) . ' days'));
    $rows = dbAll(
        'SELECT l.ID, l.KnappID, l.Tid, l.Styrka, l.Kommentar, k.Namn, k.Typ, k.Farg
         FROM MamLogg l JOIN MamKnappar k ON k.ID = l.KnappID
         WHERE l.UserID = ? AND l.Tid >= ? ORDER BY l.Tid DESC, l.ID DESC LIMIT 2000',
        'is', [$uid, $from]
    );
    return array_map(fn($r) => [
        'id'        => (int)$r['ID'],
        'knapp'     => (int)$r['KnappID'],
        'namn'      => $r['Namn'],
        'typ'       => $r['Typ'],
        'farg'      => $r['Farg'],
        'ms'        => strtotime($r['Tid']) * 1000,
        'styrka'    => $r['Styrka'] !== null ? (int)$r['Styrka'] : null,
        'kommentar' => $r['Kommentar'],
    ], $rows);
}

// Klientens tid (ms) → DATETIME. Framtid kapas till nu.
function mamMsToSql($ms): string {
    $ts = (int)floor(((float)$ms) / 1000);
    if ($ts <= 0 || $ts > time() + 300) {
        $ts = time();
    }
    if ($ts < time() - 10 * 365 * 86400) {
        $ts = time();
    }
    return date('Y-m-d H:i:s', $ts);
}

function mamStyrka($v, string $typ): ?int {
    if ($typ !== 'konsekvens' || $v === null || $v === '') {
        return null;
    }
    $v = (int)$v;
    return ($v >= 1 && $v <= 3) ? $v : null;
}

function mamHours(int $h): string {
    return $h % 24 === 0 && $h >= 48 ? ($h / 24) . ' dygn' : $h . ' h';
}

// ============================================================
// ANALYS
// ============================================================

// Loggar av samma knapp inom $gap sekunder från tillfällets start = ett tillfälle.
function mamOccurrences(array $times, int $gap): array {
    $occ = [];
    $start = null;
    foreach ($times as $t) {
        if ($start === null || $t - $start > $gap) {
            $occ[] = $t;
            $start = $t;
        }
    }
    return $occ;
}

// Finns någon tid i $xs (sorterad) i intervallet? $openLeft: (a, b] annars [a, b)
function mamAnyIn(array $xs, int $a, int $b, bool $openLeft): bool {
    $lo = 0;
    $hi = count($xs);
    while ($lo < $hi) {                         // första index där x >= a (eller > a)
        $mid = ($lo + $hi) >> 1;
        if ($openLeft ? $xs[$mid] <= $a : $xs[$mid] < $a) {
            $lo = $mid + 1;
        } else {
            $hi = $mid;
        }
    }
    if ($lo >= count($xs)) {
        return false;
    }
    return $openLeft ? $xs[$lo] <= $b : $xs[$lo] < $b;
}

// Andel av alla möjliga fönster på $len sekunder (inom perioden) som innehåller minst en tid i $xs.
function mamBaseline(array $xs, int $len, int $start, int $end): ?float {
    $lo = $start;
    $hi = $end - $len;
    if ($hi <= $lo) {
        return null;
    }
    $total = 0;
    $cs = $ce = null;
    foreach ($xs as $x) {
        $s = max($lo, $x - $len);
        $e = min($hi, $x);
        if ($e <= $s) {
            continue;
        }
        if ($ce === null || $s > $ce) {
            if ($ce !== null) {
                $total += $ce - $cs;
            }
            $cs = $s;
            $ce = $e;
        } else {
            $ce = max($ce, $e);
        }
    }
    if ($ce !== null) {
        $total += $ce - $cs;
    }
    return $total / ($hi - $lo);
}

// Binomialtest: [P(X >= k), P(X <= k)] för X ~ Bin(n, p)
function mamBinom(int $n, int $k, float $p): array {
    if ($n <= 0) {
        return [1.0, 1.0];
    }
    if ($p <= 0) {
        return [$k > 0 ? 0.0 : 1.0, 1.0];
    }
    if ($p >= 1) {
        return [1.0, $k < $n ? 0.0 : 1.0];
    }
    $lp = log($p);
    $lq = log(1 - $p);
    $logs = [$n * $lq];
    for ($i = 0; $i < $n; $i++) {
        $logs[] = $logs[$i] + log(($n - $i) / ($i + 1)) + $lp - $lq;
    }
    $max = max($logs);
    $all = $up = $low = 0.0;
    foreach ($logs as $i => $l) {
        $e = exp($l - $max);
        $all += $e;
        if ($i >= $k) {
            $up += $e;
        }
        if ($i <= $k) {
            $low += $e;
        }
    }
    return [min(1.0, $up / $all), min(1.0, $low / $all)];
}

/**
 * Förväntat antal träffar med hänsyn till dygnsrytm: för varje tillfälle
 * tittar vi på SAMMA tidsfönster (samma klockslag) andra dagar, upp till
 * MAM_SKIFT_DAGAR dagar före och efter, och räknar hur ofta det andra fanns där.
 * Saknas jämförelsedagar används den enkla normalnivån $pFlat.
 */
const MAM_SKIFT_DAGAR = 45;

function mamDailyP(int $t, array $xs, int $off, int $len, bool $after, int $dataStart, int $dataEnd, ?float $pFlat): ?float {
    $valid = $hit = 0;
    for ($d = -MAM_SKIFT_DAGAR; $d <= MAM_SKIFT_DAGAR; $d++) {
        if ($d === 0) {
            continue;
        }
        $ts = $t + $d * 86400;
        if ($after) {
            $a = $ts + $off;
            $b = $a + $len;
            if ($a < $dataStart || $b > $dataEnd) {
                continue;
            }
            $valid++;
            if (mamAnyIn($xs, $a, $b, false)) {
                $hit++;
            }
        } else {
            $b = $ts - $off;
            $a = $b - $len;
            if ($a < $dataStart || $b > $dataEnd) {
                continue;
            }
            $valid++;
            if (mamAnyIn($xs, $a, $b, true)) {
                $hit++;
            }
        }
    }
    return $valid >= 5 ? $hit / $valid : $pFlat;
}

function mamCell(array $occ, array $xs, int $off, int $len, bool $after, int $dataStart, int $dataEnd, ?float $pFlat): array {
    $n = $hits = 0;
    $expected = 0.0;
    $known = true;
    foreach ($occ as $t) {
        if ($after) {
            $a = $t + $off;
            $b = $a + $len;
            if ($b > $dataEnd) {
                continue;               // har inte hunnit hända än
            }
            $hit = mamAnyIn($xs, $a, $b, false);
        } else {
            $b = $t - $off;
            $a = $b - $len;
            if ($a < $dataStart) {
                continue;               // före första loggningen
            }
            $hit = mamAnyIn($xs, $a, $b, true);
        }
        $n++;
        if ($hit) {
            $hits++;
        }
        $p = mamDailyP($t, $xs, $off, $len, $after, $dataStart, $dataEnd, $pFlat);
        if ($p === null) {
            $known = false;
        } else {
            $expected += $p;
        }
    }
    if (!$known || $n === 0) {
        $expected = $n === 0 ? 0.0 : null;
    }
    $pMean = ($expected !== null && $n > 0) ? $expected / $n : null;
    [$up, $low] = $pMean !== null ? mamBinom($n, $hits, $pMean) : [1.0, 1.0];
    $mark = '';
    if ($n >= 3 && $expected !== null) {
        if ($hits > $expected && $up < 0.01) {
            $mark = 'hog2';
        } elseif ($hits > $expected && $up < 0.05) {
            $mark = 'hog';
        } elseif ($hits < $expected && $low < 0.05 && $expected >= 1.5) {
            $mark = 'lag';
        }
    }
    return ['n' => $n, 'hits' => $hits, 'expected' => $expected, 'p_up' => $up, 'p_low' => $low, 'mark' => $mark,
            'ratio' => ($expected ?? 0) > 0 ? $hits / $expected : null];
}

/**
 * $opts: fonster, intervall, sammanslag (timmar), fran, till (Y-m-d|''), dolj (ID-lista), minstyrka (0–3)
 */
function mamAnalyse(int $uid, array $sel, array $opts): array {
    $after = $sel['Typ'] === 'handling';
    $otherTyp = $after ? 'konsekvens' : 'handling';
    $W = $opts['fonster'] * 3600;
    $B = $opts['intervall'] * 3600;
    $nb = intdiv($W, $B);

    $now = time();
    $first = dbOne('SELECT MIN(Tid) AS M FROM MamLogg WHERE UserID = ?', 'i', [$uid]);
    if (empty($first['M'])) {
        return ['empty' => true];
    }
    $dataStart = strtotime($first['M']);
    if ($opts['fran'] !== '') {
        $dataStart = max($dataStart, strtotime($opts['fran'] . ' 00:00:00'));
    }
    $dataEnd = $opts['till'] !== '' ? min($now, strtotime($opts['till'] . ' 23:59:59')) : $now;

    $rows = dbAll(
        'SELECT l.KnappID, l.Tid, l.Styrka FROM MamLogg l WHERE l.UserID = ? AND l.Tid BETWEEN ? AND ? ORDER BY l.Tid',
        'iss', [$uid, date('Y-m-d H:i:s', $dataStart), date('Y-m-d H:i:s', $dataEnd)]
    );
    $buttons = [];
    foreach (dbAll('SELECT ID, Typ, Namn, Farg, Arkiverad FROM MamKnappar WHERE UserID = ?', 'i', [$uid]) as $k) {
        $buttons[(int)$k['ID']] = $k;
    }
    $times = [];
    foreach ($rows as $r) {
        $kid = (int)$r['KnappID'];
        if (!isset($buttons[$kid])) {
            continue;
        }
        if ($kid !== (int)$sel['ID'] && $buttons[$kid]['Typ'] === 'konsekvens' && $opts['minstyrka'] > 0
            && (int)$r['Styrka'] < $opts['minstyrka']) {
            continue;
        }
        $times[$kid][] = strtotime($r['Tid']);
    }

    $occ = mamOccurrences($times[(int)$sel['ID']] ?? [], $opts['sammanslag'] * 3600);
    $hidden = array_flip($opts['dolj']);
    $result = [];
    foreach ($buttons as $kid => $k) {
        if ($k['Typ'] !== $otherTyp || empty($times[$kid]) || isset($hidden[$kid])) {
            continue;
        }
        $xs = $times[$kid];
        $pB = mamBaseline($xs, $B, $dataStart, $dataEnd);
        $pW = mamBaseline($xs, $W, $dataStart, $dataEnd);
        $cells = [];
        $best = 1.0;
        for ($i = 0; $i < $nb; $i++) {
            $c = mamCell($occ, $xs, $i * $B, $B, $after, $dataStart, $dataEnd, $pB);
            $cells[] = $c;
            if ($c['hits'] > ($c['expected'] ?? 0)) {
                $best = min($best, $c['p_up']);
            }
        }
        $total = mamCell($occ, $xs, 0, $W, $after, $dataStart, $dataEnd, $pW);
        if ($total['hits'] > ($total['expected'] ?? 0)) {
            $best = min($best, $total['p_up']);
        }
        $result[] = ['id' => $kid, 'namn' => $k['Namn'], 'farg' => $k['Farg'], 'arkiverad' => (int)$k['Arkiverad'] === 1,
                     'cells' => $cells, 'total' => $total, 'best' => $best, 'antal' => count($xs)];
    }
    usort($result, fn($a, $b) => [$a['best'], -$a['total']['hits']] <=> [$b['best'], -$b['total']['hits']]);

    return [
        'empty'     => false,
        'after'     => $after,
        'occ'       => $occ,
        'rows'      => $result,
        'nb'        => $nb,
        'B'         => $opts['intervall'],
        'W'         => $opts['fonster'],
        'dataStart' => $dataStart,
        'dataEnd'   => $dataEnd,
        'loggar'    => count($times[(int)$sel['ID']] ?? []),
    ];
}

// ============================================================
// EXPORT
// ============================================================
function mamCsvField(string $v, string $sep): string {
    if (strpbrk($v, $sep . "\"\n\r") !== false) {
        return '"' . str_replace('"', '""', $v) . '"';
    }
    return $v;
}

function mamExportRows(int $uid, string $fran, string $till): array {
    $where = 'l.UserID = ?';
    $types = 'i';
    $params = [$uid];
    if ($fran !== '') { $where .= ' AND l.Tid >= ?'; $types .= 's'; $params[] = $fran . ' 00:00:00'; }
    if ($till !== '') { $where .= ' AND l.Tid <= ?'; $types .= 's'; $params[] = $till . ' 23:59:59'; }
    return dbAll(
        "SELECT l.ID, l.Tid, l.Styrka, l.Kommentar, k.Typ, k.Namn
         FROM MamLogg l JOIN MamKnappar k ON k.ID = l.KnappID WHERE $where ORDER BY l.Tid, l.ID",
        $types, $params
    );
}

function mamCsv(array $rows, array $set, string $sep): string {
    $head = ['id', 'tid', 'datum', 'klockslag', 'veckodag', 'typ', 'kategori', 'knapp', 'styrka', 'styrka_text', 'kommentar'];
    $out = implode($sep, $head) . "\n";
    foreach ($rows as $r) {
        $ts = strtotime($r['Tid']);
        $styrka = $r['Styrka'] !== null ? (int)$r['Styrka'] : null;
        $vals = [
            (string)$r['ID'], date('Y-m-d\TH:i', $ts), date('Y-m-d', $ts), date('H:i', $ts), MAM_DAGAR[(int)date('w', $ts)],
            $r['Typ'], $r['Typ'] === 'handling' ? $set['namn_handling'] : $set['namn_konsekvens'], $r['Namn'],
            $styrka !== null ? (string)$styrka : '', $styrka !== null ? mb_strtolower(MAM_STYRKA[$styrka]) : '', $r['Kommentar'],
        ];
        $out .= implode($sep, array_map(fn($v) => mamCsvField($v, $sep), $vals)) . "\n";
    }
    return $out;
}

function mamAiText(array $rows, array $set, int $uid, string $fran, string $till): string {
    $nh = $set['namn_handling'];
    $nk = $set['namn_konsekvens'];
    $counts = [];
    foreach ($rows as $r) {
        $counts[$r['Typ']][$r['Namn']] = ($counts[$r['Typ']][$r['Namn']] ?? 0) + 1;
    }
    $list = function ($typ) use ($counts) {
        $c = $counts[$typ] ?? [];
        arsort($c);
        return $c ? implode(', ', array_map(fn($n, $a) => "$n ($a)", array_keys($c), $c)) : '–';
    };
    $first = $rows ? date('Y-m-d', strtotime($rows[0]['Tid'])) : '–';
    $last = $rows ? date('Y-m-d', strtotime($rows[count($rows) - 1]['Tid'])) : '–';

    $t = "# Mat-å-Mage – loggdata för analys\n\n";
    $t .= "## Instruktioner till dig som AI\n\n";
    $t .= "Du får en personlig logg där en person med ett knapptryck har registrerat två sorters händelser:\n\n";
    $t .= "- **handling** (kategorin \"$nh\"): något personen gjort, ätit eller druckit.\n";
    $t .= "- **konsekvens** (kategorin \"$nk\"): hur det kändes eller vad som hände efteråt.\n\n";
    $t .= "Syftet är att hitta möjliga samband: vilka handlingar som ofta följs av vilka konsekvenser, och vilka som inte gör det.\n\n";
    $t .= "### Om datan\n";
    $t .= "- Varje rad är en loggning. Tiden är lokal tid (" . TIMEZONE . ") och kan vara inmatad i efterhand.\n";
    $t .= "- `styrka` finns bara för konsekvenser: 1 = lite, 2 = mycket, 3 = kraftigt. Tom = inte angiven.\n";
    $t .= "- Att något *inte* är loggat betyder oftast att det inte hände, men loggningen kan ha glömts.\n";
    $t .= "- Flera loggar av samma handling nära varandra (t.ex. två koppar kaffe på morgonen) bör räknas som ett tillfälle. Personen använder {$set['sammanslag']} timmar som gräns.\n\n";
    $t .= "### Föreslagen analys\n";
    $t .= "1. Gruppera varje handling i tillfällen enligt ovan.\n";
    $t .= "2. För varje par handling → konsekvens: räkna i hur stor andel av tillfällena konsekvensen inträffade 0–{$set['intervall']} h, {$set['intervall']}–" . (2 * $set['intervall']) . " h osv. upp till {$set['fonster']} h efter.\n";
    $t .= "3. Jämför med **basnivån**: hur ofta konsekvensen inträffar i samma tidsfönster (samma klockslag) andra dagar. Då tar du hänsyn till dygnsrytm – t.ex. att frukost alltid kommer före besvär som loggas på dagen. En konsekvens som händer nästan jämt säger lite om en enskild handling.\n";
    $t .= "4. Ta hänsyn till **samtidighet**: saker som ofta äts tillsammans eller samma tid på dygnet kan dela på \"skulden\". Försök skilja dem åt, t.ex. genom tillfällen där bara den ena förekom.\n";
    $t .= "5. Titta även på styrka, tid på dygnet och veckodag.\n";
    $t .= "6. Var tydlig med osäkerhet. Färre än cirka 5 tillfällen räcker sällan för slutsatser, och när många par testas uppstår några \"samband\" av ren slump.\n\n";
    $t .= "### Svara gärna med\n";
    $t .= "- En kort sammanfattning av de tydligaste sambanden, med siffror (t.ex. \"7 av 9 gånger, normalt 3 av 9\").\n";
    $t .= "- Vilka misstänkta samband som *inte* håller.\n";
    $t .= "- Förslag på enkla tester, t.ex. att utesluta en sak i två veckor och se om konsekvensen minskar.\n";
    $t .= "- Svara på svenska. Detta är inte en medicinsk bedömning, så nämn att ihållande eller kraftiga besvär bör tas upp med vården.\n\n";
    $t .= "## Översikt\n";
    $t .= "- Period: $first – $last" . ($fran !== '' || $till !== '' ? " (urval: " . ($fran ?: 'början') . " – " . ($till ?: 'nu') . ")" : '') . "\n";
    $t .= "- Antal loggningar: " . count($rows) . "\n";
    $t .= "- $nh (handlingar): " . $list('handling') . "\n";
    $t .= "- $nk (konsekvenser): " . $list('konsekvens') . "\n";
    $t .= "- Exporterad: " . date('Y-m-d H:i') . "\n\n";
    $t .= "## Data (CSV, kommaseparerad)\n\n```csv\n" . mamCsv($rows, $set, ',') . "```\n";
    return $t;
}

// ============================================================
// INLOGGNING – utan inloggning visas ingenting
// ============================================================
if (!isLoggedIn()) {
    if (isset($_POST['action'])) {
        jsonResponse(['success' => false, 'error' => 'Du är inte inloggad längre. Ladda om sidan och logga in.'], 401);
    }
    renderLoginPage('Mat-å-Mage');
    exit;
}

$uid = currentUserId();

// ============================================================
// AJAX
// ============================================================
if (isset($_POST['action'])) {
    startJsonApi();
    $action = (string)$_POST['action'];

    // ---- Loggning (via kön i webbläsaren) ----
    if ($action === 'op') {
        $o = json_decode((string)($_POST['op'] ?? ''), true);
        if (!is_array($o)) {
            jsonResponse(['success' => false, 'error' => 'Ogiltig ändring.']);
        }
        $type = (string)($o['type'] ?? '');

        if ($type === 'log') {
            $k = mamOwnButton((int)($o['knapp'] ?? 0), $uid);
            if (!$k) {
                jsonResponse(['success' => false, 'error' => 'Knappen finns inte längre.']);
            }
            dbRun(
                'INSERT INTO MamLogg (UserID, KnappID, Tid, Styrka, Kommentar, Skapad) VALUES (?, ?, ?, ?, ?, ?)',
                'iisiss',
                [$uid, (int)$k['ID'], mamMsToSql($o['ms'] ?? 0), mamStyrka($o['styrka'] ?? null, $k['Typ']),
                 mb_substr(trim((string)($o['kommentar'] ?? '')), 0, 255), nowSql()]
            );
            jsonResponse(['success' => true, 'id' => dbInsertId()]);
        }

        $log = dbOne(
            'SELECT l.ID, k.Typ FROM MamLogg l JOIN MamKnappar k ON k.ID = l.KnappID WHERE l.ID = ? AND l.UserID = ?',
            'ii', [(int)($o['id'] ?? 0), $uid]
        );
        if (!$log) {
            jsonResponse(['success' => true, 'ignored' => true]);
        }
        if ($type === 'delete') {
            dbRun('DELETE FROM MamLogg WHERE ID = ?', 'i', [(int)$log['ID']]);
            jsonResponse(['success' => true]);
        }
        if ($type === 'update') {
            $sets = [];
            $types = '';
            $params = [];
            if (array_key_exists('ms', $o)) { $sets[] = 'Tid = ?'; $types .= 's'; $params[] = mamMsToSql($o['ms']); }
            if (array_key_exists('styrka', $o)) { $sets[] = 'Styrka = ?'; $types .= 'i'; $params[] = mamStyrka($o['styrka'], $log['Typ']); }
            if (array_key_exists('kommentar', $o)) { $sets[] = 'Kommentar = ?'; $types .= 's'; $params[] = mb_substr(trim((string)$o['kommentar']), 0, 255); }
            if ($sets) {
                $params[] = (int)$log['ID'];
                dbRun('UPDATE MamLogg SET ' . implode(', ', $sets) . ' WHERE ID = ?', $types . 'i', $params);
            }
            jsonResponse(['success' => true]);
        }
        jsonResponse(['success' => false, 'error' => 'Okänd ändring.']);
    }

    if ($action === 'recent') {
        jsonResponse(['success' => true, 'logs' => mamRecent($uid, max(1, min(400, (int)($_POST['days'] ?? 2))))]);
    }

    // ---- Knappar ----
    if ($action === 'knapp_add') {
        $typ = ($_POST['typ'] ?? '') === 'konsekvens' ? 'konsekvens' : 'handling';
        $namn = mamClean((string)($_POST['namn'] ?? ''), 100);
        if ($namn === '') {
            jsonResponse(['success' => false, 'error' => 'Skriv ett namn på knappen.']);
        }
        $ex = dbOne('SELECT * FROM MamKnappar WHERE UserID = ? AND Typ = ? AND Namn = ?', 'iss', [$uid, $typ, $namn]);
        if ($ex) {
            if ((int)$ex['Arkiverad'] === 1) {
                dbRun('UPDATE MamKnappar SET Arkiverad = 0 WHERE ID = ?', 'i', [(int)$ex['ID']]);
                jsonResponse(['success' => true, 'knapp' => mamButtonForClient($ex), 'restored' => true]);
            }
            jsonResponse(['success' => false, 'error' => "Knappen \"{$ex['Namn']}\" finns redan."]);
        }
        $max = dbOne('SELECT COALESCE(MAX(Ordning), 0) AS M FROM MamKnappar WHERE UserID = ? AND Typ = ?', 'is', [$uid, $typ]);
        dbRun(
            'INSERT INTO MamKnappar (UserID, Typ, Namn, Farg, Ordning, Arkiverad, Skapad) VALUES (?, ?, ?, \'\', ?, 0, ?)',
            'issis', [$uid, $typ, $namn, (int)$max['M'] + 10, nowSql()]
        );
        $id = dbInsertId();
        jsonResponse(['success' => true, 'knapp' => ['id' => $id, 'typ' => $typ, 'namn' => $namn, 'farg' => '']]);
    }

    if (in_array($action, ['knapp_update', 'knapp_move', 'knapp_archive', 'knapp_restore', 'knapp_delete'], true)) {
        $k = mamOwnButton((int)($_POST['id'] ?? 0), $uid);
        if (!$k) {
            jsonResponse(['success' => false, 'error' => 'Knappen finns inte.']);
        }
        $kid = (int)$k['ID'];

        if ($action === 'knapp_update') {
            $namn = mamClean((string)($_POST['namn'] ?? ''), 100);
            $farg = array_key_exists((string)($_POST['farg'] ?? ''), MAM_FARGER) ? (string)$_POST['farg'] : '';
            if ($namn === '') {
                jsonResponse(['success' => false, 'error' => 'Knappen måste ha ett namn.']);
            }
            try {
                dbRun('UPDATE MamKnappar SET Namn = ?, Farg = ? WHERE ID = ?', 'ssi', [$namn, $farg, $kid]);
            } catch (Throwable $e) {
                if ($e instanceof mysqli_sql_exception && $e->getCode() === 1062) {
                    jsonResponse(['success' => false, 'error' => "Det finns redan en knapp som heter \"$namn\"."]);
                }
                throw $e;
            }
            jsonResponse(['success' => true]);
        }

        if ($action === 'knapp_move') {
            $list = dbAll(
                'SELECT ID FROM MamKnappar WHERE UserID = ? AND Typ = ? AND Arkiverad = 0 ORDER BY Ordning, Namn',
                'is', [$uid, $k['Typ']]
            );
            $ids = array_map('intval', array_column($list, 'ID'));
            $pos = array_search($kid, $ids, true);
            $to = $pos + (($_POST['dir'] ?? '') === 'up' ? -1 : 1);
            if ($pos !== false && $to >= 0 && $to < count($ids)) {
                [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
                foreach ($ids as $i => $id) {
                    dbRun('UPDATE MamKnappar SET Ordning = ? WHERE ID = ?', 'ii', [($i + 1) * 10, $id]);
                }
            }
            jsonResponse(['success' => true]);
        }

        if ($action === 'knapp_archive') {
            $count = (int)dbOne('SELECT COUNT(*) AS C FROM MamLogg WHERE KnappID = ?', 'i', [$kid])['C'];
            if ($count === 0) {
                dbRun('DELETE FROM MamKnappar WHERE ID = ?', 'i', [$kid]);
                jsonResponse(['success' => true, 'deleted' => true]);
            }
            dbRun('UPDATE MamKnappar SET Arkiverad = 1 WHERE ID = ?', 'i', [$kid]);
            jsonResponse(['success' => true, 'archived' => true, 'antal' => $count]);
        }

        if ($action === 'knapp_restore') {
            dbRun('UPDATE MamKnappar SET Arkiverad = 0 WHERE ID = ?', 'i', [$kid]);
            jsonResponse(['success' => true]);
        }

        if ($action === 'knapp_delete') {
            if ((int)$k['Arkiverad'] !== 1) {
                jsonResponse(['success' => false, 'error' => 'Arkivera knappen först.']);
            }
            dbRun('DELETE FROM MamKnappar WHERE ID = ?', 'i', [$kid]);   // loggarna försvinner automatiskt
            jsonResponse(['success' => true]);
        }
    }

    // ---- Inställningar ----
    if ($action === 'settings') {
        mamEnsureSettings($uid);
        $nh = mamClean((string)($_POST['namn_handling'] ?? ''), 50) ?: 'Mat';
        $nk = mamClean((string)($_POST['namn_konsekvens'] ?? ''), 50) ?: 'Mage';
        $f = in_array((int)($_POST['fonster'] ?? 72), MAM_FONSTER, true) ? (int)$_POST['fonster'] : 72;
        $i = in_array((int)($_POST['intervall'] ?? 12), MAM_INTERVALL, true) ? (int)$_POST['intervall'] : 12;
        if ($f % $i !== 0) {
            $i = 12;
        }
        $s = in_array((int)($_POST['sammanslag'] ?? 3), MAM_SAMMANSLAG, true) ? (int)$_POST['sammanslag'] : 3;
        dbRun(
            'UPDATE MamInstallningar SET NamnHandling = ?, NamnKonsekvens = ?, Fonster = ?, Intervall = ?, Sammanslag = ? WHERE UserID = ?',
            'ssiiii', [$nh, $nk, $f, $i, $s, $uid]
        );
        jsonResponse(['success' => true]);
    }

    jsonResponse(['success' => false, 'error' => 'Okänd åtgärd.'], 400);
}

$set = mamSettings($uid);
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

// ============================================================
// EXPORT-FILER
// ============================================================
if (isset($_GET['ladda'])) {
    $fran = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fran'] ?? '') ? $_GET['fran'] : '';
    $till = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['till'] ?? '') ? $_GET['till'] : '';
    $rows = mamExportRows($uid, $fran, $till);
    $stamp = date('Y-m-d');
    if ($_GET['ladda'] === 'ai') {
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="matamage-for-ai-' . $stamp . '.md"');
        echo mamAiText($rows, $set, $uid, $fran, $till);
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="matamage-' . $stamp . '.csv"');
        echo "\xEF\xBB\xBF" . mamCsv($rows, $set, ';');   // BOM + semikolon: öppnas rätt i svenskt Excel
    }
    exit;
}

// ============================================================
// GEMENSAMT FÖR VYERNA
// ============================================================
function mamHead(string $title): void { ?>
<!DOCTYPE html>
<html lang="sv">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f4f4f2">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Mat-å-Mage">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="<?= MAM_PAGE ?>?manifest=1">
    <link rel="apple-touch-icon" href="mam-ikon-180.png">
    <link rel="icon" type="image/png" href="mam-ikon-192.png">
    <title><?= h($title) ?></title>
    <link rel="stylesheet" href="minsida.css">
</head>
<?php }

function mamSubNav(string $current): void {
    $links = ['logga' => ['Logga', MAM_PAGE], 'analys' => ['Analys', MAM_PAGE . '?analys=1'],
              'knappar' => ['Knappar', MAM_PAGE . '?knappar=1'], 'export' => ['Export', MAM_PAGE . '?export=1']];
    echo '<div class="sub-nav">';
    foreach ($links as $key => [$label, $url]) {
        echo '<a href="' . h($url) . '"' . ($key === $current ? ' class="active"' : '') . '>' . h($label) . '</a>';
    }
    echo '</div>';
}

function mamScript(): void { ?>
<script>
const CSRF = <?= json_encode(csrfToken()) ?>;
async function post(fields) {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    for (const [k, v] of Object.entries(fields)) fd.append(k, v);
    const r = await fetch('matamage.php', { method: 'POST', body: fd, cache: 'no-store' });
    let data;
    try { data = await r.json(); } catch (e) { data = { success: false, error: 'Oväntat svar från servern (' + r.status + ').' }; }
    return { status: r.status, data };
}
document.querySelectorAll('.popup-overlay').forEach(o => o.addEventListener('click', e => { if (e.target === o) o.hidden = true; }));
document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => { document.getElementById(b.dataset.close).hidden = true; }));
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.popup-overlay').forEach(o => o.hidden = true); });
</script>
<?php }

// ============================================================
// VY: ANALYS
// ============================================================
if (isset($_GET['analys'])) {
    $all = array_merge(mamButtons($uid), mamButtons($uid, true));
    $byId = [];
    foreach ($all as $k) {
        $byId[(int)$k['ID']] = $k;
    }
    $sel = $byId[(int)($_GET['knapp'] ?? 0)] ?? null;
    $fonster = in_array((int)($_GET['fonster'] ?? 0), MAM_FONSTER, true) ? (int)$_GET['fonster'] : $set['fonster'];
    $intervall = in_array((int)($_GET['intervall'] ?? 0), MAM_INTERVALL, true) ? (int)$_GET['intervall'] : $set['intervall'];
    if ($fonster % $intervall !== 0) {
        $intervall = in_array(12, MAM_INTERVALL, true) && $fonster % 12 === 0 ? 12 : 24;
    }
    $sammanslag = in_array((int)($_GET['sammanslag'] ?? -1), MAM_SAMMANSLAG, true) ? (int)$_GET['sammanslag'] : $set['sammanslag'];
    $fran = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fran'] ?? '') ? $_GET['fran'] : '';
    $till = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['till'] ?? '') ? $_GET['till'] : '';
    $minstyrka = max(0, min(3, (int)($_GET['styrka'] ?? 0)));

    $otherTyp = $sel ? ($sel['Typ'] === 'handling' ? 'konsekvens' : 'handling') : null;
    $dolj = [];
    if ($sel) {
        $col = $otherTyp === 'handling' ? 'DoljHandling' : 'DoljKonsekvens';
        if (isset($_GET['f']) && ($_GET['dolj_typ'] ?? '') === $otherTyp) {
            $dolj = array_values(array_filter(array_map('intval', (array)($_GET['dolj'] ?? []))));
            mamEnsureSettings($uid);
            dbRun("UPDATE MamInstallningar SET $col = ? WHERE UserID = ?", 'si', [implode(',', $dolj), $uid]);
        } else {
            $dolj = $otherTyp === 'handling' ? $set['dolj_handling'] : $set['dolj_konsekvens'];
        }
    }

    $res = $sel ? mamAnalyse($uid, $sel, [
        'fonster' => $fonster, 'intervall' => $intervall, 'sammanslag' => $sammanslag,
        'fran' => $fran, 'till' => $till, 'dolj' => $dolj, 'minstyrka' => $minstyrka,
    ]) : null;

    $catName = fn($typ) => $typ === 'handling' ? $set['namn_handling'] : $set['namn_konsekvens'];
    $fmtPct = fn($x) => $x === null ? '' : round($x * 100) . ' %';

    mamHead('Analys – Mat-å-Mage');
    ?>
<body>
<div class="page mam-page">
    <?php renderNav(MAM_PAGE); mamSubNav('analys'); ?>
    <h1>Analys</h1>

    <form method="get" class="filter-form mam-analys-form" id="analys-form">
        <input type="hidden" name="analys" value="1">
        <input type="hidden" name="f" value="1">
        <?php if ($otherTyp): ?><input type="hidden" name="dolj_typ" value="<?= h($otherTyp) ?>"><?php endif; ?>
        <div class="filter-field mam-wide">
            <label>Utgå från</label>
            <select name="knapp" onchange="this.form.submit()">
                <option value="">Välj…</option>
                <?php foreach (['handling', 'konsekvens'] as $typ): ?>
                    <optgroup label="<?= h($catName($typ)) ?> – <?= $typ === 'handling' ? 'vad hände efteråt?' : 'vad hände innan?' ?>">
                        <?php foreach ($all as $k): if ($k['Typ'] !== $typ) continue; ?>
                            <option value="<?= (int)$k['ID'] ?>"<?= $sel && (int)$sel['ID'] === (int)$k['ID'] ? ' selected' : '' ?>>
                                <?= h($k['Namn']) ?> (<?= (int)$k['Antal'] ?>)<?= (int)$k['Arkiverad'] === 1 ? ' – arkiverad' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label>Från</label>
            <input type="date" name="fran" value="<?= h($fran) ?>">
        </div>
        <div class="filter-field">
            <label>Till</label>
            <input type="date" name="till" value="<?= h($till) ?>">
        </div>
        <div class="filter-field">
            <label>Fönster</label>
            <select name="fonster">
                <?php foreach (MAM_FONSTER as $v): ?><option value="<?= $v ?>"<?= $v === $fonster ? ' selected' : '' ?>><?= mamHours($v) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label>Per</label>
            <select name="intervall">
                <?php foreach (MAM_INTERVALL as $v): ?><option value="<?= $v ?>"<?= $v === $intervall ? ' selected' : '' ?>><?= $v ?> h</option><?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label>Ett tillfälle =</label>
            <select name="sammanslag">
                <?php foreach (MAM_SAMMANSLAG as $v): ?><option value="<?= $v ?>"<?= $v === $sammanslag ? ' selected' : '' ?>><?= $v === 0 ? 'varje loggning' : "inom $v h" ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label><?= h($set['namn_konsekvens']) ?>, styrka</label>
            <select name="styrka">
                <option value="0"<?= $minstyrka === 0 ? ' selected' : '' ?>>Alla</option>
                <option value="2"<?= $minstyrka === 2 ? ' selected' : '' ?>>Minst mycket</option>
                <option value="3"<?= $minstyrka === 3 ? ' selected' : '' ?>>Bara kraftigt</option>
            </select>
        </div>
        <?php if ($sel): ?>
            <details class="dolj-box mam-full"<?= $dolj ? ' open' : '' ?>>
                <summary>Visa/dölj <?= h(mb_strtolower($catName($otherTyp))) ?>-knappar<?= $dolj ? ' (' . count($dolj) . ' dolda)' : '' ?></summary>
                <div class="dolj-list">
                    <?php foreach ($all as $k): if ($k['Typ'] !== $otherTyp || (int)$k['Antal'] === 0) continue; $hid = in_array((int)$k['ID'], $dolj, true); ?>
                        <label class="dolj-item<?= $hid ? ' off' : '' ?>">
                            <input type="checkbox" name="dolj[]" value="<?= (int)$k['ID'] ?>"<?= $hid ? ' checked' : '' ?>> <?= h($k['Namn']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="muted small">Bocka i det du <strong>inte</strong> vill se. Valet sparas.</p>
            </details>
        <?php endif; ?>
        <div class="mam-full"><button type="submit" class="primary-btn">Visa</button></div>
    </form>

    <?php if (!$sel): ?>
        <p class="muted">Välj en <?= h(mb_strtolower($set['namn_handling'])) ?>-knapp för att se vad som brukar hända <strong>efteråt</strong>,
            eller en <?= h(mb_strtolower($set['namn_konsekvens'])) ?>-knapp för att se vad som brukar hända <strong>innan</strong>.</p>
    <?php elseif ($res['empty']): ?>
        <p class="empty-row">Inget loggat än.</p>
    <?php else: ?>
        <?php $n = count($res['occ']); $after = $res['after']; ?>
        <h2 class="section-title">
            <?= $after ? 'Efter' : 'Före' ?> <span class="chip-inline farg-<?= h($sel['Farg']) ?>"><?= h($sel['Namn']) ?></span> –
            <?= $after ? 'vad hände de följande' : 'vad hände de' ?> <?= mamHours($res['W']) ?><?= $after ? '?' : ' innan?' ?>
        </h2>
        <p class="muted small">
            <?= $n ?> tillfällen (<?= $res['loggar'] ?> loggningar) mellan <?= date('Y-m-d', $res['dataStart']) ?> och <?= date('Y-m-d', $res['dataEnd']) ?>.
            Varje ruta visar <strong>hur många av tillfällena</strong> där det andra loggades i tidsfönstret, och hur många det <em>normalt</em> skulle vara.
        </p>

        <?php if ($n === 0): ?>
            <p class="empty-row">Inga tillfällen under perioden.</p>
        <?php elseif (!$res['rows']): ?>
            <p class="empty-row">Inget <?= h(mb_strtolower($catName($otherTyp))) ?> loggat under perioden (eller allt är dolt).</p>
        <?php else: ?>
            <div class="table-wrap">
            <table class="inventory-table mam-table">
                <thead><tr>
                    <th class="sticky-col"><?= h($catName($otherTyp)) ?></th>
                    <?php for ($i = 0; $i < $res['nb']; $i++): $a = $i * $res['B']; $b = $a + $res['B']; ?>
                        <th class="num"><?= $a ?>–<?= $b ?> h<?= $after ? '' : ' före' ?></th>
                    <?php endfor; ?>
                    <th class="num">Totalt 0–<?= $res['W'] ?> h</th>
                </tr></thead>
                <tbody>
                <?php foreach ($res['rows'] as $row): ?>
                    <tr>
                        <td class="sticky-col row-head"><span class="dot farg-<?= h($row['farg']) ?>"></span><?= h($row['namn']) ?><?= $row['arkiverad'] ? ' <span class="badge">arkiv</span>' : '' ?></td>
                        <?php foreach (array_merge($row['cells'], [$row['total']]) as $ci => $c):
                            $title = $c['n'] ? sprintf('%d av %d tillfällen (%s). Normalt ca %s. Sannolikhet att det är slump: %s.',
                                $c['hits'], $c['n'], $fmtPct($c['hits'] / $c['n']),
                                $c['expected'] !== null ? number_format($c['expected'], 1, ',', '') : '?',
                                $c['hits'] >= ($c['expected'] ?? 0) ? $fmtPct($c['p_up']) : $fmtPct($c['p_low'])) : 'Inga tillfällen att räkna på.';
                        ?>
                            <td class="num mam-cell <?= $c['mark'] ?><?= $ci === count($row['cells']) ? ' total' : '' ?>" title="<?= h($title) ?>">
                                <?php if ($c['n'] === 0): ?>
                                    <span class="muted">–</span>
                                <?php else: ?>
                                    <span class="cell-main"><?= $c['hits'] ?>/<?= $c['n'] ?><?= $c['mark'] === 'hog2' ? ' ▲▲' : ($c['mark'] === 'hog' ? ' ▲' : ($c['mark'] === 'lag' ? ' ▼' : '')) ?></span>
                                    <span class="cell-sub">normalt <?= $c['expected'] !== null ? number_format($c['expected'], 1, ',', '') : '?' ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div class="mam-legend small">
                <span><span class="mam-cell hog legend-box"></span> ▲ oftare än normalt – troligen inte slump (under 5 % risk)</span>
                <span><span class="mam-cell hog2 legend-box"></span> ▲▲ tydligt oftare (under 1 % risk)</span>
                <span><span class="mam-cell lag legend-box"></span> ▼ mer sällan än normalt</span>
            </div>
            <p class="muted small">
                <strong>Så läser du tabellen:</strong> "7/9, normalt 3,1" betyder att det hände efter 7 av 9 tillfällen, medan man av en slump
                skulle vänta sig ungefär 3 – baserat på hur ofta det händer vid <strong>samma tid på dygnet andra dagar</strong>.
                På så vis räknas inte frukost som "orsak" bara för att besvär oftast loggas på dagen.
                Med många rutor kan enstaka ▲ uppstå av ren slump – ▲▲ och samband som syns i flera kolumner är säkrare.
                Saker som ofta äts tillsammans kan dela på ett samband, och få tillfällen ger osäkra resultat. Håll muspekaren (eller fingret) över en ruta för detaljer.
            </p>
            <details class="occ-list">
                <summary>Visa alla <?= $n ?> tillfällen</summary>
                <ul>
                    <?php foreach (array_reverse($res['occ']) as $t): ?>
                        <li><?= MAM_DAGAR[(int)date('w', $t)] ?> <?= date('Y-m-d H:i', $t) ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
    <?php endif; ?>
</div>
<script>
// Ändrade dolj-rutor visas direkt som avstängda innan man trycker Visa
document.querySelectorAll('.dolj-item input').forEach(cb => cb.addEventListener('change', () => cb.parentElement.classList.toggle('off', cb.checked)));
</script>
</body>
</html>
<?php
    exit;
}

// ============================================================
// VY: KNAPPAR OCH INSTÄLLNINGAR
// ============================================================
if (isset($_GET['knappar'])) {
    $active = mamButtons($uid);
    $archived = mamButtons($uid, true);
    mamHead('Knappar – Mat-å-Mage');
    ?>
<body>
<div class="page mam-page">
    <?php renderNav(MAM_PAGE); mamSubNav('knappar'); ?>
    <h1>Knappar</h1>

    <?php foreach (['handling', 'konsekvens'] as $typ): $name = $typ === 'handling' ? $set['namn_handling'] : $set['namn_konsekvens']; ?>
        <h2 class="section-title"><?= h($name) ?> <span class="muted small">(<?= $typ === 'handling' ? 'handlingar' : 'konsekvenser' ?>)</span></h2>
        <div class="knapp-list">
            <?php $rows = array_values(array_filter($active, fn($k) => $k['Typ'] === $typ)); ?>
            <?php if (!$rows): ?><p class="muted small">Inga knappar än.</p><?php endif; ?>
            <?php foreach ($rows as $i => $k): ?>
                <form class="knapp-row" data-id="<?= (int)$k['ID'] ?>">
                    <span class="dot farg-<?= h($k['Farg']) ?>"></span>
                    <input type="text" name="namn" value="<?= h($k['Namn']) ?>" maxlength="100" aria-label="Namn">
                    <select name="farg" aria-label="Färg">
                        <?php foreach (MAM_FARGER as $fk => $fl): ?><option value="<?= h($fk) ?>"<?= $fk === $k['Farg'] ? ' selected' : '' ?>><?= h($fl) ?></option><?php endforeach; ?>
                    </select>
                    <span class="muted small knapp-count"><?= (int)$k['Antal'] ?> st</span>
                    <button type="button" class="icon-btn move" data-dir="up" title="Flytta upp"<?= $i === 0 ? ' disabled' : '' ?>>↑</button>
                    <button type="button" class="icon-btn move" data-dir="down" title="Flytta ned"<?= $i === count($rows) - 1 ? ' disabled' : '' ?>>↓</button>
                    <button type="submit" class="secondary-btn" hidden>Spara</button>
                    <button type="button" class="secondary-btn archive" data-antal="<?= (int)$k['Antal'] ?>"><?= (int)$k['Antal'] > 0 ? 'Arkivera' : 'Ta bort' ?></button>
                </form>
            <?php endforeach; ?>
            <form class="knapp-add" data-typ="<?= $typ ?>">
                <input type="text" name="namn" maxlength="100" placeholder="Ny knapp, t.ex. <?= $typ === 'handling' ? 'Oboj' : 'Gasig' ?>">
                <button type="submit" class="primary-btn">+ Lägg till</button>
            </form>
        </div>
    <?php endforeach; ?>

    <?php if ($archived): ?>
        <h2 class="section-title">Arkiv</h2>
        <p class="muted small">Arkiverade knappar syns inte när du loggar, men deras historik finns kvar i analysen och exporten.</p>
        <div class="knapp-list">
            <?php foreach ($archived as $k): ?>
                <div class="knapp-row archived" data-id="<?= (int)$k['ID'] ?>" data-namn="<?= h($k['Namn']) ?>" data-antal="<?= (int)$k['Antal'] ?>">
                    <span class="dot farg-<?= h($k['Farg']) ?>"></span>
                    <span class="knapp-name"><?= h($k['Namn']) ?> <span class="muted small">(<?= h($k['Typ'] === 'handling' ? $set['namn_handling'] : $set['namn_konsekvens']) ?>, <?= (int)$k['Antal'] ?> loggningar)</span></span>
                    <button type="button" class="secondary-btn restore">Återställ</button>
                    <button type="button" class="danger-btn delete">Radera för alltid</button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <h2 class="section-title">Inställningar</h2>
    <form id="settings-form" class="settings-form">
        <label>Namn på handlingar <input type="text" name="namn_handling" value="<?= h($set['namn_handling']) ?>" maxlength="50"></label>
        <label>Namn på konsekvenser <input type="text" name="namn_konsekvens" value="<?= h($set['namn_konsekvens']) ?>" maxlength="50"></label>
        <label>Analysera
            <select name="fonster"><?php foreach (MAM_FONSTER as $v): ?><option value="<?= $v ?>"<?= $v === $set['fonster'] ? ' selected' : '' ?>><?= mamHours($v) ?></option><?php endforeach; ?></select>
        </label>
        <label>uppdelat per
            <select name="intervall"><?php foreach (MAM_INTERVALL as $v): ?><option value="<?= $v ?>"<?= $v === $set['intervall'] ? ' selected' : '' ?>><?= $v ?> h</option><?php endforeach; ?></select>
        </label>
        <label>Loggar av samma knapp räknas som ett tillfälle
            <select name="sammanslag"><?php foreach (MAM_SAMMANSLAG as $v): ?><option value="<?= $v ?>"<?= $v === $set['sammanslag'] ? ' selected' : '' ?>><?= $v === 0 ? 'aldrig' : "inom $v h" ?></option><?php endforeach; ?></select>
        </label>
        <button type="submit" class="primary-btn">Spara inställningar</button>
        <p class="popup-msg" id="settings-msg"></p>
    </form>
    <p class="muted small">Verktyget fungerar för vilka handlingar och konsekvenser som helst, t.ex. "Träning" och "Sömn" eller "Medicin" och "Huvudvärk".</p>
</div>

<?php mamScript(); ?>
<script>
async function act(fields, okReload = true) {
    const res = await post(fields);
    if (!res.data.success) { alert(res.data.error || 'Något gick fel.'); return null; }
    if (okReload) location.reload();
    return res.data;
}
document.querySelectorAll('form.knapp-row').forEach(form => {
    const id = form.dataset.id;
    const save = () => act({ action: 'knapp_update', id, namn: form.namn.value, farg: form.farg.value });
    form.addEventListener('submit', e => { e.preventDefault(); save(); });
    form.namn.addEventListener('change', save);
    form.farg.addEventListener('change', save);
    form.querySelectorAll('.move').forEach(b => b.addEventListener('click', () => act({ action: 'knapp_move', id, dir: b.dataset.dir })));
    form.querySelector('.archive').addEventListener('click', function () {
        const antal = +this.dataset.antal;
        const msg = antal > 0
            ? 'Arkivera "' + form.namn.value + '"?\n\nKnappen försvinner från loggningen, men de ' + antal + ' loggningarna finns kvar i analysen. Du kan återställa den när som helst.'
            : 'Ta bort "' + form.namn.value + '"? Den har inga loggningar.';
        if (confirm(msg)) act({ action: 'knapp_archive', id });
    });
});
document.querySelectorAll('form.knapp-add').forEach(form => form.addEventListener('submit', e => {
    e.preventDefault();
    if (form.namn.value.trim()) act({ action: 'knapp_add', typ: form.dataset.typ, namn: form.namn.value });
}));
document.querySelectorAll('.knapp-row.archived').forEach(row => {
    row.querySelector('.restore').addEventListener('click', () => act({ action: 'knapp_restore', id: row.dataset.id }));
    row.querySelector('.delete').addEventListener('click', () => {
        if (confirm('Radera "' + row.dataset.namn + '" för alltid?\n\nAlla ' + row.dataset.antal + ' loggningar raderas också. Det går inte att ångra.'))
            act({ action: 'knapp_delete', id: row.dataset.id });
    });
});
document.getElementById('settings-form').addEventListener('submit', async function (e) {
    e.preventDefault();
    const fields = Object.fromEntries(new FormData(this));
    fields.action = 'settings';
    if (+fields.fonster % +fields.intervall !== 0) { document.getElementById('settings-msg').textContent = 'Fönstret måste gå jämnt upp med intervallet.'; return; }
    await act(fields);
});
</script>
</body>
</html>
<?php
    exit;
}

// ============================================================
// VY: EXPORT
// ============================================================
if (isset($_GET['export'])) {
    $tot = dbOne('SELECT COUNT(*) AS C, MIN(Tid) AS F, MAX(Tid) AS L FROM MamLogg WHERE UserID = ?', 'i', [$uid]);
    mamHead('Export – Mat-å-Mage');
    ?>
<body>
<div class="page mam-page">
    <?php renderNav(MAM_PAGE); mamSubNav('export'); ?>
    <h1>Export</h1>
    <p class="muted"><?= (int)$tot['C'] ?> loggningar<?= $tot['F'] ? ' från ' . h(date('Y-m-d', strtotime($tot['F']))) . ' till ' . h(date('Y-m-d', strtotime($tot['L']))) : '' ?>.</p>

    <form method="get" class="filter-form">
        <input type="hidden" name="export" value="1">
        <div class="filter-field"><label>Från (valfritt)</label><input type="date" name="fran"></div>
        <div class="filter-field"><label>Till (valfritt)</label><input type="date" name="till"></div>
        <div class="export-buttons mam-full">
            <button type="submit" name="ladda" value="ai" class="primary-btn">🤖 Exportera för AI</button>
            <button type="submit" name="ladda" value="csv" class="secondary-btn">⬇ Exportera CSV</button>
        </div>
    </form>

    <div class="export-help">
        <h2 class="section-title">🤖 Exportera för AI</h2>
        <p>Ger <strong>en enda fil</strong> (.md) med instruktioner överst och all data under. Ladda upp den i till exempel Claude eller ChatGPT och skriv
            <em>"Analysera min logg enligt instruktionerna i filen."</em></p>
        <p class="small muted">Instruktionerna förklarar vad <?= h($set['namn_handling']) ?> och <?= h($set['namn_konsekvens']) ?> betyder, hur tillfällen ska räknas
            (inom <?= $set['sammanslag'] ?> h), att jämföra med basnivån, se upp för saker som äts samtidigt och vara tydlig med osäkerhet.</p>
        <h2 class="section-title">⬇ Exportera CSV</h2>
        <p>Ren data att öppna i Excel, Numbers eller Google Kalkylark. Semikolonseparerad med svenska tecken, så den öppnas rätt i svenskt Excel.</p>
        <p class="small muted">Kolumner: id, tid, datum, klockslag, veckodag, typ, kategori, knapp, styrka (1–3), styrka_text, kommentar.</p>
    </div>
</div>
</body>
</html>
<?php
    exit;
}

// ============================================================
// VY: LOGGA (start)
// ============================================================
$state = [
    'knappar'  => array_map('mamButtonForClient', mamButtons($uid)),
    'logs'     => mamRecent($uid, 2),
    'days'     => 2,
    'settings' => ['namn_handling' => $set['namn_handling'], 'namn_konsekvens' => $set['namn_konsekvens']],
];
mamHead('Mat-å-Mage');
?>
<body>
<div class="page mam-page">
    <?php renderNav(MAM_PAGE); mamSubNav('logga'); ?>
    <div id="net-banner" class="net-banner" hidden></div>

    <div class="time-bar">
        <span class="time-label">Tid:</span>
        <div class="time-chips" role="radiogroup" aria-label="När hände det?">
            <button type="button" data-off="0" class="active">Nu</button>
            <button type="button" data-off="30">−30 min</button>
            <button type="button" data-off="60">−1 h</button>
            <button type="button" data-off="120">−2 h</button>
            <button type="button" data-off="custom">Annan…</button>
        </div>
        <input type="datetime-local" id="custom-time" hidden>
        <span id="time-info" class="muted small"></span>
    </div>

    <h2 class="mam-cat" id="cat-handling"></h2>
    <div class="mam-grid" id="grid-handling"></div>

    <h2 class="mam-cat" id="cat-konsekvens"></h2>
    <div class="mam-grid" id="grid-konsekvens"></div>

    <h2 class="section-title">Logg</h2>
    <div id="log-list" class="log-list"></div>
    <button type="button" id="more-btn" class="link-btn">Visa fler dagar</button>
</div>

<div id="toast" class="toast mam-toast" hidden></div>

<div id="edit-overlay" class="popup-overlay" hidden>
    <div class="popup-box" role="dialog" aria-modal="true" aria-labelledby="edit-title">
        <button type="button" class="popup-close" data-close="edit-overlay" aria-label="Stäng">&times;</button>
        <h2 id="edit-title"></h2>
        <form id="edit-form">
            <label>Tid <input type="datetime-local" id="edit-time" required></label>
            <div id="edit-styrka-wrap">
                <span class="small">Styrka</span>
                <div class="styrka-chips" id="edit-styrka">
                    <button type="button" data-v="">Ej angiven</button>
                    <button type="button" data-v="1">Lite</button>
                    <button type="button" data-v="2">Mycket</button>
                    <button type="button" data-v="3">Kraftigt</button>
                </div>
            </div>
            <label>Kommentar <input type="text" id="edit-comment" maxlength="255" placeholder="t.ex. stor portion"></label>
            <button type="submit" class="primary-btn">Spara</button>
        </form>
        <button type="button" id="edit-delete" class="danger-btn">Ta bort loggningen</button>
    </div>
</div>

<?php mamScript(); ?>
<script>
let state = <?= json_encode($state, $jsonFlags) ?>;
const STYRKA = { 1: 'Lite', 2: 'Mycket', 3: 'Kraftigt' };
const DAGAR = ['söndag', 'måndag', 'tisdag', 'onsdag', 'torsdag', 'fredag', 'lördag'];
const QKEY = 'matamage-kö';
const $ = id => document.getElementById(id);

// ============================================================
// Kö (fungerar utan nät)
// ============================================================
function loadQueue() { try { return JSON.parse(localStorage.getItem(QKEY) || '[]'); } catch (e) { return []; } }
function saveQueue() { try { localStorage.setItem(QKEY, JSON.stringify(queue)); } catch (e) {} }
let queue = loadQueue();
let tempId = Math.min(-1, ...queue.filter(o => o.temp).map(o => o.temp)) - 1;
let flushing = false, online = true, netMsg = '';

function applyLocal(o) {
    if (o.type === 'log') {
        const k = state.knappar.find(k => k.id === o.knapp) || { namn: '?', typ: 'handling', farg: '' };
        state.logs.unshift({ id: o.temp, knapp: o.knapp, namn: k.namn, typ: k.typ, farg: k.farg, ms: o.ms,
                             styrka: o.styrka ?? null, kommentar: o.kommentar || '', pending: true });
        state.logs.sort((a, b) => b.ms - a.ms);
    } else {
        const l = state.logs.find(l => l.id === o.id);
        if (!l) return;
        if (o.type === 'delete') state.logs = state.logs.filter(x => x !== l);
        if (o.type === 'update') {
            if ('ms' in o) l.ms = o.ms;
            if ('styrka' in o) l.styrka = o.styrka;
            if ('kommentar' in o) l.kommentar = o.kommentar;
            state.logs.sort((a, b) => b.ms - a.ms);
        }
    }
}

function op(o) {
    if (o.type === 'log') o.temp = tempId--;
    applyLocal(o);
    queue.push(o);
    saveQueue();
    renderLog();
    flush();
    return o;
}

async function flush() {
    if (flushing || !queue.length) return;
    flushing = true;
    let sent = false;
    try {
        while (queue.length) {
            const o = queue[0];
            let res;
            try { res = await post({ action: 'op', op: JSON.stringify(o) }); }
            catch (e) { setOnline(false); return; }
            if (res.status === 401) { setOnline(false, 'Du är utloggad. Ladda om sidan och logga in, så skickas loggningarna.'); return; }
            if (res.status >= 500) { setOnline(false, 'Servern svarar inte just nu – försöker igen.'); return; }
            setOnline(true);
            queue.shift();
            if (o.type === 'log' && res.data.id) {
                queue.forEach(q => { if (q.id === o.temp) q.id = res.data.id; });
                const l = state.logs.find(l => l.id === o.temp);
                if (l) { l.id = res.data.id; l.pending = false; }
                o.id = res.data.id;   // toastens knappar pekar på samma objekt
            }
            if (!res.data.success) toast(res.data.error || 'Något gick fel.');
            saveQueue();
            sent = true;
        }
    } finally {
        flushing = false;
        renderNet();
    }
    if (sent) refresh();
}

async function refresh() {
    if (queue.length) return;
    try {
        const res = await post({ action: 'recent', days: state.days });
        if (res.data.success && !queue.length) { state.logs = res.data.logs; renderLog(); setOnline(true); }
    } catch (e) { setOnline(false); }
}

function setOnline(ok, msg) { online = ok; netMsg = msg || ''; renderNet(); }
function renderNet() {
    const b = $('net-banner');
    if (online && !queue.length) { b.hidden = true; return; }
    b.hidden = false;
    const n = queue.length + (queue.length === 1 ? ' loggning' : ' loggningar');
    b.textContent = netMsg || (online ? 'Skickar ' + n + '…' : '⚠ Ingen kontakt med servern. ' + (queue.length ? n + ' sparas i telefonen och skickas när nätet är tillbaka.' : ''));
}
window.addEventListener('online', flush);
document.addEventListener('visibilitychange', () => { if (!document.hidden) { flush(); refresh(); } });
setInterval(() => { if (queue.length) flush(); }, 10000);

// ============================================================
// Tidsval
// ============================================================
let timeOff = 0;              // minuter bakåt, eller 'custom'
let resetTimer = null;
function pad(n) { return String(n).padStart(2, '0'); }
function toLocalInput(ms) { const d = new Date(ms); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }
function hhmm(ms) { const d = new Date(ms); return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
function logTime() {
    if (timeOff === 'custom') { const v = $('custom-time').value; return v ? new Date(v).getTime() : Date.now(); }
    return Date.now() - timeOff * 60000;
}
function setTimeOff(v) {
    timeOff = v;
    document.querySelectorAll('.time-chips button').forEach(b => b.classList.toggle('active', b.dataset.off === String(v)));
    $('custom-time').hidden = v !== 'custom';
    if (v === 'custom' && !$('custom-time').value) $('custom-time').value = toLocalInput(Date.now() - 3600000);
    renderTimeInfo();
}
function renderTimeInfo() {
    $('time-info').textContent = timeOff === 0 ? '' : 'Loggas som ' + hhmm(logTime());
}
document.querySelectorAll('.time-chips button').forEach(b => b.addEventListener('click', () => setTimeOff(b.dataset.off === 'custom' ? 'custom' : +b.dataset.off)));
$('custom-time').addEventListener('input', renderTimeInfo);
setInterval(renderTimeInfo, 30000);

// ============================================================
// Knappar
// ============================================================
function el(tag, cls, text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }

function renderGrids() {
    $('cat-handling').textContent = state.settings.namn_handling;
    $('cat-konsekvens').textContent = state.settings.namn_konsekvens;
    for (const typ of ['handling', 'konsekvens']) {
        const grid = $('grid-' + typ);
        grid.innerHTML = '';
        state.knappar.filter(k => k.typ === typ).forEach(k => {
            const b = el('button', 'mam-btn farg-' + (k.farg || 'none'), k.namn);
            b.type = 'button';
            b.addEventListener('click', () => logButton(k, b));
            grid.appendChild(b);
        });
        const add = el('button', 'mam-btn mam-add', '+');
        add.type = 'button';
        add.title = 'Ny knapp';
        add.setAttribute('aria-label', 'Ny knapp för ' + (typ === 'handling' ? state.settings.namn_handling : state.settings.namn_konsekvens));
        add.addEventListener('click', () => addButton(typ));
        grid.appendChild(add);
    }
}

let lastLog = null;
function logButton(k, btn) {
    const o = op({ type: 'log', knapp: k.id, ms: logTime() });
    lastLog = o;
    btn.classList.remove('flash'); void btn.offsetWidth; btn.classList.add('flash');
    if (navigator.vibrate) navigator.vibrate(30);
    showLogToast(k, o);
    clearTimeout(resetTimer);
    if (timeOff !== 0) resetTimer = setTimeout(() => setTimeOff(0), 60000);
}

function currentId(o) { return o.id !== undefined ? o.id : o.temp; }

function showLogToast(k, o) {
    const t = $('toast');
    t.innerHTML = '';
    t.appendChild(el('span', 'toast-text', '✓ ' + k.namn + ' ' + hhmm(o.ms)));
    const actions = el('span', 'toast-actions');
    if (k.typ === 'konsekvens') {
        for (const v of [1, 2, 3]) {
            const b = el('button', 'toast-chip', STYRKA[v]);
            b.type = 'button';
            b.addEventListener('click', () => {
                op({ type: 'update', id: currentId(o), styrka: v });
                actions.querySelectorAll('.toast-chip').forEach(x => x.classList.toggle('active', x === b));
                keepToast(4000);
            });
            actions.appendChild(b);
        }
    }
    const c = el('button', 'link-btn', 'Kommentar');
    c.type = 'button';
    c.addEventListener('click', () => {
        const txt = prompt('Kommentar till ' + k.namn + ':', '');
        if (txt !== null) op({ type: 'update', id: currentId(o), kommentar: txt.trim() });
        t.hidden = true;
    });
    actions.appendChild(c);
    const u = el('button', 'link-btn', 'Ångra');
    u.type = 'button';
    u.addEventListener('click', () => { op({ type: 'delete', id: currentId(o) }); t.hidden = true; });
    actions.appendChild(u);
    t.appendChild(actions);
    t.hidden = false;
    keepToast(k.typ === 'konsekvens' ? 8000 : 5000);
}
function keepToast(ms) { clearTimeout(keepToast.timer); keepToast.timer = setTimeout(() => { $('toast').hidden = true; }, ms); }
function toast(text) {
    const t = $('toast');
    t.innerHTML = '';
    t.appendChild(el('span', 'toast-text', text));
    t.hidden = false;
    keepToast(4000);
}

async function addButton(typ) {
    const cat = typ === 'handling' ? state.settings.namn_handling : state.settings.namn_konsekvens;
    const namn = prompt('Ny knapp under ' + cat + ':', '');
    if (!namn || !namn.trim()) return;
    try {
        const res = await post({ action: 'knapp_add', typ, namn });
        if (!res.data.success) { toast(res.data.error || 'Något gick fel.'); return; }
        if (!state.knappar.some(k => k.id === res.data.knapp.id)) state.knappar.push(res.data.knapp);
        renderGrids();
        toast(res.data.restored ? '"' + res.data.knapp.namn + '" är återställd från arkivet.' : 'Knappen "' + res.data.knapp.namn + '" är tillagd.');
    } catch (e) { toast('Ingen kontakt med servern – försök igen.'); }
}

// ============================================================
// Loggen
// ============================================================
function dayLabel(ms) {
    const d = new Date(ms), today = new Date();
    const start = x => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
    const diff = Math.round((start(today) - start(d)) / 86400000);
    if (diff === 0) return 'I dag';
    if (diff === 1) return 'I går';
    return DAGAR[d.getDay()].charAt(0).toUpperCase() + DAGAR[d.getDay()].slice(1) + ' ' + d.getDate() + '/' + (d.getMonth() + 1);
}

function renderLog() {
    const box = $('log-list');
    box.innerHTML = '';
    if (!state.logs.length) {
        box.appendChild(el('p', 'muted small', 'Inget loggat de senaste ' + state.days + ' dagarna. Tryck på en knapp ovan för att börja.'));
        return;
    }
    let lastDay = null, ul = null;
    state.logs.forEach(l => {
        const day = dayLabel(l.ms);
        if (day !== lastDay) {
            box.appendChild(el('h3', 'log-day', day));
            ul = el('ul', 'item-list log-ul');
            box.appendChild(ul);
            lastDay = day;
        }
        const li = el('li', 'item log-item' + (l.pending ? ' pending' : ''));
        li.appendChild(el('span', 'log-time', hhmm(l.ms)));
        li.appendChild(el('span', 'dot farg-' + (l.farg || 'none')));
        const info = el('span', 'item-info');
        const top = el('span', 'row-top');
        top.appendChild(el('span', 'row-name', l.namn));
        if (l.styrka) top.appendChild(el('span', 'row-qty', STYRKA[l.styrka]));
        info.appendChild(top);
        if (l.kommentar) info.appendChild(el('span', 'row-comment', l.kommentar));
        li.appendChild(info);
        li.appendChild(el('span', 'log-kind muted small', l.typ === 'handling' ? state.settings.namn_handling : state.settings.namn_konsekvens));
        li.addEventListener('click', () => openEdit(l));
        ul.appendChild(li);
    });
}

let editLog = null, editStyrka = null;
function openEdit(l) {
    editLog = l;
    editStyrka = l.styrka;
    $('edit-title').textContent = l.namn;
    $('edit-time').value = toLocalInput(l.ms);
    $('edit-comment').value = l.kommentar || '';
    $('edit-styrka-wrap').hidden = l.typ !== 'konsekvens';
    document.querySelectorAll('#edit-styrka button').forEach(b => b.classList.toggle('active', String(b.dataset.v) === String(l.styrka ?? '')));
    $('edit-overlay').hidden = false;
}
document.querySelectorAll('#edit-styrka button').forEach(b => b.addEventListener('click', () => {
    editStyrka = b.dataset.v === '' ? null : +b.dataset.v;
    document.querySelectorAll('#edit-styrka button').forEach(x => x.classList.toggle('active', x === b));
}));
$('edit-form').addEventListener('submit', e => {
    e.preventDefault();
    const o = { type: 'update', id: editLog.id, ms: new Date($('edit-time').value).getTime(), kommentar: $('edit-comment').value.trim() };
    if (editLog.typ === 'konsekvens') o.styrka = editStyrka;
    op(o);
    $('edit-overlay').hidden = true;
});
$('edit-delete').addEventListener('click', () => {
    if (!confirm('Ta bort loggningen "' + editLog.namn + '" ' + hhmm(editLog.ms) + '?')) return;
    op({ type: 'delete', id: editLog.id });
    $('edit-overlay').hidden = true;
});
$('more-btn').addEventListener('click', async () => {
    state.days += 7;
    await flush();
    await refresh();
});

// ============================================================
// Start
// ============================================================
queue.forEach(applyLocal);
renderGrids();
renderLog();
renderNet();
flush();
</script>
</body>
</html>
