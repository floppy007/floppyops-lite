# Changelog

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
