# Release Notes: FloppyOps Lite

Benutzerfreundliche Release-Hinweise pro Version. Die vollständige, technische
Änderungshistorie steht in [CHANGELOG.md](CHANGELOG.md).

User-facing release notes per version. The complete technical change history is
in [CHANGELOG.md](CHANGELOG.md).

---

## Upgrade / Update

### 🇩🇪 Deutsch

**Normales Update (ab v1.2.1):**

    cd /var/www/server-admin && ./update.sh

**Wichtig beim Sprung von einer Version VOR v1.3.1:** Das laufende Update wird
noch von der *alten* `update.sh` gesteuert, die sudoers-Regeln nur ergänzt
(nicht neu erzeugt). Damit das gehärtete Regelwerk vollständig greift (alte
Catch-all-Regeln entfernen, neue scoped Rules wie `wg set *` setzen), nach dem
ersten Update **einmalig** zusätzlich ausführen:

    cd /var/www/server-admin && ./update.sh      # zweiter Lauf, jetzt neue update.sh
    # ODER gleichwertig:
    sudo ./setup.sh

Ab v1.3.1 aufwärts genügt **ein** `update.sh`-Lauf: die Datei
`/etc/sudoers.d/server-admin` wird vollständig neu generiert und validiert.

**Sehr alte Installationen (v1.1.x / v1.2.0):** Diese Versionen haben noch kein
`update.sh`. Einmal `sudo ./setup.sh` ausführen; danach funktioniert `update.sh`
dauerhaft.

### 🇬🇧 English

**Normal update (v1.2.1 and newer):**

    cd /var/www/server-admin && ./update.sh

**Important when jumping from a version BEFORE v1.3.1:** that update run is still
driven by the *old* `update.sh`, which only appends sudoers rules (it does not
regenerate them). For the hardened ruleset to fully apply (remove old catch-alls,
add the new scoped rules such as `wg set *`), run once more after the first
update:

    cd /var/www/server-admin && ./update.sh      # second run, now the new update.sh
    # OR equivalently:
    sudo ./setup.sh

From v1.3.1 onward, a **single** `update.sh` run is enough: it fully regenerates
and validates `/etc/sudoers.d/server-admin`.

**Very old installs (v1.1.x / v1.2.0):** these predate `update.sh`. Run
`sudo ./setup.sh` once; `update.sh` works from then on.

---

## v1.3.1 (2026-05-31)

### 🇩🇪 Deutsch

**Sicherheits-Audit: alle Befunde behoben und nachgeprüft.** Diese Version
schließt mehrere Wege, über die ein angemeldeter (auch nicht-root-)Benutzer oder
ein per CSRF/XSS missbrauchter Admin-Browser Root-Rechte auf dem Host erlangen
konnte.

**Sicherheit**
- **WireGuard:** Live-Peer-Änderungen (`wg set`) laufen nicht mehr über eine
  Shell, und alle in eine Tunnel-Konfiguration geschriebenen Felder (Schlüssel,
  Adressen, Endpoint, AllowedIPs, PSK, Client-Daten) werden streng geprüft und
  auf Zeilenumbrüche zurückgewiesen. Damit ist kein eingeschleustes
  `PostUp = …` mehr möglich, das `wg-quick` als root ausführt.
- **nginx:** Beim Bearbeiten eines Reverse-Proxy-Eintrags werden `IP`, `Port`
  und `Domains` validiert, bevor sie in die Site-Konfiguration geschrieben
  werden, kein Einschleusen beliebiger nginx-Direktiven (z. B. `alias /;` zum
  Lesen beliebiger Dateien) mehr.
- **Oberfläche (XSS):** Alle von außen beeinflussbaren Serverdaten (Logzeilen,
  gebannte IPs, Peer-/VM-Namen, Zertifikatsfelder, der `Host`-Header …) werden
  vor der Anzeige HTML-escaped. Keine gespeicherten oder DOM-basierten
  Cross-Site-Scripting-Lücken mehr.
