#!/usr/bin/env bash
# 00-host-create-vms.sh — run ON THE PROXMOX HOST (as root).
# Builds three cloud-init Debian 12 VMs: edge, hub, data.
#
#   cp cluster.env.example cluster.env && nano cluster.env
#   bash 00-host-create-vms.sh
#
# Idempotent-ish: it will skip a VM whose VMID already exists.
set -euo pipefail
cd "$(dirname "$0")"
[ -f cluster.env ] || { echo "Create cluster.env from cluster.env.example first."; exit 1; }
# shellcheck disable=SC1091
source ./cluster.env

command -v qm >/dev/null || { echo "qm not found — run this on the Proxmox host."; exit 1; }

IMG="/var/lib/vz/template/iso/debian-12-genericcloud-amd64.qcow2"
if [ ! -f "$IMG" ]; then
    echo "==> Downloading Debian 12 cloud image"
    mkdir -p "$(dirname "$IMG")"
    wget -O "$IMG" "$DEBIAN_IMAGE_URL"
fi

# Build cloud-init: ssh key OR password, plus enable the qemu agent + root ssh.
SSHKEY_ARG=""
if [ -n "${VM_SSH_PUBKEY:-}" ]; then
    echo "$VM_SSH_PUBKEY" > /tmp/mesh_ci.pub
    SSHKEY_ARG="--sshkeys /tmp/mesh_ci.pub"
fi

create_vm () {
    local vmid=$1 name=$2 cores=$3 ram=$4 disk=$5 ip=$6
    if qm status "$vmid" >/dev/null 2>&1; then
        echo "==> VM $vmid ($name) already exists — skipping"
        return
    fi
    echo "==> Creating VM $vmid : $name  ($ip)"
    qm create "$vmid" --name "$name" --cores "$cores" --memory "$ram" \
        --net0 "virtio,bridge=${PVE_BRIDGE}" --agent enabled=1 --ostype l26

    # import the cloud image as the boot disk
    qm importdisk "$vmid" "$IMG" "$PVE_STORAGE" >/dev/null
    qm set "$vmid" --scsihw virtio-scsi-pci --scsi0 "${PVE_STORAGE}:vm-${vmid}-disk-0"
    qm set "$vmid" --ide2 "${PVE_STORAGE}:cloudinit"
    qm set "$vmid" --boot order=scsi0 --serial0 socket --vga serial0
    qm disk resize "$vmid" scsi0 "${disk}G"

    # cloud-init: user, network (static), dns
    qm set "$vmid" --ciuser "$VM_USER" --cipassword "$VM_PASSWORD" $SSHKEY_ARG
    qm set "$vmid" --ipconfig0 "ip=${ip}/${NETMASK_CIDR},gw=${GATEWAY}"
    qm set "$vmid" --nameserver "1.1.1.1 8.8.8.8"

    qm start "$vmid"
    echo "    started. login: ${VM_USER}@${ip}"
}

create_vm "$VMID_DATA" "mesh-data" "$DATA_CORES" "$DATA_RAM" "$DATA_DISK" "$DATA_IP"
create_vm "$VMID_HUB"  "mesh-hub"  "$HUB_CORES"  "$HUB_RAM"  "$HUB_DISK"  "$HUB_IP"
create_vm "$VMID_EDGE" "mesh-edge" "$EDGE_CORES" "$EDGE_RAM" "$EDGE_DISK" "$EDGE_IP"

cat <<DONE

================================================================
 VMs created. Wait ~60s for cloud-init, then SSH into each and run
 its installer (copy cluster.env along with the SAAS/ repo):

   DATA :  ssh ${VM_USER}@${DATA_IP}   -> sudo bash 10-install-data.sh
   HUB  :  ssh ${VM_USER}@${HUB_IP}    -> sudo bash 20-install-hub.sh
   EDGE :  ssh ${VM_USER}@${EDGE_IP}   -> sudo bash 30-install-edge.sh

 Run them in that order (data -> hub -> edge).
 Tip: snapshot each VM in Proxmox before running its installer.
================================================================
DONE
