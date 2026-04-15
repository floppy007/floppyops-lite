# Changelog

## v1.2.23 (2026-04-15)

### Improved
- **WireGuard cards now show boot persistence clearly at a glance**: active boot-start state is highlighted directly in the card header with a stronger `BOOT AN` badge and matching status button styling
- **WireGuard tunnels now support direct autostart toggling from the dashboard**: each tunnel card can enable or disable `wg-quick@...` boot activation without re-importing or recreating the config

## v1.2.20 (2026-04-15)

### Fixed
- **WireGuard import/export flow is now consistent for client use**: peer exports now generate single-client configs instead of dumping the whole server file, preserve the client address correctly, and keep enough metadata for later re-export of newly created peers
- **WireGuard import is more robust and clearer in the UI**: imported peers can be named directly, keep that name visible immediately, and no longer rewrite the suggested interface name from the uploaded filename
- **WireGuard import no longer fails on common host mismatches**: configs with `DNS = ...` are automatically softened when `resolvconf` is unavailable, avoiding broken starts on hosts without that tool
- **WireGuard tunnel deletion now works end-to-end from the dashboard**: the UI exposes tunnel removal, the backend deletes root-owned configs correctly, and setup/update keep the required sudoers rules in sync
- **Update/runtime plumbing is aligned with the latest WireGuard fixes**: current setup, update, and live UI behaviour now match each other instead of leaving repo and installed app on different logic paths

## v1.2.19 (2026-04-15)

### Fixed
- **WireGuard networks can now be deleted from the UI reliably**: the delete action now removes root-owned configs through the sudo-backed path instead of failing on plain `unlink()`
- **WireGuard runtime sudoers now include config removal**: setup/update keep the `/etc/wireguard/*.conf` delete permission in sync so tunnel cleanup works after upgrades
- **Client import keeps the default interface suggestion stable**: uploading a peer config no longer silently rewrites the suggested interface name from the filename
- **Imported peer names now show up immediately without a later edit**: the import path now writes the chosen name into the first `[Peer]` block as well as the interface block, matching what the UI reads back
- **WireGuard cards now expose full tunnel deletion in the UI**: complete WG networks can be removed directly from the dashboard with confirmation

## v1.2.18 (2026-04-15)

### Fixed
- **Imported peer configs can now get a readable name immediately**: the WireGuard import modal now includes a peer-name field and writes it into the imported config for later display
- **WireGuard import no longer dies on `DNS = ...` when `resolvconf` is missing**: the importer now disables the DNS line with a warning instead of saving a config that `wg-quick` cannot start on this host

## v1.2.17 (2026-04-15)

### Fixed
- **Peer export now preserves the client tunnel address correctly**: exported peer configs now include the original client `Address` instead of trying to infer it from routed `AllowedIPs`
- **Stored peer export metadata now keeps client address separately**: newly created peers retain enough data for later single-peer export without mangling interface addressing

## v1.2.16 (2026-04-15)

### Fixed
- **Peer export now targets the peer instead of the whole server config**: the peer-row `.conf` and `.sh` exports no longer dump the complete server-side WireGuard file
- **New peers keep client export metadata for later download**: when a peer is created through FloppyOps, its client-side private key, DNS, endpoint and routed networks are stored as internal comments so the exact peer config can be exported again later
- **Peer add/update writes use the current `wgWriteConf()` result format correctly**: the API no longer treats config writes as a plain boolean

## v1.2.15 (2026-04-15)

### Fixed
- **WireGuard import/save can now really write configs as `www-data`**: the host sudoers/runtime update now permits copying temporary `wgconf_*` files into `/etc/wireguard/` and applying the expected ownership and mode
- **WireGuard create/import now reports real write/start failures**: the API stops claiming success when the config was not written or `wg-quick@...` failed to start, and returns the backend command output instead

## v1.2.14 (2026-04-15)

### Fixed
- **App auto-update cron now runs the updater with valid shell quoting**: the generated cron command now builds the `update.sh --dir ...` call safely instead of producing a broken path expression
- **Manual web self-update now uses the same safe command builder**: the in-app update trigger now constructs the updater command consistently, avoiding path and quoting drift between manual and scheduled updates

## v1.2.13 (2026-04-13)

### Added
- **Public-access toggles in Security Check**: the Lite app can now switch public reachability for both the Lite web UI and the direct PVE WebUI port `8006` from the Security tab

### Fixed
- **PVE WebUI public toggle now writes valid firewall rules**: the `8006` toggle creates/removes proper TCP ACCEPT rules and reflects the effective state correctly
- **Lite app public toggle now edits the live Nginx vhost reliably**: the Security API detects the real Lite site file, inserts/removes the allow/deny block robustly and reloads Nginx after validation
- **Host scripts now preserve the new access controls**: `setup.sh` and `update.sh` now re-apply the needed sudoers rules for the Security toggles so fresh installs and future updates keep working

