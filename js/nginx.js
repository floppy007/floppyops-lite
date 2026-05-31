/**
 * FloppyOps Lite — Nginx
 * Nginx — Sites, System-Checks, SSL Health
 */

function nginxLoadingMarkup(label) {
    return '<div class="empty"><span class="loading-spinner" style="width:14px;height:14px;border-width:2px"></span> ' + label + '</div>';
}

// ── Nginx System-Checks (IP-Forwarding, NAT, Certbot) ──
async function loadNginxChecks() {
    const el = document.getElementById('nginxChecks');
    if (el) {
        el.innerHTML = '<div style="color:var(--text3);font-size:.72rem;padding:6px"><span class="loading-spinner" style="width:10px;height:10px;border-width:1.5px;margin-right:6px"></span>Pruefe...</div>';
    }
    try {
        const d = await api('nginx-checks');
        if (!d.ok) return;
        if (!el) return;
        el.innerHTML = d.checks.map(c => {
            const icon = c.ok
                ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2.5" style="flex-shrink:0"><polyline points="20 6 9 17 4 12"/></svg>'
                : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2.5" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
            let fixBtn = '';
            if (!c.ok && c.fix) {
                if (c.id === 'nat') {
                    fixBtn = '<button class="btn btn-sm btn-green" onclick="nginxApplyFix(\'nat\',{subnet:\'' + escapeJsArg(c.nat_subnet||'') + '\',iface:\'' + escapeJsArg(c.nat_iface||'') + '\'})" style="padding:2px 8px;font-size:.6rem">Aktivieren</button>';
                } else {
                    fixBtn = '<button class="btn btn-sm btn-green" onclick="nginxApplyFix(\'' + escapeJsArg(c.id) + '\')" style="padding:2px 8px;font-size:.6rem">Fix</button>';
                }
            }
            return '<div style="display:flex;align-items:center;gap:8px;padding:5px 8px;border-radius:5px;background:' + (c.ok ? 'rgba(34,197,94,.03)' : 'rgba(255,61,87,.03)') + ';border:1px solid ' + (c.ok ? 'rgba(34,197,94,.1)' : 'rgba(255,61,87,.1)') + '">' +
                icon +
                '<span style="font-size:.75rem;font-weight:500;flex:1">' + escapeHtml(c.label) + '</span>' +
                '<span style="font-size:.65rem;font-family:var(--mono);color:' + (c.ok ? 'var(--green)' : 'var(--red)') + '">' + escapeHtml(c.value) + '</span>' +
                fixBtn +
            '</div>';
        }).join('');
    } catch (e) {
    }
}

