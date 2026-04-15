# FloppyOps Lite

**Open-source server admin panel for single-host Proxmox VE setups**

FloppyOps Lite is a lightweight web UI that runs directly on your Proxmox VE host and brings the day-to-day terminal work for a dedicated server into one focused panel: updates, firewalling, reverse proxying, WireGuard, ZFS, Fail2ban, and VM/CT operations.

Designed for **one rented server, one admin surface, no cluster overhead**.

[![License](https://img.shields.io/badge/License-MIT-1f6feb)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.x-777bb4)](#requirements)
[![Proxmox VE](https://img.shields.io/badge/Proxmox_VE-8%2B-e57000)](#requirements)
[![Version](https://img.shields.io/badge/Version-v1.2.20-0a7f5a)](#whats-new-in-v120)
[![Issues](https://img.shields.io/badge/GitHub-Issues-111111?logo=github)](https://github.com/floppy007/floppyops-lite/issues)

**[Deutsch](#deutsch)** | **[English](#english)**

Current version: **`v1.2.20`**

Quick links:
- [Installation](#installation)
- [Updating](#updating)
- [Features](#features)
- [Deutsch](#deutsch)

---

<a id="english"></a>

## Why FloppyOps Lite?

When you rent a dedicated server with Proxmox VE, too many routine tasks still fall back to SSH:

- **Updates & repos**: keep both the host and the app itself current without hand-rolling shell commands
- **WireGuard**: create tunnels, add peers, import/export configs, inspect logs, and manage peer workflows from the UI
- **Nginx & SSL**: run reverse proxies, issue certificates, and check certificate health
- **Security**: review exposed ports, manage host firewall rules, and keep Fail2ban visible
- **ZFS & VM/CT tooling**: snapshot, rollback, clone, and adjust guest hardware/network settings

FloppyOps Lite gives you a direct, self-hosted control surface for exactly those jobs, without adding an external SaaS layer or cluster management complexity.

## What's new in v1.2.20?

- **WireGuard client flow is much cleaner**: peer export now produces real client configs instead of dumping the full server-side file
- **Peer re-export works for newly created peers**: client address and related metadata are preserved so later exports stay usable
- **WireGuard import is safer on real hosts**: imports can be named directly, keep the visible peer name immediately, and handle missing `resolvconf` more gracefully
- **Tunnel deletion is now end-to-end**: complete WireGuard networks can be removed from the UI, including the root-owned config cleanup path
- **Update/runtime plumbing is aligned**: current app logic, setup, update, and sudoers rules are in sync for the latest WireGuard and updater fixes

## Features

### Dashboard
- Server status: Hostname, Uptime, CPU %, RAM, Disk
- **Live Charts** - CPU, RAM, Network I/O, Disk I/O (4s refresh, Chart.js)
- Fail2ban stats + Nginx site count + Updates count
- **Subscription status** tile
- Auto-refresh every 4 seconds

### VMs & Containers (on Dashboard)
- Compact table: Status, VMID, Name, Type, vCPU, RAM + Start/Stop/Restart buttons
- **IP Column** - Colored dots: yellow = public IP, gray = internal IP
- **Template Assignments** - Shows which firewall template is assigned to each VM/CT
- **Clone** with Full/Linked Clone option
- Adjust hardware: CPU, RAM, Swap, Onboot
- Network: Keep, Customize (IPv4/IPv6 Address, Gateway, Bridge, DNS), or Disconnect
- Auto-start after clone

### Firewall Templates
- **18 built-in templates**: Mailcow, Webserver, Database, Proxmox, Docker, DNS, WireGuard, Virtualmin Web, Virtualmin Web+Mail, Nginx Proxy, PostgreSQL, Redis, Elasticsearch, Minecraft, TeamSpeak, Nextcloud, Gitea/GitLab, Monitoring
- **Custom Templates** - Create, save and reuse your own rule sets
- **Editable rules** - Ports and sources can be customized before applying
- **Duplicate detection** - Already existing rules are not created twice
- **VM/CT Firewall Management** - View/toggle firewall per VM/CT, see status, policy, rule count and assigned template
- **Two firewall levels**: PVE Host Firewall (Security Check) for the host, VM/CT Firewall (Templates) for individual machines
- **Compact card layout** - icon + name + rule count inline

### Fail2ban
- All jails with status (banned IPs, failed logins)
- **Unban** button per IP
- Config editor (jail.local) with save & restart
- Ban log viewer

### Nginx Reverse Proxy
- All proxy sites with domain, target, SSL status + expiry date
- **New site** creation (multi-domain, SSL via Certbot)
- Edit site (config editor) and delete
- **SSL Renew** button per site
- **SSL Health Check** *(new in v1.1.0)* - Automated check of all sites:
  - DNS A + AAAA record verification
  - SSL certificate validity and expiry
  - Certificate-domain match check
  - IPv4/IPv6 consistency (same certificate on both protocols)
  - **ipv6only=on detection** with 1-click fix
- **Cloudflare Proxy Support** - Optional during setup:
  - Automatic `real_ip` config for correct client IP behind CF Proxy
  - IP whitelists, logs and Fail2ban work correctly with proxied domains
- **Setup Guide** with live system checks:
  - IPv4/IPv6 Forwarding (with fix button)
  - IPv6 NDP Proxy check (with fix button - needed for IPv6 between bridges)
  - NAT/Masquerading (with activate button)
  - **Safer system changes** - forwarding/NDP/NAT fixes now support backup, diff output and dry-run mode before writing persistent config
  - Internal bridge detection
  - Nginx + Certbot status

### ZFS Storage
- **Pools & Datasets** - utilization, health, fragmentation
- **Snapshots** - grouped by CT/VM with name, sortable, filterable
  - **Rollback** - restore to previous state
  - **Clone** - create new CT/VM from snapshot (full hardware: CPU, RAM, Swap, Onboot + IPv4/IPv6 network customization)
  - Only 5 most recent shown, rest collapsible
- **Auto-Snapshots** - zfs-auto-snapshot installation + retention config
  - Per-dataset toggle
  - **Editable retention** per interval (frequent, hourly, daily, weekly, monthly)
  - Default: 4 frequent, 24 hourly, 31 daily, 8 weekly, 12 monthly (~1 year)

### WireGuard VPN
- **Tunnel overview** with peer info (VPN IP, endpoint, handshake, transfer)
- **Tunnel info bar** - VPN subnet, gateway, port, public key, peer count
- **Live traffic graph** (Chart.js, 5s interval)
- **Auto-refresh** - peer status updates every 10 seconds
- Start / Stop / Restart per tunnel
- **New tunnel wizard** (3 steps):
  1. Basics: Interface, Port, IP, Keys (auto-generated)
  2. Peer: Endpoint, Public Key, Allowed IPs, PSK
  3. Preview + remote config for copying
- **Add Peer Wizard** - 2-step wizard for existing tunnels (auto-generated keys, suggested IPs)
- **Peer Edit Modal** - form-based editing (Name, Endpoint, AllowedIPs, PSK, Keepalive)
- **Config Import** - import .conf files from other WireGuard servers (upload or paste)
- **Setup Script Generator** - download .sh scripts for remote peers (installs WG, checks existing configs, starts tunnel)
- **Download buttons** - .conf and .sh per peer and in wizards
- **Interface Settings** - form-based editing (Address, Port, PostUp/Down)
- **Log Viewer** - journalctl + dmesg per tunnel in modal
- **Restart banner** - persistent notification when config changed since last service start
- **Firewall Integration** - auto-add UDP port to PVE firewall when creating/importing tunnels
- **Firewall rules wizard** - NAT, Forwarding, IP-Forward as checkboxes

### Security Check
- **Port Scanner** - all listening ports with risk classification (critical/high/medium/low)
- External vs. local-only detection
- **PVE Firewall** - Datacenter + Node status, one-click enable (auto-adds SSH + WebUI safety rules)
- **Firewall Rules** - view, add (modal), delete existing rules
- **One-Click Block** - block risky ports (rpcbind, MySQL, Redis, etc.) with a single click
- **Default Rules** - apply recommended ruleset with selectable checkboxes
- **IPv6 NDP Proxy Check** - detects if NDP proxy is needed, one-click fix (permanent via sysctl.conf)

### Authentication
- **Realm Selection** - Dropdown like PVE: Proxmox VE (PVE) or Linux (PAM)
- **PVE Auth** - Login with Proxmox VE users (root@pam, etc.)
- **PAM Auth** - Linux system users
- CSRF tokens on all forms
- **IP Whitelist** - configured during setup (auto-detects SSH client IP)

### PVE Dashboard Integration
- **Toolbar Button** - FloppyOps button in PVE's top toolbar
- **SSL Access** - Port 8443 with PVE certificate, auto HTTP->HTTPS redirect
- **apt Hook** - auto-restores integration after PVE updates

### Updates & Repositories
- **System Updates** - check + install (apt dist-upgrade) with one click
- **Repository Management** - Enterprise / No-Subscription repos with toggle switches
  - Auto-detect PVE 8 (bookworm, .list) and PVE 9 (trixie, .sources DEB822)
  - Warnings: Enterprise without subscription, both active, no PVE repo
  - Subscription status display
- **App Self-Update** - version check against GitHub, one-click update (git pull or download)
- **Auto-Update** - system + app auto-update with configurable schedule (day + time)
- **Reboot detection** - banner when system restart required

### Navigation
- **6 tabs**: Dashboard, Security, Network, ZFS, Updates, Help
- **Dashboard** includes VMs/CTs table + subscription status
- **Security** groups: Firewall Templates + Security Check + Fail2ban
- **Network** groups: Nginx Reverse Proxy + WireGuard VPN
- **Help** page with search and collapsible sections
- **Spinners** on all loading states

### More
- Deutsch / English - language toggle in topbar
- Responsive layout (mobile-friendly)
- Dark theme with accent color
- Login page uses local system font stacks only (no external font request)
- Tab persists after reload (URL hash)
- Bug report + Feature request links (GitHub Issues)

## Requirements

- **Proxmox VE 8+** on a dedicated server (Hetzner, OVH, Netcup, etc.)
- **Root access** (SSH or console)
- Internet connection (for package installation)

> PHP, Nginx and all other dependencies are installed automatically by the setup script.

## Installation

**1. Clone the repository on your PVE host:**

```bash
git clone https://github.com/floppy007/floppyops-lite.git
cd floppyops-lite
```

**2. Run the setup script:**

```bash
bash setup.sh
```

Or with a domain for automatic SSL:

```bash
bash setup.sh --domain admin.example.com
```

**3. The setup wizard will guide you through:**

| Step | What happens |
|------|-------------|
| **Language** | Choose English or Deutsch |
| **Modules** | Select which modules to install (Fail2ban, Nginx Proxy, ZFS, WireGuard) - or install all |
| **Dependencies** | Installs PHP-FPM, Nginx, and selected module packages automatically |
| **IP Whitelist** | Restrict panel access to your IP or subnet (recommended). Your SSH client IP is detected automatically |
| **Cloudflare** | Optional: Configure `real_ip` for correct client IPs behind Cloudflare Proxy |
| **SSL** | If `--domain` is set: automatic Let's Encrypt certificate via Certbot |
| **PVE Integration** | Adds a FloppyOps button to the PVE toolbar + SSL access on port 8443 |

**4. Open the panel in your browser:**

| Access | URL |
|--------|-----|
| **HTTP** | `http://YOUR-SERVER-IP` |
| **SSL (PVE cert)** | `https://YOUR-SERVER-IP:8443` |
| **Custom domain** | `https://admin.example.com` (if `--domain` was used) |
| **PVE Toolbar** | Click the FloppyOps button in your PVE web interface |

Login with your **PVE root credentials** (root / your PVE password).

### Setup Options

| Option | Description |
|--------|-------------|
| `--domain FQDN` | Domain for the panel - enables nginx vHost + SSL via Certbot |
| `--dir /path` | Install directory (default: `/var/www/server-admin`) |
| `--no-ssl` | Skip Let's Encrypt SSL certificate |

### Updating

The panel has a built-in self-update feature (Updates tab). You can also update manually:

```bash
cd /var/www/server-admin
bash update.sh
```

`bash update.sh` is the recommended update path for both Git-based and non-Git installs.

- Git installs: pulls the latest code, validates the release tree, creates a backup, and synchronizes the full app tree
- Non-Git installs: use `bash update.sh --from /path/to/floppyops-lite`
- `config.php` and `data/` are preserved
- For Git-based installs, the worktree must be clean
- The in-app update button uses the same `update.sh` backend

## Architecture

```
index.php           → Auth, Router, HTML/CSS Layout
api/                → PHP API modules (one per feature)
  dashboard.php     → System stats (CPU, RAM, Disk, Network)
  fail2ban.php      → Jails, Logs, Config
  nginx.php         → Reverse Proxy, Sites, SSL
  vms.php           → PVE VMs & Containers
  zfs.php           → Pools, Datasets, Snapshots
  wireguard.php     → VPN Tunnel Management
  updates.php       → App + System Updates, Repos
  security.php      → Port Scan, Host Firewall
  firewall.php      → VM/CT Firewall Templates
js/                 → JavaScript modules (one per feature)
  core.js           → Navigation, API helper, Toast, Modals
  dashboard.js      → Live charts + stats
  fail2ban.js       → Jails, Unban
  nginx.js          → Sites, SSL Health
  vms.js            → VM/CT list, Clone, Control
  zfs.js            → Pools, Snapshots, Auto-Snapshot
  wireguard.js      → Tunnels, Wizard, Traffic Graph
  security.js       → Port Scan, Host Firewall
  firewall.js       → Templates, VM/CT Rules
  updates.js        → App/System Updates, Repos
config.php          → Credentials + settings (not in Git)
config.example.php  → Template for config.php
lang.php            → Translations (DE/EN)
setup.sh            → Automated setup script
update.sh           → Update script (git pull or --from)
```

Modular PHP app - no framework, no database, no external dependencies (except Chart.js for the traffic graph).

## License

MIT License - free to use and modify.

**Footer attribution must remain** (see [LICENSE](LICENSE)).

---

<a id="deutsch"></a>

## Deutsch

### Warum FloppyOps Lite?

Wenn du einen Dedicated Server mit Proxmox VE mietest, landest du für viele Alltagsaufgaben immer noch im Terminal:

- **Updates & Repos**: Host und App aktuell halten, ohne ständig Shell-Kommandos von Hand abzufeuern
- **WireGuard**: Tunnel anlegen, Peers verwalten, Configs importieren/exportieren und Logs prüfen
- **Nginx & SSL**: Reverse Proxies betreiben, Zertifikate ausrollen und SSL-Status sauber im Blick behalten
- **Security**: offene Ports, Host-Firewall und Fail2ban zentral sehen und bedienen
- **ZFS & VM/CT-Workflows**: Snapshots, Rollback, Clone und Hardware-/Netzwerk-Anpassungen direkt im Panel

FloppyOps Lite bringt genau diese Aufgaben in eine direkte, selbst gehostete Web-Oberfläche auf deinem Proxmox-Host, ohne externe Plattform und ohne Cluster-Overhead.

### Was ist neu in v1.2.20?

- **WireGuard-Client-Flow deutlich sauberer**: Peer-Exporte erzeugen jetzt echte Client-Configs statt die komplette Serverdatei auszugeben
- **Neue Peers bleiben später erneut exportierbar**: Client-Adresse und die nötigen Export-Metadaten werden für neu angelegte Peers mitgespeichert
- **WireGuard-Import ist robuster**: Importierte Peers können direkt benannt werden, zeigen den Namen sofort sichtbar an und brechen nicht mehr einfach an fehlendem `resolvconf`
- **Tunnel-Löschen funktioniert jetzt komplett aus der UI**: ganze WireGuard-Netze können über das Dashboard inkl. Config-Cleanup entfernt werden
- **Setup, Update und Runtime-Regeln sind wieder konsistent**: die aktuellen Fixes fuer WireGuard und den Update-Pfad laufen jetzt ueber denselben sauberen Stand

### Features

| Bereich | Funktionen |
|---------|-----------|
| **Dashboard** | Uptime, CPU, RAM, Disk, Live-Charts, Fail2ban Stats, Nginx Sites, Subscription-Status, VMs/CTs Tabelle |
| **VMs/CTs** | Status, Start/Stop/Restart, Clone (Full/Linked), Hardware anpassen, IPv4/IPv6 Netzwerk |
| **Firewall Templates** | 18 Built-in + Custom Templates, editierbare Ports/Sources, Duplikat-Erkennung, VM/CT Firewall-Tabelle |
| **Fail2ban** | Jails, gebannte IPs, Unban, Config-Editor, Ban-Log |
| **Nginx Proxy** | Sites, SSL-Ablauf/Renew, Multi-Domain, SSL Health Check, Cloudflare Proxy Support, Setup-Guide |
| **ZFS** | Eigener Tab - Pools, Datasets, Snapshots, Rollback, Clone (mit IPv6), Auto-Snapshots |
| **WireGuard** | Tunnel-Info, Live Traffic, Peer Wizard, Config Import, Setup Scripts, Downloads, Log Viewer, Auto-Refresh, Firewall-Integration |
| **Security** | Port-Scanner, PVE Firewall, Regeln verwalten, One-Click Block, Standard-Regeln, IPv6 NDP Proxy Check |
| **Navigation** | 6 Tabs (Dashboard, Security, Network, ZFS, Updates, Help), Spinner, Hilfe mit Suche |
| **Auth** | Realm-Dropdown (PVE/PAM), CSRF, IP-Whitelist (im Setup konfigurierbar) |
| **PVE Integration** | Toolbar-Button, SSL Port 8443, apt-Hook |
| **i18n** | Deutsch + Englisch |

### Voraussetzungen

- **Proxmox VE 8+** auf einem Dedicated Server (Hetzner, OVH, Netcup, etc.)
- **Root-Zugriff** (SSH oder Konsole)
- Internetverbindung (für Paketinstallation)

> PHP, Nginx und alle weiteren Abhängigkeiten werden automatisch vom Setup-Script installiert.

### Installation

**1. Repository auf dem PVE-Host klonen:**

```bash
git clone https://github.com/floppy007/floppyops-lite.git
cd floppyops-lite
```

**2. Setup-Script starten:**

```bash
bash setup.sh
```

Oder mit Domain für automatisches SSL:

```bash
bash setup.sh --domain admin.example.com
```

**3. Der Setup-Wizard führt durch die Konfiguration:**

| Schritt | Was passiert |
|---------|-------------|
| **Sprache** | Deutsch oder English wählen |
| **Module** | Welche Module installiert werden sollen (Fail2ban, Nginx Proxy, ZFS, WireGuard) - oder alle |
| **Abhängigkeiten** | Installiert PHP-FPM, Nginx und ausgewählte Modul-Pakete automatisch |
| **IP-Whitelist** | Panel-Zugriff auf deine IP oder Subnetz beschränken (empfohlen). Deine SSH-Client-IP wird automatisch erkannt |
| **Cloudflare** | Optional: `real_ip` Konfiguration für korrekte Client-IPs hinter Cloudflare Proxy |
| **SSL** | Bei `--domain`: automatisches Let's Encrypt Zertifikat via Certbot |
| **PVE-Integration** | FloppyOps-Button in der PVE-Toolbar + SSL-Zugang auf Port 8443 |

**4. Panel im Browser öffnen:**

| Zugang | URL |
|--------|-----|
| **HTTP** | `http://DEINE-SERVER-IP` |
| **SSL (PVE-Zertifikat)** | `https://DEINE-SERVER-IP:8443` |
| **Eigene Domain** | `https://admin.example.com` (wenn `--domain` gesetzt) |
| **PVE-Toolbar** | FloppyOps-Button in der PVE-Weboberfläche |

Login mit **PVE root-Zugangsdaten** (root / dein PVE-Passwort).

### Setup-Optionen

| Option | Beschreibung |
|--------|-------------|
| `--domain FQDN` | Domain für das Panel - aktiviert nginx vHost + SSL via Certbot |
| `--dir /path` | Installationsverzeichnis (Standard: `/var/www/server-admin`) |
| `--no-ssl` | Let's Encrypt SSL-Zertifikat überspringen |

### Aktualisieren

Das Panel hat eine eingebaute Self-Update-Funktion (Updates-Tab). Manuell geht es auch:

```bash
cd /var/www/server-admin
bash update.sh
```

`bash update.sh` ist der empfohlene Update-Weg für Git- und Nicht-Git-Installationen.

- Git-Installationen: holen den neuesten Code, validieren den Release-Dateisatz, erstellen ein Backup und synchronisieren den vollständigen App-Baum
- Nicht-Git-Installationen: `bash update.sh --from /pfad/zu/floppyops-lite`
- `config.php` und `data/` bleiben erhalten
- Bei Git-Installationen muss der Worktree sauber sein
- Der In-App-Update-Button nutzt denselben `update.sh`-Backend-Pfad

---

## Links

- **FloppyOps PVE Manager** (Full Version): [floppyops.com](https://floppyops.com)
- **Author**: Florian Hesse - [Comnic-IT](https://comnic-it.de)