## v1.2.12 (2026-04-13)

### Fixed
- **Web self-update now reports real success/failure**: the updates API now evaluates the updater exit code instead of treating any shell output as success
- **Web self-update no longer dies on backup path permissions**: non-root update runs now write backups to `/var/tmp/floppyops-lite-update-backups` instead of failing on `/root`
- **Web self-update no longer breaks on PHP-FPM reload**: app-triggered updates defer the PHP-FPM reload into the background so the HTTP request can finish cleanly instead of returning `502 Bad Gateway`
- **Updater no longer dirties Git worktrees via file mode drift**: `pve-integration/install.sh` keeps its executable mode during update syncs

## v1.2.11 (2026-04-13)

### Fixed
- **Fail2ban and NAT checks stop spamming sudo mails**: runtime checks now guard missing tools/modules better and use the matching sudo command paths for Fail2ban and iptables
- **Repository repair now really switches to community repos**: the in-app repo fix disables enterprise repos and enables the free `pve-no-subscription` and matching Ceph community repos on `trixie`
- **Update actions no longer fail on empty POST CSRF**: the shared JS API helper now sends `_csrf` for every POST, including actions without a payload body
- **Manual system updates no longer collapse into HTML/502 responses**: `apt dist-upgrade` now runs as a background job with status polling instead of blocking PHP-FPM until the web request times out
- **Auto-update schedules can be saved from the web UI again**: cron files are now written through the sudo-backed root file path instead of failing silently as `www-data`

### Improved
- **Reboot hints show more context**: the updates panel can include package names from `/var/run/reboot-required.pkgs`
- **Updater keeps runtime sudoers fixes during future app upgrades**: `update.sh` now re-applies the newer sudoers lines for repo writes, cron writes, and NAT checks

## v1.2.9 (2026-04-12)

### Fixed
- **PAM login works reliably again**: the PHP PAM helper process now uses the correct pipe directions, fixing the live `Bad file descriptor` failure during Linux/PAM sign-in
- **Network tab no longer feels stuck on open**: Nginx now shows an immediate loading spinner while proxy sites are fetched
- **Network tab avoids unnecessary background work**: opening the grouped `Network` section now loads only the active sub-tab instead of always starting both Nginx and WireGuard paths
- **Hash restore for `#network` respects the active/default sub-tab**: restores now land on the intended Nginx view instead of jumping into WireGuard

### Improved
- **Updates panel UX cleaned up**: the dashboard updates card now opens the correct tab, app updates show a busy state, and update action labels stay consistent after reload/check/install flows
- **Updates panel localization improved**: visible update/repository status strings were moved out of hardcoded JS text into `lang.php`
- **Updates panel styling is less inline-heavy**: repeated card/banner/status layout styling now uses dedicated CSS classes instead of repeating large inline style blocks
- **Setup verification is stricter**: `setup.sh` now validates that `helpers/pam_auth.py` is present in the installed tree

## v1.2.8 (2026-04-11)

### Fixed
- **Live cache bust for updated WireGuard UI text**: bumped the app version so deployments fetch the fresh `wireguard.js` bundle instead of serving a stale CDN-cached copy
- **German UI text restored with umlauts**: reverted the accidental ASCII-only text downgrade in the visible Lite frontend strings

## v1.2.7 (2026-04-11)

### Added
- **LXC reachability helper in WireGuard**: the WireGuard tab now audits running LXC containers for missing return routes to tunnel networks and can apply a direct fix for ifupdown-based guests

### Fixed
- **WireGuard UI cache bust for live updates**: JS modules now include the app version in their script URL so browser/CDN caches pick up new frontend code reliably

## v1.2.6 (2026-04-11)

### Fixed
- **Updater repairs PAM login prerequisites completely**: `update.sh` now also ensures `python3-pam` is installed and re-adds the `sudoers` rule for the dedicated PAM helper, so existing Lite installs do not lose Linux login support after partial host changes

## v1.2.5 (2026-04-11)

### Fixed
- **App updates now use one backend path**: In-app updates and the app auto-update cron now both go through `update.sh`, so Git and non-Git installs use the same validated full-tree update flow
- **PHP-FPM reload is now explicit**: Update reloads now detect concrete `php*-fpm.service` units instead of relying on wildcard `systemctl` calls
- **PAM login no longer uses `su` shell piping**: Linux authentication now goes through a dedicated root-owned PAM helper via `sudo`, while the PVE/PAM realm split stays intact
- **Linux PAM login finalized**: PAM auth now uses a dedicated `floppyops-lite` PAM service, supports the host `PAM` Python module variant, strips accidental realm suffixes like `@pam`, and returns the helper error path correctly for explicit PAM logins
- **Setup and update keep PAM auth in sync**: `setup.sh` and `update.sh` now both install/update the dedicated PAM service file alongside the PAM helper

## v1.2.4 (2026-04-11)

