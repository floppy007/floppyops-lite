<?php
/**
 * FloppyOps Lite — API: Fail2ban
 *
 * Fail2ban Jails, Logs, Config — Jails anzeigen, Ban-Log lesen,
 * Konfiguration bearbeiten, Filter auflisten, IPs entbannen.
 *
 * Endpoints: f2b-jails, f2b-log, f2b-config, f2b-save, f2b-filters, f2b-unban
 */

/**
 * Verwaltet alle Fail2ban-Operationen: Jails anzeigen, Logs lesen,
 * Config bearbeiten, Filter auflisten, IPs entbannen.
 *
 * Endpoints: f2b-jails, f2b-log, f2b-config, f2b-save, f2b-filters, f2b-unban
 *
 * @param string $action Der API-Action-Name
 * @return bool true wenn behandelt
 */
function handleFail2banAPI(string $action): bool {
    $fail2banClient = findExecutable(['/usr/bin/fail2ban-client', '/bin/fail2ban-client']);
    $systemctl = findExecutable(['/usr/bin/systemctl', '/bin/systemctl']);
    $tail = findExecutable(['/usr/bin/tail', '/bin/tail']);

    // GET: Alle Fail2ban Jails mit Ban-Statistiken
    if ($action === 'f2b-jails') {
        if ($fail2banClient === null) {
            echo json_encode([]);
            return true;
        }

        $statusCmd = buildSudoCommand([$fail2banClient, 'status'], '2>/dev/null');
        $raw = $statusCmd !== null ? (shell_exec($statusCmd) ?? '') : '';
        preg_match('/Jail list:\s*(.*)$/m', $raw, $m);
        $names = array_filter(array_map('trim', explode(',', $m[1] ?? '')));
        $jails = [];
        foreach ($names as $name) {
            $jailStatusCmd = buildSudoCommand([$fail2banClient, 'status', $name], '2>/dev/null');
            $st = $jailStatusCmd !== null ? (shell_exec($jailStatusCmd) ?? '') : '';
            preg_match('/Currently failed:\s*(\d+)/', $st, $cf);
            preg_match('/Total failed:\s*(\d+)/', $st, $tf);
            preg_match('/Currently banned:\s*(\d+)/', $st, $cb);
            preg_match('/Total banned:\s*(\d+)/', $st, $tb);
            preg_match('/Banned IP list:\s*(.*)$/m', $st, $bl);
            $bannedIPs = array_filter(array_map('trim', explode(' ', $bl[1] ?? '')));
            $jails[] = [
                'name' => $name,
                'failed_current' => (int)($cf[1] ?? 0),
                'failed_total' => (int)($tf[1] ?? 0),
                'banned_current' => (int)($cb[1] ?? 0),
                'banned_total' => (int)($tb[1] ?? 0),
                'banned_ips' => array_values($bannedIPs),
            ];
        }
        echo json_encode($jails);
        return true;
    }

    // GET: Letzte 80 Zeilen aus dem Fail2ban Ban-Log
    if ($action === 'f2b-log') {
        $log = F2B_LOG;
        $lines = [];
        if (file_exists($log) && is_readable($log)) {
            $lines = array_slice(file($log, FILE_IGNORE_NEW_LINES), -80);
            $lines = array_reverse($lines);
        } elseif ($tail !== null) {
            $tailCmd = buildSudoCommand([$tail, '-80', $log], '2>/dev/null');
            $raw = $tailCmd !== null ? (shell_exec($tailCmd) ?? '') : '';
            $lines = array_reverse(array_filter(explode("\n", $raw)));
        }
        echo json_encode($lines);
        return true;
    }

    // GET: Fail2ban-Konfigurationsdatei lesen (jail.local oder Filter)
    if ($action === 'f2b-config') {
        $file = $_GET['file'] ?? 'jail.local';
        $allowed = ['jail.local', 'jail.conf'];
        // Also allow filter files
        if (preg_match('/^filter\.d\/[\w-]+\.conf$/', $file)) {
            $allowed[] = $file;
        }
        if (!in_array($file, $allowed)) {
            echo json_encode(['ok' => false, 'error' => 'Datei nicht erlaubt']);
            return true;
        }
        $path = "/etc/fail2ban/$file";
        if (!file_exists($path)) {
            echo json_encode(['ok' => false, 'error' => 'Datei nicht gefunden']);
            return true;
        }
        echo json_encode(['ok' => true, 'content' => file_get_contents($path), 'file' => $file]);
        return true;
    }

    // POST: Config speichern und Fail2ban neustarten
    if ($action === 'f2b-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if ($systemctl === null) {
            echo json_encode(['ok' => false, 'error' => 'systemctl nicht gefunden']);
            return true;
        }

        $file = $_POST['file'] ?? '';
        $content = $_POST['content'] ?? '';
        if (!in_array($file, ['jail.local']) && !preg_match('/^filter\.d\/[\w-]+\.conf$/', $file)) {
            echo json_encode(['ok' => false, 'error' => 'Datei nicht erlaubt']);
            return true;
        }
        $path = "/etc/fail2ban/$file";
        file_put_contents($path, $content);
        // Restart fail2ban
        $restartCmd = buildSudoCommand([$systemctl, 'restart', 'fail2ban'], '2>&1');
        $statusCmd = buildSudoCommand([$systemctl, 'is-active', 'fail2ban'], '2>/dev/null');
        $out = $restartCmd !== null ? (shell_exec($restartCmd) ?? '') : '';
        $active = $statusCmd !== null ? trim(shell_exec($statusCmd) ?? '') : '';
        echo json_encode(['ok' => $active === 'active', 'status' => $active, 'output' => trim($out)]);
        return true;
    }

    // GET: Verfuegbare Filter-Dateien auflisten
    if ($action === 'f2b-filters') {
        $files = glob('/etc/fail2ban/filter.d/*.conf');
        $filters = array_map(fn($f) => 'filter.d/' . basename($f), $files ?: []);
        sort($filters);
        echo json_encode($filters);
        return true;
    }

    // POST: IP aus einem Jail entbannen
    if ($action === 'f2b-unban' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if ($fail2banClient === null) {
            echo json_encode(['ok' => false, 'error' => 'Fail2ban ist nicht installiert']);
            return true;
        }

        $jail = $_POST['jail'] ?? '';
        $ip = $_POST['ip'] ?? '';
        if (!preg_match('/^[\w-]+$/', $jail) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['ok' => false, 'error' => 'Ungültige Parameter']);
            return true;
        }
        $unbanCmd = buildSudoCommand([$fail2banClient, 'set', $jail, 'unbanip', $ip], '2>&1');
        $out = $unbanCmd !== null ? shell_exec($unbanCmd) : '';
        echo json_encode(['ok' => true, 'output' => trim($out ?? '')]);
        return true;
    }

    return false;
}