async function nginxApplyFix(fixId, extra) {
    toast('Wende Fix an...');
    try {
        const data = { fix_id: fixId };
        if (extra) Object.assign(data, extra);
        const res = await api('nginx-fix', 'POST', data);
        if (res.ok) {
            toast(res.output || 'Fix angewendet');
            loadNginxChecks();
        } else toast(res.error || 'Fehler', 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

// ── Nginx Sites Verwaltung ──────────────────────────
let sitesData = [];

async function loadNginx() {
    const grid = document.getElementById('siteGrid');
    if (grid) {
        grid.innerHTML = nginxLoadingMarkup('Proxy-Sites werden geladen...');
    }
    try {
        sitesData = await api('nginx-sites');
        document.getElementById('siteCount').textContent = sitesData.length;
        if (!grid) return;
        grid.innerHTML = '';

        if (sitesData.length === 0) {
            grid.innerHTML = '<div class="empty">Keine Proxy-Sites konfiguriert</div>';
            return;
        }

        sitesData.forEach((s, i) => {
            const domainTags = s.domains.map(d => `<span class="tag tag-accent">${escapeHtml(d)}</span>`).join(' ');
            let sslTag = '<span class="tag tag-muted">HTTP</span>';
            let sslInfo = '';
            let renewBtn = '';

            if (s.ssl) {
                if (s.ssl_days_left !== null) {
                    let tagClass = 'tag-green';
                    let statusText = s.ssl_days_left + 'd';
                    if (s.ssl_days_left <= 7) { tagClass = 'tag-red'; }
                    else if (s.ssl_days_left <= 30) { tagClass = 'tag-yellow'; }
                    sslTag = '<span class="tag ' + tagClass + '">SSL ' + statusText + '</span>';
                    sslInfo = '<div style="font-size:.68rem;color:var(--text3);margin-top:2px;font-family:var(--mono)">Ablauf: ' + escapeHtml(s.ssl_expiry) + '</div>';
                } else {
                    sslTag = '<span class="tag tag-green" style="opacity:.5">SSL</span>';
                }
                const mainDomain = s.domains[0] || '';
                renewBtn = `<button class="btn btn-sm btn-green" onclick="renewCert('${escapeJsArg(mainDomain)}')" title="SSL erneuern"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg></button>`;
            }

            grid.innerHTML += `
                <div class="site-row" data-file="${escapeHtml(s.file)}">
                    <div class="site-domain">
                        <span class="ssl-tag">${sslTag}</span>
                        <div>
                            <div class="domains">${domainTags}</div>
                            <span class="ssl-info">${sslInfo}</span>
                        </div>
                    </div>
                    <div class="site-target">${s.target ? escapeHtml(s.target) : '<span style="color:var(--text3)">---</span>'}</div>
                    <div class="site-actions">
                        ${renewBtn}
                        <button class="btn btn-sm" onclick="editSite(${i})" title="${T.edit}">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button class="btn btn-sm btn-red" onclick="deleteSite('${escapeJsArg(s.file)}')" title="${T.delete}">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </div>
                </div>`;
        });

        // Lazy: SSL-Expiry nachladen
        loadNginxSsl();
        // Auto-refresh SSL Health on every load (page open + after mutations)
        if (typeof loadSslHealth === 'function' && document.getElementById('sslHealthResult')) loadSslHealth();
    } catch (e) {
        if (grid) {
            grid.innerHTML = '<div class="empty" style="color:var(--red)">Proxy-Sites konnten nicht geladen werden</div>';
        }
    }
}

async function loadNginxSsl() {
    try {
        var d = await api('nginx-ssl-batch');
        if (!d.ok || !d.ssl) return;
        var grid = document.getElementById('siteGrid');
        if (!grid) return;
        sitesData.forEach(function(s) {
            if (!s.ssl || !d.ssl[s.file]) return;
            var info = d.ssl[s.file];
            s.ssl_expiry = info.ssl_expiry;
            s.ssl_days_left = info.ssl_days_left;
            var row = grid.querySelector('[data-file="' + s.file + '"]');
            if (!row) return;
            var tagEl = row.querySelector('.ssl-tag');
            var infoEl = row.querySelector('.ssl-info');
            if (tagEl && info.ssl_days_left !== null) {
                var cls = 'tag-green';
                if (info.ssl_days_left <= 7) cls = 'tag-red';
                else if (info.ssl_days_left <= 30) cls = 'tag-yellow';
                tagEl.innerHTML = '<span class="tag ' + cls + '">SSL ' + info.ssl_days_left + 'd</span>';
            } else if (tagEl) {
                tagEl.innerHTML = '<span class="tag tag-green">SSL</span>';
            }
            if (infoEl && info.ssl_expiry) {
                infoEl.innerHTML = '<div style="font-size:.68rem;color:var(--text3);margin-top:2px;font-family:var(--mono)">Ablauf: ' + escapeHtml(info.ssl_expiry) + '</div>';
            }
        });
    } catch (e) {}
}

function showAddSite() {
    document.getElementById('newDomain').value = '';
    document.getElementById('newTargetIp').value = '10.10.10.';
    document.getElementById('newTargetPort').value = '80';
    document.getElementById('newSsl').checked = true;
    document.getElementById('newForceSsl').checked = true;
    document.getElementById('newWs').checked = false;
    document.getElementById('newMaxUpload').value = '100';
    document.getElementById('newTimeout').value = '60';
    openModal('addSiteModal');
}

function renderProgressStep(step, state) {
    // state: 'pending' | 'running' | done (uses step.ok/warn/skipped)
    let icon, color, bg, border;
    if (state === 'running') {
        icon = '<span class="loading-spinner" style="width:14px;height:14px;border-width:2px"></span>';
        color = 'var(--text2)'; bg = 'rgba(64,196,255,.04)'; border = 'rgba(64,196,255,.15)';
    } else if (state === 'pending') {
        icon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--text3)" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>';
        color = 'var(--text3)'; bg = 'transparent'; border = 'var(--border-subtle)';
    } else if (step.skipped) {
        icon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--text3)" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg>';
        color = 'var(--text2)'; bg = 'rgba(148,163,184,.04)'; border = 'rgba(148,163,184,.15)';
    } else if (step.warn) {
        icon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--yellow)" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
        color = 'var(--yellow)'; bg = 'rgba(234,179,8,.04)'; border = 'rgba(234,179,8,.2)';
    } else if (step.ok) {
        icon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--green)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';
        color = 'var(--green)'; bg = 'rgba(34,197,94,.04)'; border = 'rgba(34,197,94,.15)';
    } else {
        icon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
        color = 'var(--red)'; bg = 'rgba(239,68,68,.05)'; border = 'rgba(239,68,68,.2)';
    }

    const hasOutput = step.output && step.output.length > 0;
    const outId = 'progress-out-' + step.id;
    const expandedByDefault = (!step.ok && !step.skipped) || step.warn;
    const outputHtml = hasOutput ? (
        '<div style="margin-top:6px">' +
        '<button onclick="document.getElementById(\'' + outId + '\').style.display=document.getElementById(\'' + outId + '\').style.display===\'none\'?\'block\':\'none\'" style="background:none;border:0;color:var(--text3);font-size:.62rem;cursor:pointer;padding:0">▸ Details</button>' +
        '<pre id="' + outId + '" style="display:' + (expandedByDefault ? 'block' : 'none') + ';margin:4px 0 0 0;padding:8px;background:var(--bg-deep,#0a0a0a);border:1px solid var(--border-subtle);border-radius:4px;font-size:.65rem;font-family:var(--mono);max-height:220px;overflow:auto;white-space:pre-wrap;color:var(--text2)">' + escapeHtml(step.output) + '</pre>' +
        '</div>'
    ) : '';

    return '<div style="display:flex;flex-direction:column;padding:8px 10px;border-radius:6px;background:' + bg + ';border:1px solid ' + border + '">' +
        '<div style="display:flex;align-items:center;gap:8px">' +
            '<span style="flex-shrink:0;width:14px;height:14px;display:flex;align-items:center;justify-content:center">' + icon + '</span>' +
            '<span style="font-size:.78rem;font-weight:500;color:' + color + ';flex:1">' + escapeHtml(step.label) + '</span>' +
        '</div>' +
        outputHtml +
    '</div>';
}

