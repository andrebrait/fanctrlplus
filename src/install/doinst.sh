#!/bin/sh
chmod 0755 /usr/local/emhttp/plugins/fanctrlplus2/scripts/*.sh 2>/dev/null || true
chmod 0755 /usr/local/emhttp/plugins/fanctrlplus2/scripts/rc.fanctrlplus2 2>/dev/null || true
ln -sf /usr/local/emhttp/plugins/fanctrlplus2/scripts/rc.fanctrlplus2 /etc/rc.d/rc.fanctrlplus2
mkdir -p /boot/config/plugins/fanctrlplus2/sensors.d 2>/dev/null || true
