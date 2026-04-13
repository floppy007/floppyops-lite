<?php
/**
 * FloppyOps Lite — API: Security
 *
 * Security-Scanner, Port-Scan, Host-Firewall — Port-Scan via ss,
 * PVE Firewall-Regeln lesen/erstellen/loeschen, Firewall aktivieren
 * mit Safety-Checks, Standard-Regelsatz anwenden.
 *
 * Endpoints: sec-scan, sec-fw-rules, sec-fw-enable, sec-fw-block, sec-fw-add-rule, sec-fw-defaults, sec-fw-delete-rule
 */

/**
 * Security-Scanner und PVE Host-Firewall: Port-Scan, Firewall-Regeln
 * lesen/erstellen/loeschen, Firewall aktivieren, Standard-Regeln.
 *
 * Endpoints: sec-scan, sec-fw-rules, sec-fw-enable, sec-fw-block, sec-fw-add-rule, sec-fw-defaults, sec-fw-delete-rule
 *
 * @param string $action Der API-Action-Name
 * @return bool true wenn behandelt
 */
function secIsPublicPveRule(array $rule): bool {
    if (($rule['action'] ?? '') !== 'ACCEPT' || ($rule['type'] ?? '') !== 'in') {
        return false;
    }
    if (($rule['dport'] ?? '') != '8006') {
        return false;
    }

    $source = trim((string)($rule['source'] ?? ''));
    return $source === '';
}

function secDeleteFirewallRule(string $path): void {
    shell_exec("sudo pvesh delete $path 2>&1");
}

function secFindLitePublicSitePath(): ?string {
    foreach (glob('/etc/nginx/sites-enabled/*') as $path) {
        if (!is_file($path)) continue;
        $content = @file_get_contents($path);
        if ($content === false) continue;
        if (!str_contains($content, 'root /var/www/server-admin;')) continue;
        if (!preg_match('/^\s*server_name\s+(.+);/m', $content, $m)) continue;
        if (trim($m[1]) === '_') continue;

        $basename = basename($path);
        $available = '/etc/nginx/sites-available/' . $basename;
        return is_file($available) ? $available : $path;
    }
    return null;
}

function secReadRootFile(string $path): array {
    $content = @file_get_contents($path);
    if ($content !== false) {
        return ['ok' => true, 'content' => $content];
    }

    $cat = findExecutable(['/usr/bin/cat', '/bin/cat']);
    $cmd = buildSudoCommand([$cat ?: '/bin/cat', $path], '2>/dev/null');
    $content = $cmd !== null ? shell_exec($cmd) : null;
    if ($content !== null && $content !== '') {
        return ['ok' => true, 'content' => $content];
    }

    return ['ok' => false, 'error' => "Konnte Datei nicht lesen: {$path}"];
}

function secWriteNginxSite(string $path, string $content): array {
    $tmp = tempnam('/tmp', 'nginx_');
    if ($tmp === false) {
        return ['ok' => false, 'error' => 'Temp-Datei konnte nicht erstellt werden'];
    }

    file_put_contents($tmp, $content);
    $cp = findExecutable(['/usr/bin/cp', '/bin/cp']) ?: '/usr/bin/cp';
    $copyCmd = buildSudoCommand([$cp, $tmp, $path], '2>&1');
    $output = $copyCmd !== null ? (shell_exec($copyCmd) ?? '') : '';
    @unlink($tmp);

    if (!is_file($path)) {
        return ['ok' => false, 'error' => "Datei nicht gefunden: {$path}", 'details' => trim($output)];
    }

    $verify = @file_get_contents($path);
    if ($verify !== $content) {
        return ['ok' => false, 'error' => "Datei {$path} wurde nicht korrekt geschrieben", 'details' => trim($output)];
    }

    return ['ok' => true];
}

function secNginxReload(): array {
    $nginx = findExecutable(['/usr/sbin/nginx', '/sbin/nginx']) ?: '/usr/sbin/nginx';
    $systemctl = findExecutable(['/usr/bin/systemctl', '/bin/systemctl']) ?: '/usr/bin/systemctl';

    $testCmd = buildSudoCommand([$nginx, '-t'], '2>&1');
    $testOut = $testCmd !== null ? (shell_exec($testCmd) ?? '') : '';
    if (!str_contains($testOut, 'syntax is ok') || !str_contains($testOut, 'test is successful')) {
        return ['ok' => false, 'error' => 'nginx -t fehlgeschlagen', 'details' => trim($testOut)];
    }

    $reloadCmd = buildSudoCommand([$systemctl, 'reload', 'nginx'], '2>&1');
    $reloadOut = $reloadCmd !== null ? (shell_exec($reloadCmd) ?? '') : '';
    return ['ok' => true, 'details' => trim($reloadOut)];
}

