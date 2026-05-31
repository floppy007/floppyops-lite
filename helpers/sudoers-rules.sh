# shellcheck shell=bash
#
# FloppyOps Lite — kanonisches sudoers-Regelwerk (gemeinsame Quelle)
#
# Wird von setup.sh (Erstinstallation) UND update.sh (Update) eingebunden, damit
# beide DASSELBE, gehärtete Regelwerk erzeugen und es keine Drift gibt. Frühere
# Versionen pflegten die Regeln getrennt/additiv — dadurch blieben gefährliche
# Catch-all-Regeln (cp */chmod */chown */iptables */pvesh */cat /etc/pve/*) auf
# bestehenden Hosts erhalten. Diese Datei ist die einzige Wahrheit.
#
# Sicherheitsmodell: www-data darf NUR die unten gelisteten Befehle als root
# ausführen. Regeln sind so eng wie möglich (fester Pfad + eingeschränkte
# Argumente). KEINE Catch-alls aufnehmen — jede davon erlaubt einen trivialen
# www-data -> root-Ausbruch (chmod u+s, cp nach /etc/sudoers.d,
# pvesh create /access/users, iptables --modprobe …).
#
# Modulauswahl über Umgebungsvariablen (Default: alle aktiv — so verhält sich
# das Update wie bisher und entfernt nur die unsicheren Altregeln):
#   MOD_FAIL2BAN, MOD_NGINX, MOD_WIREGUARD, MOD_ZFS  (="true" | "false")

