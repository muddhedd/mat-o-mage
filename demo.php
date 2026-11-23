<?php
/**
 * demo.php – sandlåda för Mat-å-Mage (används bara när AUTH_MODE = 'demo')
 * Exempeldatan ligger under UserID 0 (se sql/demo.sql) och kopieras till
 * varje ny besökare. Datumen flyttas hela dygn, så att klockslagen behålls
 * och den sista dagen i exempeldatan blir i går.
 */
if (!defined('AUTH_MODE')) {
    http_response_code(403);
    exit;
}

function demoClone(int $uid): void {
    $db = getDb();
    mysqli_begin_transaction($db);

    $max = dbOne('SELECT MAX(Tid) AS M FROM MamLogg WHERE UserID = 0');
    $days = 0;
    if (!empty($max['M'])) {
        $days = (int)round((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($max['M'])))) / 86400) - 1;
    }
    $shift = function ($d) use ($days) { return date('Y-m-d H:i:s', strtotime($d . ' ' . ($days >= 0 ? '+' : '') . $days . ' days')); };

    $knapp = [];
    foreach (dbAll('SELECT * FROM MamKnappar WHERE UserID = 0 ORDER BY ID') as $k) {
        dbRun(
            'INSERT INTO MamKnappar (UserID, Typ, Namn, Farg, Ordning, Arkiverad, Skapad) VALUES (?, ?, ?, ?, ?, ?, ?)',
            'isssiis', [$uid, $k['Typ'], $k['Namn'], $k['Farg'], (int)$k['Ordning'], (int)$k['Arkiverad'], $shift($k['Skapad'])]
        );
        $knapp[(int)$k['ID']] = dbInsertId();
    }

    foreach (dbAll('SELECT * FROM MamLogg WHERE UserID = 0 ORDER BY ID') as $l) {
        if (!isset($knapp[(int)$l['KnappID']])) {
            continue;
        }
        dbRun(
            'INSERT INTO MamLogg (UserID, KnappID, Tid, Styrka, Kommentar, Skapad) VALUES (?, ?, ?, ?, ?, ?)',
            'iisiss', [$uid, $knapp[(int)$l['KnappID']], $shift($l['Tid']), $l['Styrka'] !== null ? (int)$l['Styrka'] : null, $l['Kommentar'], $shift($l['Skapad'])]
        );
    }

    $s = dbOne('SELECT * FROM MamInstallningar WHERE UserID = 0');
    if ($s) {
        dbRun(
            "INSERT INTO MamInstallningar (UserID, NamnHandling, NamnKonsekvens, Fonster, Intervall, Sammanslag, DoljHandling, DoljKonsekvens)
             VALUES (?, ?, ?, ?, ?, ?, '', '')",
            'issiii', [$uid, $s['NamnHandling'], $s['NamnKonsekvens'], (int)$s['Fonster'], (int)$s['Intervall'], (int)$s['Sammanslag']]
        );
    }

    mysqli_commit($db);
}

function demoPurge(array $uids): void {
    $in = demoIdList($uids);
    dbRun("DELETE FROM MamLogg WHERE UserID IN ($in)");
    dbRun("DELETE FROM MamKnappar WHERE UserID IN ($in)");
    dbRun("DELETE FROM MamInstallningar WHERE UserID IN ($in)");
}