- **sudo-Rechte:** Die Root-Freigaben für `www-data` wurden von Sammel-Regeln
  (`cp *`, `chmod *`, `chown *`, `iptables *`, `pvesh *`, `cat /etc/pve/*`) auf
  exakt die Befehle und Pfade eingegrenzt, die das Panel braucht. Ein
  Kompromittieren des Webdienstes führt damit nicht mehr trivial zu Root.
- **Installer/Updater:** Das sudo-Regelwerk hat jetzt eine einzige Quelle
  (`helpers/sudoers-rules.sh`); `setup.sh` und `update.sh` erzeugen dieselbe,
  per `visudo` geprüfte Datei. `update.sh` regeneriert sie vollständig und
  **entfernt** dabei alte, zu breite Regeln auf bestehenden Installationen
  (früher wurden nur Zeilen ergänzt, nie entfernt).

**Verbesserungen**
- **nginx-Site-Erstellung mit Live-Fortschritt:** Jeder Schritt (Konfiguration
  geschrieben, Site aktiviert, `nginx -t`, Reload, SSL/Certbot, Auslieferung
  geprüft) wird einzeln mit Ausgabe angezeigt.
- **Deep-Links per URL-Anker:** `/#network`, `#zfs`, `#system` usw. öffnen direkt
  den passenden Tab und laden dessen Daten.
- **Favicon** wird jetzt aus `/favicon.png` geladen (statt als Inline-Data-URI).
- **JS-Dateien werden anhand des Änderungsdatums versioniert**, sodass Updates
  im Browser sofort greifen.

**Update**

    cd /var/www/server-admin && ./update.sh

Keine manuellen Schritte nötig: `update.sh` schreibt das gehärtete sudoers-Regelwerk mit.

### 🇬🇧 English

**Security audit: all findings fixed and re-verified.** This release closes
several paths by which a logged-in (including non-root) user, or an admin browser
abused via CSRF/XSS, could gain root on the host.

**Security**
- **WireGuard:** live peer changes (`wg set`) no longer go through a shell, and
  every field written into a tunnel config (keys, addresses, endpoint,
  AllowedIPs, PSK, client data) is strictly validated and rejected if it
  contains line breaks. A smuggled `PostUp = …` that `wg-quick` would run as root
  is no longer possible.
- **nginx:** editing a reverse-proxy entry now validates `ip`, `port` and
  `domains` before they are written into the site config, no more injection of
  arbitrary nginx directives (e.g. `alias /;` for arbitrary file reads).
- **UI (XSS):** all attacker-influenceable server data (log lines, banned IPs,
  peer/VM names, certificate fields, the `Host` header, …) is HTML-escaped before
  rendering. No more stored or DOM-based cross-site scripting.
- **sudo grants:** the root grants for `www-data` were narrowed from catch-all
  rules (`cp *`, `chmod *`, `chown *`, `iptables *`, `pvesh *`, `cat /etc/pve/*`)
  to exactly the commands and paths the panel uses, so compromising the web
  service no longer trivially yields root.
- **Installer/updater:** the sudo ruleset now has a single source
  (`helpers/sudoers-rules.sh`); `setup.sh` and `update.sh` generate the same
  `visudo`-validated file. `update.sh` fully regenerates it and **removes**
  stale, over-broad rules on existing installs (it used to only append, never
  remove).

**Improvements**
- **nginx site creation with live progress:** each stage (config written, site
  enabled, `nginx -t`, reload, SSL/certbot, delivery verified) is shown
  individually with its output.
- **Deep-linking via URL hash:** `/#network`, `#zfs`, `#system`, etc. open the
  right tab and load its data on page load.
- **Favicon** is now served from `/favicon.png` (instead of an inline data URI).
- **JS assets are versioned by file modification time**, so client updates take
  effect immediately.

**Upgrade**

    cd /var/www/server-admin && ./update.sh

No manual steps required: `update.sh` installs the hardened sudoers ruleset.

---

_Ältere Versionen / older versions: see [CHANGELOG.md](CHANGELOG.md)._