function secHasLiteAppWhitelist(string $content): bool {
    return (bool)preg_match('/^\s*allow\s+.+;\s*$/m', $content) && (bool)preg_match('/^\s*deny\s+all;\s*$/m', $content);
}

function handleSecurityAPI(string $action): bool {
    // GET: Port-Scan + PVE Firewall-Status (Risikobewertung)
    if ($action === 'sec-scan') {
        $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');

        // Port-Scan via ss
        $raw = shell_exec('ss -tlnpH 2>/dev/null') ?? '';
        $riskyPorts = [
            111  => ['service' => 'rpcbind',       'risk' => 'high'],
            2049 => ['service' => 'NFS',            'risk' => 'medium'],
            3306 => ['service' => 'MySQL/MariaDB',  'risk' => 'high'],
            5432 => ['service' => 'PostgreSQL',     'risk' => 'high'],
            5900 => ['service' => 'VNC',            'risk' => 'high'],
            6379 => ['service' => 'Redis',          'risk' => 'critical'],
            9200 => ['service' => 'Elasticsearch',  'risk' => 'high'],
            11211 => ['service' => 'Memcached',     'risk' => 'critical'],
            27017 => ['service' => 'MongoDB',       'risk' => 'critical'],
        ];
        $ports = [];
        foreach (explode("\n", trim($raw)) as $line) {
            if (!$line) continue;
            if (!preg_match('/\s+([\[\]:0-9.*]+):(\d+)\s+/', $line, $m)) continue;
            $addr = trim($m[1], '[]');
            $port = (int)$m[2];
            $external = !in_array($addr, ['127.0.0.1', '::1', '0:0:0:0:0:0:0:1']);
            $process = '';
            if (preg_match('/users:\(\("([^"]+)"/', $line, $pm)) $process = $pm[1];
            $risk = null;
            $service = $process ?: "port-$port";
            if ($external && isset($riskyPorts[$port])) {
                $risk = $riskyPorts[$port]['risk'];
                $service = $riskyPorts[$port]['service'];
            }
            // Known PVE services
            $knownServices = [22 => 'SSH', 8006 => 'PVE WebUI', 3128 => 'SPICE', 111 => 'rpcbind',
                85 => 'pvedaemon', 25 => 'SMTP', 80 => 'HTTP', 443 => 'HTTPS', 53 => 'DNS'];
            if (isset($knownServices[$port]) && $service === $process) $service = $knownServices[$port];

            $ports[] = ['port' => $port, 'addr' => $addr, 'process' => $process,
                        'external' => $external, 'risk' => $risk, 'service' => $service];
        }
        // Deduplicate (same port may appear for v4+v6)
        $seen = [];
        $unique = [];
        foreach ($ports as $p) {
            $key = $p['port'];
            if (isset($seen[$key])) {
                // If any binding is external, mark as external
                if ($p['external'] && !$unique[$seen[$key]]['external']) {
                    $unique[$seen[$key]]['external'] = true;
                    $unique[$seen[$key]]['addr'] = $p['addr'];
                    if ($p['risk']) $unique[$seen[$key]]['risk'] = $p['risk'];
                }
                continue;
            }
            $seen[$key] = count($unique);
            $unique[] = $p;
        }
        usort($unique, fn($a, $b) => ($b['risk'] ? 1 : 0) - ($a['risk'] ? 1 : 0) ?: $a['port'] - $b['port']);

        // PVE Firewall status
        $dcFw = json_decode(shell_exec("sudo pvesh get /cluster/firewall/options --output-format json 2>/dev/null") ?? '{}', true);
        $nodeFw = json_decode(shell_exec("sudo pvesh get /nodes/" . escapeshellarg($node) . "/firewall/options --output-format json 2>/dev/null") ?? '{}', true);
        $dcRules = json_decode(shell_exec("sudo pvesh get /cluster/firewall/rules --output-format json 2>/dev/null") ?? '[]', true) ?: [];
        $nodeRules = json_decode(shell_exec("sudo pvesh get /nodes/" . escapeshellarg($node) . "/firewall/rules --output-format json 2>/dev/null") ?? '[]', true) ?: [];
        $publicDc = array_values(array_filter($dcRules, 'secIsPublicPveRule'));
        $publicNode = array_values(array_filter($nodeRules, 'secIsPublicPveRule'));
        $liteSitePath = secFindLitePublicSitePath();
        $liteAppPublic = true;
        $liteAppHost = '';
        if ($liteSitePath !== null) {
            $site = secReadRootFile($liteSitePath);
            if ($site['ok']) {
                $liteAppPublic = !secHasLiteAppWhitelist($site['content']);
                if (preg_match('/^\s*server_name\s+(.+);/m', $site['content'], $m)) {
                    $liteAppHost = trim($m[1]);
                }
            }
        }

        $riskyCount = count(array_filter($unique, fn($p) => $p['risk']));
        $dcOn = !empty($dcFw['enable']);
        $nodeOn = !empty($nodeFw['enable']);
        // PVE: DC firewall is the main switch — if DC is on, firewall is active
        $fwActive = $dcOn;
        echo json_encode(['ok' => true, 'ports' => $unique, 'firewall' => [
            'dc_enabled' => $dcOn,
            'node_enabled' => $nodeOn,
            'dc_policy_in' => $dcFw['policy_in'] ?? 'ACCEPT',
            'node' => $node,
            'pve_public_8006' => [
                'enabled' => !empty($publicDc) || !empty($publicNode),
                'dc_rules' => count($publicDc),
                'node_rules' => count($publicNode),
            ],
            'lite_app_public' => [
                'enabled' => $liteAppPublic,
                'host' => $liteAppHost,
                'path' => $liteSitePath,
            ],
        ], 'summary' => [
            'total_ports' => count($unique),
            'external_ports' => count(array_filter($unique, fn($p) => $p['external'])),
            'risky_ports' => $riskyCount,
            'fw_active' => $fwActive
        ]]);
        return true;
    }

    // GET: PVE Firewall-Regeln lesen (Node + Datacenter)
    if ($action === 'sec-fw-rules') {
        $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');
        $nodeRules = json_decode(shell_exec("sudo pvesh get /nodes/" . escapeshellarg($node) . "/firewall/rules --output-format json 2>/dev/null") ?? '[]', true) ?: [];
        $dcRules = json_decode(shell_exec("sudo pvesh get /cluster/firewall/rules --output-format json 2>/dev/null") ?? '[]', true) ?: [];
        echo json_encode(['ok' => true, 'node_rules' => $nodeRules, 'cluster_rules' => $dcRules, 'node' => $node]);
        return true;
    }

    // POST: Oeffentlichen Zugriff auf Lite-App umschalten
    if ($action === 'sec-app-public-toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $enable = ($_POST['enable'] ?? '') === '1';
        $sitePath = secFindLitePublicSitePath();
        if ($sitePath === null) {
            echo json_encode(['ok' => false, 'error' => 'Lite-Nginx-Site nicht gefunden']);
            return true;
        }

        $read = secReadRootFile($sitePath);
        if (!$read['ok']) {
            echo json_encode(['ok' => false, 'error' => $read['error']]);
            return true;
        }

        $content = $read['content'];
        $whitelistBlock = "    allow 127.0.0.1;\n    allow 10.10.20.0/24;\n    allow 10.10.10.0/24;\n    deny all;\n\n";
        $updated = $content;
        if ($enable) {
            $updated = preg_replace('/^\s*allow\s+.+;\n(?:^\s*allow\s+.+;\n)*^\s*deny\s+all;\n\n/m', '', $content, 1) ?? $content;
        } elseif (!secHasLiteAppWhitelist($content)) {
            $updated = preg_replace('/(^\s*index\s+index\.php;\s*$\n?)/m', "$1\n" . $whitelistBlock, $content, 1) ?? $content;
        }

        if ($updated !== $content) {
            $write = secWriteNginxSite($sitePath, $updated);
            if (!$write['ok']) {
                echo json_encode(['ok' => false, 'error' => $write['error'], 'details' => $write['details'] ?? '']);
                return true;
            }
            $reload = secNginxReload();
            if (!$reload['ok']) {
                echo json_encode(['ok' => false, 'error' => $reload['error'], 'details' => $reload['details'] ?? '']);
                return true;
            }
        }

        echo json_encode(['ok' => true, 'enabled' => $enable]);
        return true;
    }

    // POST: Oeffentlichen Zugriff auf PVE WebUI (8006) umschalten
    if ($action === 'sec-pve-public-toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');
        $enable = ($_POST['enable'] ?? '') === '1';

        $dcRules = json_decode(shell_exec("sudo pvesh get /cluster/firewall/rules --output-format json 2>/dev/null") ?? '[]', true) ?: [];
        $nodeRules = json_decode(shell_exec("sudo pvesh get /nodes/" . escapeshellarg($node) . "/firewall/rules --output-format json 2>/dev/null") ?? '[]', true) ?: [];

        if ($enable) {
            $hasDc = !empty(array_filter($dcRules, 'secIsPublicPveRule'));
            $hasNode = !empty(array_filter($nodeRules, 'secIsPublicPveRule'));
            $comment = escapeshellarg('PVE WebUI public access (FloppyOps Lite)');

            if (!$hasDc) {
                shell_exec("sudo pvesh create /cluster/firewall/rules --action ACCEPT --type in --proto tcp --dport 8006 --enable 1 --comment $comment 2>&1");
            }
            if (!$hasNode) {
                shell_exec("sudo pvesh create /nodes/" . escapeshellarg($node) . "/firewall/rules --action ACCEPT --type in --proto tcp --dport 8006 --enable 1 --comment $comment 2>&1");
            }
        } else {
            foreach ($dcRules as $rule) {
                if (secIsPublicPveRule($rule) && isset($rule['pos'])) {
                    secDeleteFirewallRule("/cluster/firewall/rules/" . (int)$rule['pos']);
                }
            }
            foreach ($nodeRules as $rule) {
                if (secIsPublicPveRule($rule) && isset($rule['pos'])) {
                    secDeleteFirewallRule("/nodes/" . escapeshellarg($node) . "/firewall/rules/" . (int)$rule['pos']);
                }
            }
        }

        $dcRulesAfter = json_decode(shell_exec("sudo pvesh get /cluster/firewall/rules --output-format json 2>/dev/null") ?? '[]', true) ?: [];
        $nodeRulesAfter = json_decode(shell_exec("sudo pvesh get /nodes/" . escapeshellarg($node) . "/firewall/rules --output-format json 2>/dev/null") ?? '[]', true) ?: [];
        $effective = !empty(array_filter($dcRulesAfter, 'secIsPublicPveRule')) || !empty(array_filter($nodeRulesAfter, 'secIsPublicPveRule'));
        echo json_encode(['ok' => true, 'enabled' => $effective]);
        return true;
    }

    // POST: PVE Firewall aktivieren (mit SSH/WebUI Safety)
    if ($action === 'sec-fw-enable' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');
        $level = $_POST['level'] ?? 'node';

        if ($level === 'node') {
            // Safety: ensure SSH + PVE WebUI ACCEPT rules exist
            $rules = json_decode(shell_exec("sudo pvesh get /nodes/" . escapeshellarg($node) . "/firewall/rules --output-format json 2>/dev/null") ?? '[]', true) ?: [];
            $has22 = false; $has8006 = false;
            foreach ($rules as $r) {
                if (($r['action'] ?? '') === 'ACCEPT' && ($r['type'] ?? '') === 'in') {
                    $dp = $r['dport'] ?? '';
                    if ($dp == '22') $has22 = true;
                    if ($dp == '8006') $has8006 = true;
                }
            }
            if (!$has22) shell_exec("sudo pvesh create /nodes/" . escapeshellarg($node) . "/firewall/rules --action ACCEPT --type in --dport 22 --enable 1 --comment 'SSH (auto-added)' 2>&1");
            if (!$has8006) shell_exec("sudo pvesh create /nodes/" . escapeshellarg($node) . "/firewall/rules --action ACCEPT --type in --dport 8006 --enable 1 --comment 'PVE WebUI (auto-added)' 2>&1");
            shell_exec("sudo pvesh set /nodes/" . escapeshellarg($node) . "/firewall/options --enable 1 2>&1");
        } else {
            shell_exec("sudo pvesh set /cluster/firewall/options --enable 1 2>&1");
        }
        echo json_encode(['ok' => true]);
        return true;
    }

    // POST: Port blockieren (DROP Regel)
    if ($action === 'sec-fw-block' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');
        $port = (int)($_POST['port'] ?? 0);
        if ($port < 1 || $port > 65535) { echo json_encode(['error' => 'Invalid port']); return true; }
        $out = shell_exec("sudo pvesh create /nodes/" . escapeshellarg($node) . "/firewall/rules"
            . " --action DROP --type in --dport " . escapeshellarg((string)$port)
            . " --enable 1 --comment " . escapeshellarg("Blocked by FloppyOps (port $port)")
            . " 2>&1");
        echo json_encode(['ok' => true, 'output' => trim($out ?? '')]);
        return true;
    }

    // POST: Neue Firewall-Regel hinzufuegen
    if ($action === 'sec-fw-add-rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');
        $ruleAction = $_POST['rule_action'] ?? 'DROP';
        $type = $_POST['type'] ?? 'in';
        $dport = $_POST['dport'] ?? '';
        $source = $_POST['source'] ?? '';
        $comment = substr(preg_replace('/[^\w\s\-\.\/():,]/', '', $_POST['comment'] ?? ''), 0, 256);
        $level = $_POST['level'] ?? 'node';

        if (!in_array($ruleAction, ['ACCEPT', 'DROP', 'REJECT'])) { echo json_encode(['error' => 'Invalid action']); return true; }
        if (!in_array($type, ['in', 'out'])) { echo json_encode(['error' => 'Invalid type']); return true; }
        if ($dport !== '' && !preg_match('/^\d+([:\-]\d+)?$/', $dport)) { echo json_encode(['error' => 'Invalid port']); return true; }
        if ($source !== '' && !preg_match('/^[\d\.\/]+$/', $source)) { echo json_encode(['error' => 'Invalid source']); return true; }

        $path = $level === 'dc' ? "/cluster/firewall/rules" : "/nodes/" . escapeshellarg($node) . "/firewall/rules";
        $cmd = "sudo pvesh create $path --action " . escapeshellarg($ruleAction) . " --type " . escapeshellarg($type) . " --enable 1";
        if ($dport !== '') $cmd .= " --dport " . escapeshellarg($dport);
        if ($source !== '') $cmd .= " --source " . escapeshellarg($source);
        if ($comment !== '') $cmd .= " --comment " . escapeshellarg($comment);
        $out = shell_exec("$cmd 2>&1");
        echo json_encode(['ok' => true, 'output' => trim($out ?? '')]);
        return true;
    }

    // POST: Standard-Regelsatz anwenden
    if ($action === 'sec-fw-defaults' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');
        $basePath = "/nodes/" . escapeshellarg($node) . "/firewall/rules";

        // Existing rules — avoid duplicates
        $existing = json_decode(shell_exec("sudo pvesh get $basePath --output-format json 2>/dev/null") ?? '[]', true) ?: [];
        $existingPorts = [];
        foreach ($existing as $r) {
            $dp = $r['dport'] ?? '';
            $act = $r['action'] ?? '';
            $existingPorts["$act:$dp"] = true;
        }

        $defaults = [
            ['action' => 'ACCEPT', 'dport' => '22',   'comment' => 'SSH'],
            ['action' => 'ACCEPT', 'dport' => '8006', 'comment' => 'PVE WebUI'],
            ['action' => 'ACCEPT', 'dport' => '3128', 'comment' => 'SPICE Proxy'],
            ['action' => 'DROP',   'dport' => '111',  'comment' => 'rpcbind (blocked)'],
            ['action' => 'DROP',   'dport' => '3306', 'comment' => 'MySQL (blocked)'],
            ['action' => 'DROP',   'dport' => '5432', 'comment' => 'PostgreSQL (blocked)'],
            ['action' => 'DROP',   'dport' => '5900', 'comment' => 'VNC (blocked)'],
            ['action' => 'DROP',   'dport' => '6379', 'comment' => 'Redis (blocked)'],
            ['action' => 'DROP',   'dport' => '11211','comment' => 'Memcached (blocked)'],
            ['action' => 'DROP',   'dport' => '27017','comment' => 'MongoDB (blocked)'],
        ];

        // Filter by selected indices
        $selected = json_decode($_POST['selected'] ?? '[]', true) ?: [];
        if (!empty($selected)) {
            $filtered = [];
            foreach ($selected as $idx) {
                if (isset($defaults[$idx])) $filtered[] = $defaults[$idx];
            }
            $defaults = $filtered;
        }

        $added = 0;
        foreach ($defaults as $rule) {
            $key = $rule['action'] . ':' . $rule['dport'];
            if (isset($existingPorts[$key])) continue;
            shell_exec("sudo pvesh create $basePath"
                . " --action " . escapeshellarg($rule['action'])
                . " --type in --enable 1"
                . " --dport " . escapeshellarg($rule['dport'])
                . " --comment " . escapeshellarg($rule['comment'])
                . " 2>&1");
            $added++;
        }
        echo json_encode(['ok' => true, 'added' => $added]);
        return true;
    }

    // POST: Firewall-Regel loeschen
    if ($action === 'sec-fw-delete-rule' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $node = trim(shell_exec('hostname -s 2>/dev/null') ?? '');
        $pos = (int)($_POST['pos'] ?? -1);
        $level = $_POST['level'] ?? 'node';
        if ($pos < 0) { echo json_encode(['error' => 'Invalid position']); return true; }
        $path = $level === 'dc' ? "/cluster/firewall/rules/$pos" : "/nodes/" . escapeshellarg($node) . "/firewall/rules/$pos";
        $out = shell_exec("sudo pvesh delete $path 2>&1");
        echo json_encode(['ok' => true, 'output' => trim($out ?? '')]);
        return true;
    }

    return false;
}