function showAddSiteProgress(initialSteps) {
    const wrap = document.getElementById('addSiteProgressSteps');
    wrap.innerHTML = initialSteps.map(s => renderProgressStep(s.step, s.state)).join('');
    document.getElementById('addSiteProgressClose').style.display = 'none';
    document.getElementById('addSiteProgressDone').style.display = 'none';
    document.getElementById('addSiteProgressTitle').textContent = (typeof T !== 'undefined' && T.lang === 'de') || document.documentElement.lang === 'de' ? 'Site wird erstellt…' : 'Creating Site…';
    openModal('addSiteProgressModal');
}

function finishAddSiteProgress(steps, overallOk, finalMessage) {
    const wrap = document.getElementById('addSiteProgressSteps');
    wrap.innerHTML = steps.map(s => renderProgressStep(s, 'done')).join('');
    document.getElementById('addSiteProgressClose').style.display = '';
    document.getElementById('addSiteProgressDone').style.display = '';
    const title = document.getElementById('addSiteProgressTitle');
    const isDe = document.documentElement.lang === 'de';
    if (overallOk) {
        title.textContent = isDe ? '✓ Site erstellt' : '✓ Site Created';
        title.style.color = 'var(--green)';
    } else {
        const hasSkip = steps.some(s => s.skipped);
        title.textContent = hasSkip
            ? (isDe ? '⚠ Site erstellt — Aktion erforderlich' : '⚠ Site Created — Action Required')
            : (isDe ? '✗ Fehler beim Erstellen' : '✗ Creation Failed');
        title.style.color = hasSkip ? 'var(--yellow)' : 'var(--red)';
    }
}

