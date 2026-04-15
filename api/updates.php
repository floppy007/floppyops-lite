<?php
/**
 * FloppyOps Lite — API: Updates
 *
 * App-Updates, Repositories, System-Updates (apt) — Prueft GitHub auf
 * neue Versionen, verwaltet PVE-Repos (Enterprise/No-Sub), apt upgrade,
 * Auto-Update Crons fuer App und System.
 *
 * Endpoints: update-check, update-pull, repo-check, repo-toggle, repo-add-nosub, apt-check, apt-refresh, apt-upgrade, app-auto-update-save, app-auto-update-status, auto-update-save, auto-update-status
 */

/**
 * Self-Update, Repositories, System-Updates (apt) und Auto-Update Crons.
 * Prueft GitHub auf neue Versionen, verwaltet PVE-Repos, apt upgrade.
 *
 * Endpoints: update-check, update-pull, repo-check, repo-toggle, repo-add-nosub, apt-check, apt-refresh, apt-upgrade, app-auto-update-save, app-auto-update-status, auto-update-save, auto-update-status
 *
 * @param string $action Der API-Action-Name
 * @return bool true wenn behandelt
 */
function runLiteUpdateCommand(string $cmd): array {
    $spec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $spec, $pipes);
    if (!is_resource($proc)) {
        return [
            'ok' => false,
            'output' => 'Updater konnte nicht gestartet werden',
        ];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[2]);
    $exitCode = proc_close($proc);
    $output = trim($stdout . ($stderr !== '' ? ($stdout !== '' ? "\n" : '') . $stderr : ''));

    return [
        'ok' => ($exitCode === 0),
        'output' => $output,
    ];
}

function getAptCodename(): string {
    $codename = trim(shell_exec('lsb_release -cs 2>/dev/null') ?? '');
    if ($codename !== '') {
        return $codename;
    }

    $osRelease = @file_get_contents('/etc/os-release') ?: '';
    if (preg_match('/^VERSION_CODENAME=(.+)$/m', $osRelease, $m)) {
        return trim($m[1], "\"' \t");
    }

    return 'bookworm';
}

function writeAptSourceFile(string $path, string $content): array {
    $cp = findExecutable(['/usr/bin/cp', '/bin/cp']);
    if ($cp === null) {
        return ['ok' => false, 'error' => 'cp nicht gefunden'];
    }

    $tmp = tempnam('/tmp', 'floppyops-lite_repo_');
    if ($tmp === false) {
        return ['ok' => false, 'error' => 'Temp-Datei konnte nicht erstellt werden'];
    }

    file_put_contents($tmp, $content);
    $cmd = buildSudoCommand([$cp, $tmp, $path], '2>&1');
    $output = $cmd !== null ? (shell_exec($cmd) ?? '') : '';
    @unlink($tmp);

    if (!is_file($path)) {
        return ['ok' => false, 'error' => "Datei konnte nicht geschrieben werden: {$path}", 'output' => trim($output)];
    }

    return ['ok' => true, 'output' => trim($output)];
}

function updateRepoEnabledState(string $path, bool $enable): array {
    if (!is_file($path)) {
        return ['ok' => false, 'error' => "Datei nicht gefunden: {$path}"];
    }

    $content = @file_get_contents($path);
    if ($content === false) {
        return ['ok' => false, 'error' => "Datei konnte nicht gelesen werden: {$path}"];
    }

    if (str_ends_with($path, '.sources')) {
        $content = preg_replace('/^Enabled:\s*(yes|no)\s*\n?/mi', '', $content);
        $content = "Enabled: " . ($enable ? 'yes' : 'no') . "\n" . trim($content) . "\n";
    } else {
        $content = $enable
            ? preg_replace('/^#\s*/m', '', $content)
            : preg_replace('/^(?!#)(.+)/m', '# $1', $content);
    }

    return writeAptSourceFile($path, $content);
}