emit_sudoers_rules() {
    local mod_fail2ban="${MOD_FAIL2BAN:-true}"
    local mod_nginx="${MOD_NGINX:-true}"
    local mod_wireguard="${MOD_WIREGUARD:-true}"
    local mod_zfs="${MOD_ZFS:-true}"

    echo "# FloppyOps Lite Panel"
    echo "#"
    echo "# Generiert aus helpers/sudoers-rules.sh — NICHT von Hand erweitern."
    echo "# KEINE Catch-all-Regeln (cp */chmod */chown */iptables */pvesh */"
    echo "# cat /etc/pve/*) hinzufügen: jede erlaubt einen www-data -> root-Ausbruch."

    if [[ "$mod_fail2ban" == "true" ]]; then
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/fail2ban-client status *"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/fail2ban-client status"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/fail2ban-client set * unbanip *"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/tail -* /var/log/fail2ban.log"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart fail2ban"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl is-active fail2ban"
    fi
    if [[ "$mod_nginx" == "true" ]]; then
        echo "www-data ALL=(root) NOPASSWD: /usr/sbin/nginx -t"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl reload nginx"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start nginx"
        # certbot kann via --deploy-hook/--manual-auth-hook beliebige Befehle als
        # root ausführen; per sudo-Glob nicht abschaltbar. Nur der authentifizierte
        # Admin (CSRF-geschützt) erreicht diesen Pfad — bewusst akzeptiertes Risiko.
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/certbot *"
        echo "www-data ALL=(root) NOPASSWD: /usr/sbin/iptables -t nat -L POSTROUTING -n"
        echo "www-data ALL=(root) NOPASSWD: /usr/sbin/iptables -t nat -C POSTROUTING -s * -o * -j MASQUERADE"
        echo "www-data ALL=(root) NOPASSWD: /usr/sbin/iptables -t nat -A POSTROUTING -s * -o * -j MASQUERADE"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/cat /etc/nginx/*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/cp /tmp/nginx_* /etc/nginx/sites-available/*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/cp /tmp/nginx_* /etc/nginx/sites-enabled/*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/chmod 644 /etc/nginx/sites-available/*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/chmod 644 /etc/nginx/sites-enabled/*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/ln -sf /etc/nginx/sites-available/* /etc/nginx/sites-enabled/*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/rm -f /etc/nginx/sites-available/*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/rm -f /etc/nginx/sites-enabled/*"
        # IP-Forwarding / NAT-Bridge-Tuning (nginx-checks Fixes). sysctl-Befehle
        # haben feste Werte; sysctl.conf/interfaces werden gelesen, gesichert und
        # aus /tmp/ngx_write_* überschrieben — alles fest pfad-/wertgebunden.
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/cat /etc/sysctl.conf"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/cat /etc/network/interfaces"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/cp /etc/sysctl.conf /etc/sysctl.conf.bak-*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/cp /etc/network/interfaces /etc/network/interfaces.bak-*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/cp /tmp/ngx_write_* /etc/sysctl.conf"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/cp /tmp/ngx_write_* /etc/network/interfaces"
        echo "www-data ALL=(root) NOPASSWD: /usr/sbin/sysctl -w net.ipv4.ip_forward=1"
        echo "www-data ALL=(root) NOPASSWD: /usr/sbin/sysctl -w net.ipv6.conf.all.forwarding=1"
        echo "www-data ALL=(root) NOPASSWD: /usr/sbin/sysctl -w net.ipv6.conf.all.proxy_ndp=1"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/apt-get install -y certbot python3-certbot-nginx"
    fi
    if [[ "$mod_wireguard" == "true" ]]; then
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/wg show *"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/wg genkey"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/wg pubkey"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/wg genpsk"
        # wg set: Argumente (Key, AllowedIPs) werden im Code streng validiert.
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/wg set *"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/cp /tmp/wgconf_* /etc/wireguard/*"
        echo "www-data ALL=(root) NOPASSWD: /bin/chmod 0640 /etc/wireguard/*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/chown root\\:www-data /etc/wireguard/*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/rm -f /etc/wireguard/*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start wg-quick@*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl stop wg-quick@*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart wg-quick@*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl enable wg-quick@*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl disable wg-quick@*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl is-active wg-quick@*"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl show wg-quick@* *"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/journalctl -u wg-quick@* *"
    fi
    if [[ "$mod_zfs" == "true" ]]; then
        echo "www-data ALL=(root) NOPASSWD: /usr/sbin/zfs list *"
        echo "www-data ALL=(root) NOPASSWD: /usr/sbin/zfs snapshot *"
        echo "www-data ALL=(root) NOPASSWD: /usr/sbin/zfs destroy *"
        echo "www-data ALL=(root) NOPASSWD: /usr/sbin/zfs rollback *"
        echo "www-data ALL=(root) NOPASSWD: /usr/sbin/zfs clone *"
        echo "www-data ALL=(root) NOPASSWD: /usr/sbin/zfs set *"
        echo "www-data ALL=(root) NOPASSWD: /usr/sbin/zfs get *"
        echo "www-data ALL=(root) NOPASSWD: /usr/sbin/zpool list *"
        echo "www-data ALL=(root) NOPASSWD: /usr/bin/apt-get install -y zfs-auto-snapshot"
    fi
    # Security Check + VM/CT-Verwaltung (PVE via pvesh / pct)
    # pvesh set/create/delete sind auf /nodes/ und /cluster/firewall/ begrenzt,
    # damit www-data NICHT 'pvesh create /access/users' (PVE-Admin anlegen) o.ä.
    # ausführen kann. 'pvesh get' bleibt breit (nur Lesezugriff).
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/pvesh get *"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/pvesh set /nodes/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/pvesh set /cluster/firewall/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/pvesh create /nodes/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/pvesh create /cluster/firewall/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/pvesh delete /nodes/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/pvesh delete /cluster/firewall/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/sbin/pct list"
    echo "www-data ALL=(root) NOPASSWD: /usr/sbin/pct config *"
    # pct exec führt beliebige Befehle als root im Container aus (LXC-Routen-Fix).
    # Nicht per Glob einschränkbar; nur der authentifizierte Admin erreicht es.
    echo "www-data ALL=(root) NOPASSWD: /usr/sbin/pct exec *"
    # Guest-Configs lesen/kopieren (VM-Klon). Auf die Gast-Verzeichnisse begrenzt,
    # damit /etc/pve/priv/ (Cluster-Secrets) NICHT lesbar/überschreibbar ist.
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/cat /etc/pve/lxc/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/cat /etc/pve/qemu-server/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/cp /etc/pve/lxc/* /etc/pve/lxc/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/cp /etc/pve/qemu-server/* /etc/pve/qemu-server/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/cp /tmp/* /etc/pve/lxc/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/cp /tmp/* /etc/pve/qemu-server/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/ss -tlnpH"
    # Self-Update + System Updates
    echo "www-data ALL=(root) NOPASSWD: /usr/local/libexec/floppyops-lite/pam_auth.py --user *"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl list-units --type=service --all php*-fpm.service --no-legend"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl reload php*-fpm.service"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart php*-fpm.service"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/cp /tmp/floppyops-lite_repo_* /etc/apt/sources.list.d/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/cp /tmp/floppyops-lite_file_* /etc/cron.d/*"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/chmod 0644 /etc/cron.d/floppyops-lite-update"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/chmod 0644 /etc/cron.d/floppyops-lite-app-update"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/rm -f /etc/cron.d/floppyops-lite-update"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/rm -f /etc/cron.d/floppyops-lite-app-update"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/rm -f /etc/cron.daily/floppyops-lite-update"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/rm -f /etc/cron.daily/floppyops-lite-app-update"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/apt-get update"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/apt-get dist-upgrade *"
    echo "www-data ALL=(root) NOPASSWD: /usr/bin/apt-get autoremove *"
}

# Schreibt das Regelwerk atomar nach /etc/sudoers.d/server-admin, prüft es mit
# visudo und installiert es nur bei Erfolg (440 root:root). $1 = optionaler
# Runner-Prefix für Root-Rechte (z.B. "sudo -n"); leer, wenn bereits root.
install_sudoers_file() {
    local runner="${1:-}"
    local target="/etc/sudoers.d/server-admin"
    local tmp
    tmp="$(mktemp /tmp/floppyops-sudoers.XXXXXX)"
    emit_sudoers_rules > "$tmp"
    if ! visudo -cf "$tmp" >/dev/null 2>&1; then
        rm -f "$tmp"
        echo "FEHLER: erzeugte sudoers-Regeln sind ungültig" >&2
        return 1
    fi
    $runner install -o root -g root -m 0440 "$tmp" "$target"
    local rc=$?
    rm -f "$tmp"
    return $rc
}