async function addSite() {
    const domain = document.getElementById('newDomain').value.trim();
    const ip = document.getElementById('newTargetIp').value.trim();
    const port = document.getElementById('newTargetPort').value.trim() || '80';
    const ssl = document.getElementById('newSsl').checked ? '1' : '0';
    const forceSsl = document.getElementById('newForceSsl').checked ? '1' : '0';
    const ws = document.getElementById('newWs').checked ? '1' : '0';
    const maxUpload = document.getElementById('newMaxUpload').value;
    const timeout = document.getElementById('newTimeout').value;
    const target = 'http://' + ip + ':' + port;

    if (!domain || !ip) { toast('Domain und IP erforderlich', 'error'); return; }

    closeModal('addSiteModal');

    const plannedSteps = [
        { step: { id: 'config', label: 'Nginx-Config schreiben' }, state: 'running' },
        { step: { id: 'nginx-test', label: 'nginx -t (Syntax-Check)' }, state: 'pending' },
        { step: { id: 'nginx-reload', label: 'Nginx Reload' }, state: 'pending' },
        { step: { id: 'dns-precheck', label: 'DNS Pre-Flight Check' }, state: 'pending' },
    ];
    if (ssl === '1') {
        plannedSteps.push({ step: { id: 'cert-acquire', label: 'Let\'s Encrypt Zertifikat' }, state: 'pending' });
        plannedSteps.push({ step: { id: 'cert-verify', label: 'Zertifikat-Auslieferung verifizieren' }, state: 'pending' });
    }
    showAddSiteProgress(plannedSteps);

    try {
        const res = await api('nginx-add', 'POST', { domain, target, ssl, ws, force_ssl: forceSsl, max_upload: maxUpload, timeout });
        const steps = res.steps || [];
        if (!steps.length) {
            // Legacy/error response
            finishAddSiteProgress([{ id: 'err', label: res.error || res.message || 'Unbekannter Fehler', ok: res.ok }], !!res.ok);
            return;
        }
        finishAddSiteProgress(steps, !!res.ok, res.message);
    } catch (e) {
        finishAddSiteProgress([{ id: 'err', label: 'Netzwerk-Fehler', ok: false, output: e.message }], false);
    }
}

function editSite(index) {
    const s = sitesData[index];
    document.getElementById('editSiteFile').value = s.file;
    document.getElementById('editSiteTitle').textContent = s.domains.join(', ') || s.file;
    document.getElementById('editDomains').value = s.domains.join(', ');
    const m = (s.target || '').match(/https?:\/\/([\d.]+):?(\d*)/);
    document.getElementById('editTargetIp').value = m ? m[1] : '';
    document.getElementById('editTargetPort').value = m && m[2] ? m[2] : '80';
    document.getElementById('editWs').checked = /proxy_set_header Upgrade/.test(s.content);
    document.getElementById('editForceSsl').checked = /return 301 https/.test(s.content);
    // Parse max_upload from content
    const uploadM = s.content.match(/client_max_body_size\s+(\d+)m/i);
    document.getElementById('editMaxUpload').value = uploadM ? uploadM[1] : '';
    // Parse timeout from content
    const timeoutM = s.content.match(/proxy_read_timeout\s+(\d+)/);
    document.getElementById('editTimeout').value = timeoutM ? timeoutM[1] : '';
    document.getElementById('editSiteContent').value = s.content;
    document.getElementById('editConfigWrap').style.display = 'none';
    openModal('editSiteModal');
}