function writeRootFile(string $path, string $content, int $mode = 0644): array {
    $cp = findExecutable(['/usr/bin/cp', '/bin/cp']);
    $chmod = findExecutable(['/usr/bin/chmod', '/bin/chmod']);
    if ($cp === null || $chmod === null) {
        return ['ok' => false, 'error' => 'cp/chmod nicht gefunden'];
    }

    $tmp = tempnam('/tmp', 'floppyops-lite_file_');
    if ($tmp === false) {
        return ['ok' => false, 'error' => 'Temp-Datei konnte nicht erstellt werden'];
    }

    file_put_contents($tmp, $content);
    $copyCmd = buildSudoCommand([$cp, $tmp, $path], '2>&1');
    $copyOut = $copyCmd !== null ? (shell_exec($copyCmd) ?? '') : '';
    @unlink($tmp);
    if (!is_file($path)) {
        return ['ok' => false, 'error' => "Datei konnte nicht geschrieben werden: {$path}", 'output' => trim($copyOut)];
    }

    $chmodCmd = buildSudoCommand([$chmod, sprintf('%04o', $mode), $path], '2>&1');
    $chmodOut = $chmodCmd !== null ? (shell_exec($chmodCmd) ?? '') : '';

    return ['ok' => true, 'output' => trim($copyOut . "\n" . $chmodOut)];
}

function removeRootFile(string $path): void {
    $rm = findExecutable(['/usr/bin/rm', '/bin/rm']);
    if ($rm === null || !is_file($path)) {
        return;
    }
    $cmd = buildSudoCommand([$rm, '-f', $path], '2>/dev/null');
    if ($cmd !== null) {
        shell_exec($cmd);
    }
}

function getAptUpgradePaths(): array {
    return [
        'state' => '/tmp/floppyops-lite-apt-upgrade-state.json',
        'log' => '/tmp/floppyops-lite-apt-upgrade.log',
        'script' => '/tmp/floppyops-lite-apt-upgrade.sh',
    ];
}

function readAptUpgradeState(): array {
    $paths = getAptUpgradePaths();
    $state = ['running' => false, 'ok' => null, 'finished' => false, 'output' => '', 'autoremove' => ''];
    if (is_file($paths['state'])) {
        $data = json_decode((string)file_get_contents($paths['state']), true);
        if (is_array($data)) {
            $state = array_merge($state, $data);
        }
    }
    if (is_file($paths['log'])) {
        $state['log'] = trim((string)shell_exec('tail -n 120 ' . escapeshellarg($paths['log']) . ' 2>/dev/null'));
    } else {
        $state['log'] = '';
    }
    return $state;
}

