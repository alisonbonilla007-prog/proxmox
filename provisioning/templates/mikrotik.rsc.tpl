# MESH Cloud onboarding for {{TENANT_NAME}} ({{SLUG}})
# RouterOS v7+. Paste this into  /terminal  (or Files > drag .rsc > "Run Script").
# The router generates its own WireGuard private key; only its PUBLIC key is
# shown at the end — copy it back into the MESH Cloud onboarding page to finish.

# 1) WireGuard tunnel to the MESH Cloud hub ----------------------------------
/interface/wireguard
add name=mesh-wg listen-port=13231 comment="MESH Cloud"
/interface/wireguard/peers
add interface=mesh-wg \
    public-key="{{SERVER_PUBKEY}}" \
    endpoint-address=[:pick "{{HUB_ENDPOINT}}" 0 [:find "{{HUB_ENDPOINT}}" ":"]] \
    endpoint-port=[:pick "{{HUB_ENDPOINT}}" ([:find "{{HUB_ENDPOINT}}" ":"] + 1) [:len "{{HUB_ENDPOINT}}"]] \
    allowed-address={{ALLOWED_IPS}} \
    persistent-keepalive=25s
/ip/address
add interface=mesh-wg address={{ASSIGNED_IP}}/16 comment="MESH Cloud tunnel"

# 2) RADIUS over the tunnel (auth + accounting on the hub) -------------------
/radius
add service=hotspot,login address={{RADIUS_IP}} secret="{{RADIUS_SECRET}}" \
    src-address={{ASSIGNED_IP}} comment="MESH Cloud RADIUS"
/radius/incoming
set accept=yes

# 3) Hotspot on the LAN port (test clients land here) -----------------------
# Clients on ether2 get DHCP on 192.168.88.0/24 and hit the captive portal.
# >>> Change every "ether2" below if your hotspot LAN is a different port. <<<
/ip/pool
add name=mesh-hs ranges=192.168.88.10-192.168.88.254
/ip/address
add interface=ether2 address=192.168.88.1/24 comment="MESH hotspot LAN"
/ip/dhcp-server
add name=mesh-hs interface=ether2 address-pool=mesh-hs lease-time=1h disabled=no
/ip/dhcp-server/network
add address=192.168.88.0/24 gateway=192.168.88.1 dns-server=192.168.88.1 comment="MESH hotspot"
/ip/dns
set allow-remote-requests=yes
# NAT hotspot clients out to the internet via the uplink (assumed ether1)
/ip/firewall/nat
add chain=srcnat src-address=192.168.88.0/24 out-interface=ether1 action=masquerade comment="MESH hotspot internet"
/ip/hotspot
add name=mesh interface=ether2 address-pool=mesh-hs profile=default disabled=no

# 3b) Default hotspot profile authenticates against MESH RADIUS --------------
/ip/hotspot/profile
set [find default=yes] use-radius=yes \
    login-by=http-chap,http-pap,mac-cookie \
    html-directory=hotspot
# Walled garden: let unauthenticated clients reach the portal + M-Pesa + fonts
/ip/hotspot/walled-garden
add dst-host={{PORTAL_HOST}} comment="MESH portal"
add dst-host=*.safaricom.co.ke comment="M-Pesa"
add dst-host=sandbox.safaricom.co.ke comment="M-Pesa sandbox"
add dst-host=api.fontshare.com comment="fonts"
add dst-host=*.fontshare.com comment="fonts"
/ip/hotspot/walled-garden/ip
add dst-host={{PORTAL_HOST}} action=accept

# 4) Monitoring: read-only REST user + service on the tunnel ----------------
# MESH Cloud polls /rest/system/resource and /interface over the tunnel for
# CPU, uptime and throughput. Read-only, reachable only via the WG interface.
/user/group
add name=mesh-monitor policy=read,api,rest-api,winbox comment="MESH Cloud monitoring"
/user
add name={{MON_USER}} group=mesh-monitor password="{{MON_PASS}}" comment="MESH Cloud (read-only)"
/ip/service
set www-ssl address={{RADIUS_IP}}/32 disabled=no
set api-ssl address={{RADIUS_IP}}/32 disabled=no

# 5) Print this router's PUBLIC key — paste it into MESH Cloud onboarding ----
:put "============================================================"
:put ("MESH Cloud: copy this PUBLIC KEY into the onboarding page:")
:put [/interface/wireguard/get mesh-wg public-key]
:put "============================================================"