async function saveSite() {
    const file = document.getElementById('editSiteFile').value;
    const configWrap = document.getElementById('editConfigWrap');

    if (configWrap.style.display !== 'none') {
        const content = document.getElementById('editSiteContent').value;
        try {
            const res = await api('nginx-save', 'POST', { file, content });
            if (res.ok) { toast('OK'); closeModal('editSiteModal'); loadNginx(); }
            else { toast(res.error || 'Fehler', 'error'); }
        } catch (e) { toast('Fehler: ' + e.message, 'error'); }
        return;
    }

    const domains = document.getElementById('editDomains').value.trim();
    const ip = document.getElementById('editTargetIp').value.trim();
    const port = document.getElementById('editTargetPort').value.trim() || '80';
    const ws = document.getElementById('editWs').checked ? '1' : '0';
    const forceSsl = document.getElementById('editForceSsl').checked ? '1' : '0';
    const maxUpload = document.getElementById('editMaxUpload').value;
    const timeout = document.getElementById('editTimeout').value;

    if (!domains || !ip) { toast('Domain und IP erforderlich', 'error'); return; }

    try {
        const res = await api('nginx-update', 'POST', { file, domains, ip, port, ws, force_ssl: forceSsl, max_upload: maxUpload, timeout });
        if (res.ok) { toast('OK'); closeModal('editSiteModal'); loadNginx(); }
        else { toast(res.error || 'Fehler', 'error'); }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function deleteSite(file) {
    if (!await appConfirm('Site löschen', 'Site <strong>' + escapeHtml(file) + '</strong> wirklich löschen?')) return;
    try {
        const res = await api('nginx-delete', 'POST', { file });
        if (res.ok) {
            toast('Site gelöscht');
            loadNginx();
            loadStats();
        } else {
            toast(res.error || 'Fehler', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function renewCert(domain) {
    toast('SSL-Zertifikat wird erneuert...', 'success');
    try {
        const res = await api('nginx-renew', 'POST', { domain });
        if (res.ok) {
            toast('Zertifikat für ' + domain + ' erneuert');
            loadNginx();
        } else {
            toast(res.error || res.output || 'Renew fehlgeschlagen', 'error');
        }
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

async function reloadNginx() {
    try {
        const res = await api('nginx-reload', 'POST', {});
        toast(res.ok ? 'Nginx neu geladen' : (res.error || 'Fehler'), res.ok ? 'success' : 'error');
    } catch (e) { toast('Fehler: ' + e.message, 'error'); }
}

// ── SSL Health Check ────────────────────────────────
async function loadSslHealth() {
    const el = document.getElementById('sslHealthResult');
    const badge = document.getElementById('sslIssueCount');
    el.innerHTML = `<div style="display:flex;align-items:center;gap:8px;padding:4px 0"><span style="width:14px;height:14px;border:2px solid var(--border-subtle);border-top-color:var(--accent);border-radius:50%;animation:spin .6s linear infinite;flex-shrink:0"></span>${T.ssl_scanning}</div>`;
    badge.style.display = 'none';

    const d = await api('ssl-health');
    if (!d.ok) { el.innerHTML = T.error; return; }

    const results = d.results;
    // CDN-Match (e.g. Cloudflare proxy) counts as expected setup — not an issue.
    const dnsAOk = r => r.dns_a || !!r.dns_a_cdn;
    const dnsAAAAOk = r => !r.has_aaaa || r.dns_aaaa || !!r.dns_aaaa_cdn;
    let issueCount = 0;
    results.forEach(r => {
        if (!dnsAOk(r) || !dnsAAAAOk(r) || !r.ssl_valid || !r.cert_match || !r.v4v6_match) issueCount++;
        if (r.issues?.length) issueCount += r.issues.length;
    });

    if (issueCount > 0) {
        badge.textContent = issueCount;
        badge.style.display = '';
    }

    if (results.length === 0) {
        el.innerHTML = `<div style="text-align:center;color:var(--text3)">${T.ssl_all_ok}</div>`;
        return;
    }

    // Official Cloudflare cloud mark (simple-icons.org, orange brand color)
    const cloudflareLogo = '<svg width="22" height="22" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="#F38020" style="vertical-align:middle" aria-label="Cloudflare"><path d="M16.5088 16.8447c.1475-.5068.0908-.9707-.1553-1.3154-.2246-.3164-.5996-.499-1.0537-.5273l-8.541-.1123a.1559.1559 0 0 1-.1333-.0713.1742.1742 0 0 1-.0181-.1582.2542.2542 0 0 1 .2032-.1543l8.6175-.1123c1.0224-.0488 2.1318-.873 2.5215-1.8779l.4942-1.291a.297.297 0 0 0 .0205-.1611A5.5503 5.5503 0 0 0 8.5645 8.4082a2.4848 2.4848 0 0 0-1.7286-.4824 2.4823 2.4823 0 0 0-2.1492 3.085l.1397.4854c-1.2334.0351-2.3164.834-2.3164 2.3408a2.317 2.317 0 0 0 .0254.3437.1582.1582 0 0 0 .163.1387l12.3848-.0019a.2089.2089 0 0 0 .2002-.1514l.2855-.9131zM19.4756 9.6533c-.0791 0-.1582.002-.2373.0078-.0537.0058-.1074.0468-.1259.1006l-.336 1.1572c-.1474.5069-.0908.9707.1553 1.3155.2246.3164.5996.499 1.0537.5273l1.8203.1123a.1561.1561 0 0 1 .1338.0713c.0234.0488.0303.1074.0186.165a.2547.2547 0 0 1-.2031.1524l-1.8926.1133c-1.0274.0488-2.1318.873-2.5215 1.8779l-.1377.3565a.105.105 0 0 0 .0918.1416h6.5176c.0879 0 .166-.0567.1894-.1417a4.6967 4.6967 0 0 0 .1739-1.292 4.643 4.643 0 0 0-4.6602-4.667z"/></svg>';
    const ok = (v) => v ? `<span style="color:var(--green);font-weight:600">✓</span>` : `<span style="color:var(--red);font-weight:600">✗</span>`;
    const cdn = (name) => name === 'cloudflare'
        ? `<span style="display:inline-flex;align-items:center;gap:3px" title="Hinter Cloudflare-Proxy">${cloudflareLogo}</span>`
        : ok(false);
    const na = `<span style="color:var(--text3)">—</span>`;

    let html = '<table style="width:100%;border-collapse:collapse;font-size:.72rem"><thead><tr style="border-bottom:1px solid var(--border-subtle)">'
        + `<th style="padding:6px 8px;text-align:left;color:var(--text3);font-weight:600">${T.ssl_domain}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_dns_v4}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_dns_v6}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_cert}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_cert_match}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_v4v6}</th>`
        + `<th style="padding:6px 8px;text-align:center;color:var(--text3);font-weight:600">${T.ssl_expiry}</th>`
        + `<th style="padding:6px 8px;text-align:right;color:var(--text3);font-weight:600">${T.ssl_fix}</th>`
        + '</tr></thead><tbody>';

    results.forEach(r => {
        const hasIssue = !dnsAOk(r) || !dnsAAAAOk(r) || !r.ssl_valid || !r.cert_match || !r.v4v6_match || r.issues?.length;
        const rowBg = hasIssue ? 'background:rgba(239,68,68,.04)' : '';

        // DNS tooltips
        const dnsATitle = r.dns_a_ip ? `title="${escapeHtml(r.dns_a_ip)}${r.dns_a_cdn ? ' (' + escapeHtml(r.dns_a_cdn) + ')' : ''}"` : '';
        const dnsAAAATitle = r.dns_aaaa_ip ? `title="${escapeHtml(r.dns_aaaa_ip)}${r.dns_aaaa_cdn ? ' (' + escapeHtml(r.dns_aaaa_cdn) + ')' : ''}"` : '';

        // Cell renderers: CDN logo if not direct match but recognized CDN
        const dnsACell = r.dns_a ? ok(true) : (r.dns_a_cdn ? cdn(r.dns_a_cdn) : ok(false));
        const dnsAAAACell = !r.has_aaaa ? na : (r.dns_aaaa ? ok(true) : (r.dns_aaaa_cdn ? cdn(r.dns_aaaa_cdn) : ok(false)));

        // Expiry
        let expiryHtml = na;
        if (r.ssl_days !== null) {
            const color = r.ssl_days < 7 ? 'var(--red)' : r.ssl_days < 30 ? 'var(--yellow)' : 'var(--green)';
            expiryHtml = `<span style="color:${color};font-family:var(--mono)">${T.ssl_days.replace('%d', r.ssl_days)}</span>`;
        }

        // Fix buttons
        let fixHtml = '';
        if (r.issues?.includes('ipv6only_on')) {
            fixHtml = `<button class="btn btn-sm btn-yellow" onclick="sslFixIpv6only('${escapeJsArg(r.file)}')" style="padding:1px 6px;font-size:.55rem">${T.ssl_fix_ipv6only}</button>`;
        }

        html += `<tr style="border-bottom:1px solid var(--border-subtle);${rowBg}">
            <td style="padding:5px 8px;font-size:.68rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escapeHtml(r.domain)}">${escapeHtml(r.domain)}</td>
            <td style="padding:5px 8px;text-align:center" ${dnsATitle}>${dnsACell}</td>
            <td style="padding:5px 8px;text-align:center" ${dnsAAAATitle}>${dnsAAAACell}</td>
            <td style="padding:5px 8px;text-align:center">${ok(r.ssl_valid)}</td>
            <td style="padding:5px 8px;text-align:center">${ok(r.cert_match)}</td>
            <td style="padding:5px 8px;text-align:center">${r.has_aaaa ? ok(r.v4v6_match) : na}</td>
            <td style="padding:5px 8px;text-align:center">${expiryHtml}</td>
            <td style="padding:5px 8px;text-align:right">${fixHtml}</td>
        </tr>`;
    });
    html += '</tbody></table>';

    if (issueCount === 0) {
        html = `<div style="text-align:center;color:var(--green);padding:8px;font-size:.78rem;margin-bottom:8px">✓ ${T.ssl_all_ok}</div>` + html;
    }

    el.innerHTML = html;
}

async function sslFixIpv6only(file) {
    if (!await appConfirm(T.ssl_fix_ipv6only, T.ssl_fix_ipv6only_desc, 'warning')) return;
    const d = await api('ssl-fix-ipv6only', 'POST', { file });
    if (d.ok) loadSslHealth();
}