function handleUpdatesAPI(string $action): bool {
    // GET: Versionsvergleich lokal vs. GitHub
    if ($action === 'update-check') {
        $localVersion = APP_VERSION;
        $installDir = __DIR__ . '/..';
        $isGit = is_dir($installDir . '/.git') || is_dir(dirname($installDir) . '/.git');
        if ($isGit && !is_dir($installDir . '/.git')) $installDir = dirname($installDir);

        // Check latest version from GitHub
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: FloppyOps-Lite\r\n"]]);
        $gh = @file_get_contents('https://raw.githubusercontent.com/floppy007/floppyops-lite/main/index.php', false, $ctx);
        $remoteVersion = $localVersion;
        if ($gh && preg_match("/define\('APP_VERSION',\s*'([^']+)'\)/", $gh, $m)) {
            $remoteVersion = $m[1];
        }
        $updateAvailable = version_compare($remoteVersion, $localVersion, '>');

        echo json_encode([
            'ok' => true,
            'local_version' => $localVersion,
            'remote_version' => $remoteVersion,
            'update_available' => $updateAvailable,
            'is_git' => $isGit,
            'install_dir' => $installDir,
        ]);
        return true;
    }

    // POST: Update durchfuehren (git pull oder Direct Download)
    if ($action === 'update-pull' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $appDir = dirname(__DIR__);
        $cmd = 'FLOPPYOPS_LITE_DEFER_PHP_FPM_RELOAD=1 '
            . buildShellCommand(['/bin/bash', $appDir . '/update.sh', '--dir', $appDir]);
        $res = runLiteUpdateCommand($cmd);
        echo json_encode(['ok' => $res['ok'], 'output' => $res['output']]);
        return true;
    }

    // GET: PVE Repository-Status (Enterprise/No-Sub, Subscription)
    if ($action === 'repo-check') {
        $codename = getAptCodename();
        $isTrixie = ($codename === 'trixie');
        $hasEnterprise = false;
        $hasNoSub = false;

        // Scan ALL repo files for PVE enterprise/no-subscription
        // DEB822 .sources: "Enabled: no" = disabled, no Enabled field = active
        foreach (glob('/etc/apt/sources.list.d/*.sources') as $f) {
            $c = file_get_contents($f);
            $enabled = !preg_match('/^Enabled:\s*no/mi', $c);
            if ($enabled && str_contains($c, 'pve-enterprise')) $hasEnterprise = true;
            if ($enabled && str_contains($c, 'pve-no-subscription')) $hasNoSub = true;
        }
        // .list files (PVE 8 style, but can exist on PVE 9 too)
        foreach (glob('/etc/apt/sources.list.d/*.list') as $f) {
            $c = file_get_contents($f);
            if (preg_match('/^[^#]*pve-enterprise/m', $c)) $hasEnterprise = true;
            if (preg_match('/^[^#]*pve-no-subscription/m', $c)) $hasNoSub = true;
        }

        $subStatus = trim(shell_exec('pvesubscription get 2>/dev/null | grep -i status') ?? '');
        $hasSubscription = str_contains(strtolower($subStatus), 'active');

        // List all repos in sources.list.d
        $repos = [];
        foreach (glob('/etc/apt/sources.list.d/*.list') as $f) {
            $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $active = !str_starts_with(trim($line), '#');
                $clean = ltrim($line, '# ');
                if (preg_match('/^deb\s+(\S+)\s+(\S+)\s+(.+)/', $clean, $m)) {
                    $repos[] = ['file' => basename($f), 'url' => $m[1], 'suite' => $m[2], 'components' => trim($m[3]), 'active' => $active, 'format' => 'list'];
                }
            }
        }
        foreach (glob('/etc/apt/sources.list.d/*.sources') as $f) {
            $c = file_get_contents($f);
            $enabled = !preg_match('/^Enabled:\s*no/mi', $c);
            $uri = ''; $suite = ''; $comp = '';
            if (preg_match('/^URIs?:\s*(.+)/mi', $c, $m)) $uri = trim($m[1]);
            if (preg_match('/^Suites?:\s*(.+)/mi', $c, $m)) $suite = trim($m[1]);
            if (preg_match('/^Components?:\s*(.+)/mi', $c, $m)) $comp = trim($m[1]);
            if ($uri) $repos[] = ['file' => basename($f), 'url' => $uri, 'suite' => $suite, 'components' => $comp, 'active' => $enabled, 'format' => 'deb822'];
        }

        // Standard PVE repos — always show, even if file doesn't exist
        $standardRepos = [
            ['id' => 'pve-enterprise', 'label' => 'Enterprise', 'components' => 'pve-enterprise', 'desc' => 'Stabile Updates (Subscription nötig)'],
            ['id' => 'pve-no-subscription', 'label' => 'No-Subscription', 'components' => 'pve-no-subscription', 'desc' => 'Community-Updates (kostenlos)'],
        ];
        $pveRepos = [];
        foreach ($standardRepos as $sr) {
            // Find all matching repos, prefer active one
            $matches = [];
            foreach ($repos as &$r) {
                if (str_contains($r['components'], $sr['components'])) {
                    $r['_standard'] = $sr['id'];
                    $r['_label'] = $sr['label'];
                    $r['_desc'] = $sr['desc'];
                    $matches[] = $r;
                }
            }
            unset($r);
            $found = !empty($matches);
            if ($found) {
                // Prefer active repo, then official URL
                usort($matches, function($a, $b) {
                    if ($a['active'] !== $b['active']) return $b['active'] ? 1 : -1;
                    $aOfficial = str_contains($a['url'], 'download.proxmox.com');
                    $bOfficial = str_contains($b['url'], 'download.proxmox.com');
                    return $bOfficial - $aOfficial;
                });
                $pveRepos[] = $matches[0];
            }
            if (!$found) {
                $pveRepos[] = [
                    'file' => null, 'url' => 'http://download.proxmox.com/debian/pve',
                    'suite' => $codename, 'components' => $sr['components'],
                    'active' => false, 'format' => $isTrixie ? 'deb822' : 'list',
                    '_standard' => $sr['id'], '_label' => $sr['label'], '_desc' => $sr['desc'],
                    '_missing' => true,
                ];
            }
        }
        // Other repos (non-PVE)
        $otherRepos = array_filter($repos, fn($r) => !isset($r['_standard']));

        echo json_encode([
            'ok' => true,
            'enterprise_active' => $hasEnterprise,
            'no_sub_active' => $hasNoSub,
            'has_subscription' => $hasSubscription,
            'warning' => $hasEnterprise && !$hasSubscription,
            'codename' => $codename,
            'format' => $isTrixie ? 'deb822' : 'list',
            'pve_repos' => array_values($pveRepos),
            'other_repos' => array_values($otherRepos),
        ]);
        return true;
    }

    // POST: Repository aktivieren/deaktivieren
    if ($action === 'repo-toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $file = trim($_POST['file'] ?? '');
        $component = trim($_POST['component'] ?? ''); // for creating new repos
        $enable = ($_POST['enable'] ?? '') === '1';
        $codename = getAptCodename();
        $isTrixie = ($codename === 'trixie');
        $output = [];

        // Create new repo if file doesn't exist
        if (!$file && $component && $enable) {
            $url = 'http://download.proxmox.com/debian/pve';
            if ($isTrixie) {
                $file = 'pve-' . str_replace('pve-', '', $component) . '.sources';
                $path = '/etc/apt/sources.list.d/' . $file;
                $content = "Enabled: yes\nTypes: deb\nURIs: {$url}\nSuites: {$codename}\nComponents: {$component}\nSigned-By: /usr/share/keyrings/proxmox-archive-keyring.gpg\n";
            } else {
                $file = 'pve-' . str_replace('pve-', '', $component) . '.list';
                $path = '/etc/apt/sources.list.d/' . $file;
                $content = "deb {$url} {$codename} {$component}\n";
            }
            $write = writeAptSourceFile($path, $content);
            if (!$write['ok']) {
                echo json_encode(['ok' => false, 'error' => $write['error'], 'output' => $write['output'] ?? '']);
                return true;
            }
            $output[] = 'Erstellt: ' . $file;
        } elseif ($file && preg_match('/^[a-zA-Z0-9._-]+$/', $file)) {
            $path = '/etc/apt/sources.list.d/' . $file;
            if (file_exists($path)) {
                $write = updateRepoEnabledState($path, $enable);
                if (!$write['ok']) {
                    echo json_encode(['ok' => false, 'error' => $write['error'], 'output' => $write['output'] ?? '']);
                    return true;
                }
                $output[] = ($enable ? 'Aktiviert' : 'Deaktiviert') . ': ' . $file;
            }
        }

        $update = runLiteUpdateCommand(buildSudoCommand(['/usr/bin/apt-get', 'update'], '-qq 2>&1') ?? '');
        if (!$update['ok']) {
            echo json_encode(['ok' => false, 'error' => 'apt update fehlgeschlagen', 'output' => $update['output']]);
            return true;
        }
        $output[] = 'apt update ausgeführt';
        echo json_encode(['ok' => true, 'output' => implode("\n", $output)]);
        return true;
    }

    // POST: No-Subscription Repository hinzufuegen
    if ($action === 'repo-add-nosub' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $codename = getAptCodename();
        $isTrixie = ($codename === 'trixie');
        $output = [];

        foreach (glob('/etc/apt/sources.list.d/*.sources') as $path) {
            $content = @file_get_contents($path) ?: '';
            if (!str_contains($content, 'pve-enterprise')) {
                continue;
            }
            $write = updateRepoEnabledState($path, false);
            if (!$write['ok']) {
                echo json_encode(['ok' => false, 'error' => $write['error'], 'output' => $write['output'] ?? '']);
                return true;
            }
            $output[] = 'Deaktiviert: ' . basename($path);
        }
        foreach (glob('/etc/apt/sources.list.d/*.list') as $path) {
            $content = @file_get_contents($path) ?: '';
            if (!preg_match('/^[^#]*pve-enterprise/m', $content)) {
                continue;
            }
            $write = updateRepoEnabledState($path, false);
            if (!$write['ok']) {
                echo json_encode(['ok' => false, 'error' => $write['error'], 'output' => $write['output'] ?? '']);
                return true;
            }
            $output[] = 'Deaktiviert: ' . basename($path);
        }

        foreach (glob('/etc/apt/sources.list.d/*.sources') as $path) {
            $content = @file_get_contents($path) ?: '';
            if (!str_contains($content, 'enterprise.proxmox.com/debian/ceph-')) {
                continue;
            }
            $cephRepo = '';
            if (preg_match('/^URIs:\s*\S*\/debian\/(ceph-[^\s]+)\s*$/mi', $content, $m)) {
                $cephRepo = trim($m[1]);
            }
            $write = writeAptSourceFile(
                $path,
                "Enabled: yes\nTypes: deb\nURIs: http://download.proxmox.com/debian/{$cephRepo}\nSuites: {$codename}\nComponents: no-subscription\nSigned-By: /usr/share/keyrings/proxmox-archive-keyring.gpg\n"
            );
            if (!$write['ok']) {
                echo json_encode(['ok' => false, 'error' => $write['error'], 'output' => $write['output'] ?? '']);
                return true;
            }
            $output[] = 'Aktiviert: ' . basename($path) . ' (Ceph Community)';
        }

        if ($isTrixie) {
            $src = '/etc/apt/sources.list.d/proxmox.sources';
            $content = "Enabled: yes\nTypes: deb\nURIs: http://download.proxmox.com/debian/pve\nSuites: {$codename}\nComponents: pve-no-subscription\nSigned-By: /usr/share/keyrings/proxmox-archive-keyring.gpg\n";
            $write = writeAptSourceFile($src, $content);
            $target = basename($src);
        } else {
            $src = '/etc/apt/sources.list.d/pve-no-subscription.list';
            $write = writeAptSourceFile($src, "deb http://download.proxmox.com/debian/pve {$codename} pve-no-subscription\n");
            $target = basename($src);
        }
        if (!$write['ok']) {
            echo json_encode(['ok' => false, 'error' => $write['error'], 'output' => $write['output'] ?? '']);
            return true;
        }
        $output[] = 'Aktiviert: ' . $target;

        $update = runLiteUpdateCommand(buildSudoCommand(['/usr/bin/apt-get', 'update'], '-qq 2>&1') ?? '');
        if (!$update['ok']) {
            echo json_encode(['ok' => false, 'error' => 'apt update fehlgeschlagen', 'output' => $update['output']]);
            return true;
        }
        $output[] = 'apt update ausgeführt';
        echo json_encode(['ok' => true, 'output' => implode("\n", $output)]);
        return true;
    }

    // GET: Verfuegbare apt-Updates auflisten
    if ($action === 'apt-check') {
        $updates = [];
        $raw = shell_exec('apt list --upgradable 2>/dev/null') ?? '';
        foreach (explode("\n", trim($raw)) as $line) {
            if (str_contains($line, 'Listing') || empty(trim($line))) continue;
            if (preg_match('/^(\S+)\/\S+\s+(\S+)\s+\S+\s+\[upgradable from: (\S+)\]/', $line, $m)) {
                $updates[] = ['name' => $m[1], 'new' => $m[2], 'old' => $m[3]];
            }
        }
        $lastCheck = trim(shell_exec('stat -c %Y /var/cache/apt/pkgcache.bin 2>/dev/null') ?? '0');
        $rebootRequired = file_exists('/var/run/reboot-required');
        $rebootPackages = [];
        if ($rebootRequired && is_readable('/var/run/reboot-required.pkgs')) {
            $rebootPackages = array_values(array_filter(array_map('trim', file('/var/run/reboot-required.pkgs', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])));
        }
        echo json_encode([
            'ok' => true,
            'updates' => $updates,
            'count' => count($updates),
            'last_check' => (int)$lastCheck,
            'reboot_required' => $rebootRequired,
            'reboot_packages' => $rebootPackages,
        ]);
        return true;
    }

    // POST: apt-get update ausfuehren
    if ($action === 'apt-refresh' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $out = shell_exec('sudo apt-get update 2>&1') ?? '';
        $ok = str_contains($out, 'Reading package lists') || str_contains($out, 'Paketlisten werden gelesen');
        echo json_encode(['ok' => $ok, 'output' => trim(substr($out, -500))]);
        return true;
    }

    // POST: apt dist-upgrade + autoremove ausfuehren
    if ($action === 'apt-upgrade' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $paths = getAptUpgradePaths();
        $current = readAptUpgradeState();
        if (!empty($current['running'])) {
            echo json_encode(['ok' => true, 'started' => false, 'running' => true, 'output' => 'Update laeuft bereits']);
            return true;
        }

        $upgradeCmd = buildSudoCommand(['/usr/bin/apt-get', 'dist-upgrade', '-y'], '>> ' . escapeshellarg($paths['log']) . ' 2>&1');
        $autoremoveCmd = buildSudoCommand(['/usr/bin/apt-get', 'autoremove', '-y'], '>> ' . escapeshellarg($paths['log']) . ' 2>&1');
        if ($upgradeCmd === null || $autoremoveCmd === null) {
            echo json_encode(['ok' => false, 'error' => 'apt-get konnte nicht gestartet werden']);
            return true;
        }

        @unlink($paths['log']);
        file_put_contents($paths['state'], json_encode([
            'running' => true,
            'finished' => false,
            'ok' => null,
            'output' => 'Update gestartet',
            'autoremove' => '',
            'started_at' => time(),
        ], JSON_UNESCAPED_SLASHES));

        $script = <<<SH
#!/bin/sh
STATE_FILE={$paths['state']}
LOG_FILE={$paths['log']}
{
  echo "== apt-get dist-upgrade =="
  {$upgradeCmd}
  UPGRADE_RC=\$?
  echo
  echo "== apt-get autoremove =="
  {$autoremoveCmd}
  AUTOREMOVE_RC=\$?
  php -r '
\$upgradeRc = (int)(\$argv[1] ?? 1);
\$autoremoveRc = (int)(\$argv[2] ?? 1);
\$logFile = \$argv[3] ?? "";
\$stateFile = \$argv[4] ?? "";
\$output = trim((string)@shell_exec("tail -n 80 " . escapeshellarg(\$logFile) . " 2>/dev/null"));
\$state = [
  "running" => false,
  "finished" => true,
  "ok" => (\$upgradeRc === 0),
  "output" => \$output,
  "autoremove" => (\$autoremoveRc === 0 ? "done" : "failed"),
  "finished_at" => time(),
];
file_put_contents(\$stateFile, json_encode(\$state, JSON_UNESCAPED_SLASHES));
' "\$UPGRADE_RC" "\$AUTOREMOVE_RC" "\$LOG_FILE" "\$STATE_FILE"
} >/dev/null 2>&1
SH;
        file_put_contents($paths['script'], $script . "\n");
        @chmod($paths['script'], 0700);
        shell_exec('nohup /bin/sh ' . escapeshellarg($paths['script']) . ' >/dev/null 2>&1 &');

        echo json_encode(['ok' => true, 'started' => true, 'running' => true, 'output' => 'Update gestartet']);
        return true;
    }

    if ($action === 'apt-upgrade-status') {
        $state = readAptUpgradeState();
        echo json_encode(array_merge(['ok' => true], $state));
        return true;
    }

    // POST: App Auto-Update Cron konfigurieren
    if ($action === 'app-auto-update-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $enabled = ($_POST['enabled'] ?? '') === '1';
        $day = (int)($_POST['day'] ?? 0);
        $hour = max(0, min(23, (int)($_POST['hour'] ?? 4)));
        $cronFile = '/etc/cron.d/floppyops-lite-app-update';
        removeRootFile('/etc/cron.daily/floppyops-lite-app-update'); // remove old format
        if ($enabled) {
            $dayField = $day === 0 ? '*' : (string)$day;
            $appDir = dirname(__DIR__);
            $cmd = buildShellCommand(['/bin/bash', $appDir . '/update.sh', '--dir', $appDir]);
            $script = "# FloppyOps Lite App Auto-Update\n0 {$hour} * * {$dayField} root {$cmd} > /var/log/floppyops-lite-app-update.log 2>&1\n";
            $write = writeRootFile($cronFile, $script, 0644);
            if (!$write['ok']) {
                echo json_encode(['ok' => false, 'error' => $write['error'], 'output' => $write['output'] ?? '']);
                return true;
            }
        } else {
            removeRootFile($cronFile);
        }
        echo json_encode(['ok' => true, 'enabled' => $enabled, 'day' => $day, 'hour' => $hour]);
        return true;
    }

    // GET: App Auto-Update Cron-Status lesen
    if ($action === 'app-auto-update-status') {
        $cronFile = '/etc/cron.d/floppyops-lite-app-update';
        $oldCron = '/etc/cron.daily/floppyops-lite-app-update';
        $enabled = file_exists($cronFile) || file_exists($oldCron);
        $day = 0; $hour = 4;
        if (file_exists($cronFile)) {
            $c = file_get_contents($cronFile);
            if (preg_match('/^0\s+(\d+)\s+\*\s+\*\s+(\S+)/m', $c, $m)) {
                $hour = (int)$m[1]; $day = $m[2] === '*' ? 0 : (int)$m[2];
            }
        }
        echo json_encode(['ok' => true, 'enabled' => $enabled, 'day' => $day, 'hour' => $hour]);
        return true;
    }

    // POST: System Auto-Update Cron konfigurieren
    if ($action === 'auto-update-save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $enabled = ($_POST['enabled'] ?? '') === '1';
        $day = (int)($_POST['day'] ?? 0); // 0=daily, 1-7=Mon-Sun
        $hour = max(0, min(23, (int)($_POST['hour'] ?? 3)));
        $cronFile = '/etc/cron.d/floppyops-lite-update';
        if ($enabled) {
            $dayField = $day === 0 ? '*' : (string)$day;
            $script = "# FloppyOps Lite Auto-Update\n0 {$hour} * * {$dayField} root apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get dist-upgrade -y -qq && apt-get autoremove -y -qq > /var/log/floppyops-lite-update.log 2>&1\n";
            $write = writeRootFile($cronFile, $script, 0644);
            if (!$write['ok']) {
                echo json_encode(['ok' => false, 'error' => $write['error'], 'output' => $write['output'] ?? '']);
                return true;
            }
            // Remove old cron.daily file if exists
            removeRootFile('/etc/cron.daily/floppyops-lite-update');
        } else {
            removeRootFile($cronFile);
            removeRootFile('/etc/cron.daily/floppyops-lite-update');
        }
        echo json_encode(['ok' => true, 'enabled' => $enabled, 'day' => $day, 'hour' => $hour]);
        return true;
    }

    // GET: System Auto-Update Cron-Status lesen
    if ($action === 'auto-update-status') {
        $cronFile = '/etc/cron.d/floppyops-lite-update';
        $oldCron = '/etc/cron.daily/floppyops-lite-update';
        $enabled = file_exists($cronFile) || file_exists($oldCron);
        $day = 0; $hour = 3;
        if (file_exists($cronFile)) {
            $c = file_get_contents($cronFile);
            if (preg_match('/^0\s+(\d+)\s+\*\s+\*\s+(\S+)/m', $c, $m)) {
                $hour = (int)$m[1];
                $day = $m[2] === '*' ? 0 : (int)$m[2];
            }
        }
        $tz = trim(shell_exec('timedatectl show --property=Timezone --value 2>/dev/null') ?? '') ?: (trim(file_get_contents('/etc/timezone') ?? '') ?: 'UTC');
        echo json_encode(['ok' => true, 'enabled' => $enabled, 'day' => $day, 'hour' => $hour, 'timezone' => $tz]);
        return true;
    }

    return false;
}