### Improved
- Login page font loading: Removed external Google Fonts import and switched the login UI to local system font stacks only
- Safer Nginx system fixes: Forwarding, NDP proxy and NAT persistence now go through a shared system-change helper with backup creation, diff output, dry-run support and clearer error reporting

## v1.2.3 (2026-04-10)

### Improved
- **Nginx sites load instantly**: SSL certificate expiry is now loaded lazily via batch endpoint. Sites appear immediately, SSL badges update in background
- **Firewall VM/CT table loads instantly**: Basic VM list renders immediately, firewall details (status, rules, IPs, templates) load in background
- **WireGuard public IP cached**: `ifconfig.me` result cached for 5 minutes in Add Peer dialog

### Added
- **`nginx-ssl-batch` endpoint**: Batch SSL cert check for all sites
- **`fw-vm-list?quick=1` parameter**: Fast VM list without per-VM firewall queries

## v1.1.4 (2026-04-06)

### Added
- **Add Peer Wizard**: 2-step wizard to add new peers to existing tunnels (auto-generated keys, suggested IPs)
- **Peer Edit Modal**: Form-based peer settings (Name, Endpoint, AllowedIPs, PSK, Keepalive)
- **Config Import**: Import .conf files from other WireGuard servers (upload or paste)
- **Setup Script Generator**: Download .sh scripts for remote peers (installs WG, checks existing configs, starts tunnel)
- **Download Buttons**: .conf and .sh downloads per peer and in both wizards
- **Peer Info Display**: VPN IP, Peer Name, PSK (click to copy), Public Key per peer row
- **Tunnel Info Bar**: VPN subnet, gateway, port, public key, peer count per tunnel
- **Log Viewer**: Logs button per tunnel — journalctl + dmesg in modal with line count selector
- **Interface Settings Modal**: Form-based interface editing (Address, Port, PostUp/Down) — consistent with peer edit
- **Restart Banner**: Persistent notification when config was changed since last service start (survives page reload)
- **Auto-Refresh**: Peer status updates every 10 seconds (handshake, transfer, endpoint)
- **Firewall Integration**: Auto-add UDP port to PVE firewall when creating/importing tunnels

### Changed
- **Navigation restructured**: Dashboard | Security | Network | ZFS | Updates | Help
- **ZFS own top tab**: Moved out of System sub-tab for better visibility
- **System tab renamed to Updates**: Cleaner, dedicated updates management
- **VMs/CTs moved to Dashboard**: Compact table with Status, VMID, Name, Type, vCPU, RAM + Start/Stop/Restart buttons
- **Subscription status**: New dashboard tile showing Active/None + subscription level

### Improved
- Peers always visible (merged from config + live data, no restart needed to see new peers)
- Peer rows use CSS Grid for consistent alignment regardless of content
- Remove peer button per peer row with confirmation
- Config writes use sudo fallback when www-data lacks write permissions
- **Firewall template cards**: Compact single-line layout (icon + name + badge inline)

### Performance
- **pve-vms**: 15s response cache (pvesh calls ~2s each, was the main bottleneck)
- **fw-vm-list**: 30s response cache (3x pvesh per VM/CT)
- **zfs-status**: 5s response cache
- **Updates tab**: All 3 checks (repo, app, apt) load in parallel via Promise.all
- **Security tab**: All loads run in parallel
- **ZFS tab**: No longer loads PVE VMs, loads in ~160ms instead of ~2s

### Fixed
- JS syntax error from regex in template literal
- WG data not loading on page reload with #network hash
- ZFS "Pools & Datasets" button not highlighted on tab switch

---

## v1.1.3 (2026-04-05)

### Added
- **IPv6 Network Config**: IPv6 address + gateway fields in ZFS Snap Clone dialog
- **IPv6 NDP Proxy Check**: Security tab now checks if NDP proxy is enabled when needed, with one-click fix (permanent via sysctl.conf)
- **IPv6 in VM list**: Clone modal shows IPv6 addresses in network info

### Improved
- Security checks now cover IPv4 forwarding, IPv6 forwarding, and NDP proxy status
- Network section in clone dialog split into clear IPv4/IPv6 sections

---

## v1.1.2 (2026-04-04)

### Fixed
- System tab showing empty page due to broken HTML nesting (Network sub-panels were incorrectly placed inside System panel)

---

## v1.1.1 (2026-04-03)

- Initial public release
- Dashboard with live charts (CPU, RAM, Network, Disk I/O)
- Fail2ban management (jails, banned IPs, log viewer)
- Nginx reverse proxy (sites, SSL, diagnostics)
- WireGuard VPN tunnel management
- ZFS snapshots, pools, auto-snapshots
- VM/CT management (clone, start/stop, resize)
- Firewall templates (iptables presets)
- System updates (APT, repositories, auto-update)
- PVE & PAM authentication
- Dark theme, responsive, i18n (EN/DE)
