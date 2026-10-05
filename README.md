<div align="center">

<img src="src/usr/local/emhttp/plugins/fanctrlplus2/images/fanctrlplus2-static.svg" alt="FanCtrl Plus 2 logo" width="150">

<h1>FanCtrl Plus 2</h1>

<p><strong>Temperature-driven PWM fan control for <a href="https://unraid.net/">Unraid</a>.</strong></p>

<p>
  <a href="https://github.com/andrebrait/fanctrlplus/actions/workflows/test.yml"><img src="https://img.shields.io/github/actions/workflow/status/andrebrait/fanctrlplus/test.yml?branch=main&label=tests" alt="Tests"></a>
  <a href="https://github.com/andrebrait/fanctrlplus/releases"><img src="https://img.shields.io/github/v/release/andrebrait/fanctrlplus?label=release&color=blue" alt="Latest release"></a>
  <img src="https://img.shields.io/badge/Unraid-6.9%2B-e8543f" alt="Supported Unraid versions">
  <a href="https://github.com/andrebrait/fanctrlplus/releases"><img src="https://img.shields.io/github/downloads/andrebrait/fanctrlplus/total?color=blueviolet" alt="Downloads"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/andrebrait/fanctrlplus?color=green" alt="License: GPL-2.0-only"></a>
</p>

</div>

**FanCtrl Plus 2** is an Unraid plugin that provides automatic fan control based on the temperatures of HDDs, NVMe drives, Unassigned Devices, and optionally the CPU.
Each fan configuration can monitor specific drives or the CPU, define a temperature range, and scale fan speed automatically using a linear control algorithm.  
Configuration is done through a user-friendly interface, with custom thresholds, intervals, and labels available per fan.

## ✨ Features

- Full-featured Web UI for configuration and monitoring
- Supports temporary fan configuration with safe validation and custom naming
- Automatically starts with the Unraid array for hands-free operation
- Set custom thresholds and intervals per fan, from 5 seconds to 1 hour
- Control multiple PWM fans independently
- Monitor temps from array disks, NVMe, unassigned devices, and optionally the CPU
- Monitor auxiliary hwmon, storcli, NVIDIA GPU, and Mellanox NIC (`mget_temp`) temperature sensors, or any sensor of your own through a custom script
- Use independent temperature ranges for multiple disk groups on one fan
- Uses a linear control algorithm to smoothly adjust fan speed (PWM) based on the current temperature (disk or CPU) between your defined low/high values
- Identify and label PWM controllers to match physical fans easily
- Lays the fan configurations out side by side, wrapping to the next row, so a wide screen shows several at once and a phone shows one
- Dashboard tile and system integration
- Optional FCP Airflow Dashboard tile, similar to Unraid’s built-in Airflow tile but enhanced with support for custom fan labels
- Reorder fan configurations with a button per direction, on a desktop or a phone. The new order is saved and reflected in both the UI and Dashboard.

---

## 🔧 Custom Fork Installation

Open Unraid **Plugins → Install Plugin** and use:

```text
https://raw.githubusercontent.com/andrebrait/fanctrlplus/main/plugin/fanctrlplus2.plg
```

FanCtrl Plus 2 has a separate plugin identity and cannot run beside upstream
FanCtrl Plus because both may control the same PWM devices. To migrate:

1. Leave upstream FanCtrl Plus installed and install FanCtrl Plus 2 once. The
   installer copies existing configurations, then stops before installing.
2. Uninstall upstream FanCtrl Plus.
3. Install FanCtrl Plus 2 again using the URL above.

Do not reinstall or run upstream FanCtrl Plus while FanCtrl Plus 2 is installed.

## Custom temperature sensors

Any sensor the plugin does not know about can be added as a readable script with
a valid shebang. Place the file in:

```text
/boot/config/plugins/fanctrlplus2/sensors.d/
```

Each readable script in that directory becomes one auxiliary sensor, named
after the file, selectable per fan alongside the built-in sources. The contract is:

- print the temperature in degrees Celsius and nothing else (a fractional
  reading is truncated to whole degrees);
- on any error, print nothing and exit with a non-zero status.

A sensor that errors, prints anything other than a temperature, or takes longer
than 5 seconds is skipped for that round of checks only; the fan keeps being
driven by its other sources, and the script is tried again on the next round.
Errors are reported by the exit status, never by a sentinel reading: a script
that prints `0` has reported 0 °C.

Readings below −100 °C or above 300 °C are rejected as implausible; valid negative
readings are floored at 0 °C. A script that reads 0 °C when the settings page is
opened is taken for an unpopulated sensor and is not offered in the list.

```sh
#!/bin/bash
# /boot/config/plugins/fanctrlplus2/sensors.d/ambient
temp=$(some-tool --read-ambient 2>/dev/null) || exit 1
[[ "$temp" =~ ^[0-9]+$ ]] || exit 1
echo "$temp"
```

The flash drive does not need executable permissions. The plugin makes a private
executable copy on runtime storage, preserving the shebang and using the file's
current contents on each read. The copy is removed after the reading finishes.
Use absolute paths for helper files: `$0` refers to the runtime copy, not the
original flash-resident script.

## Configuration backup and restore

Use **General Settings → Export backup** to download saved fan configurations,
PWM labels, dashboard switches, and fan ordering as a JSON file. Unsaved edits,
custom sensor scripts, runtime history, and logs are not included.

**Restore backup** asks for confirmation before replacing saved settings. The
entire file is validated before changes are made, and unrelated files such as
`sensors.d/` remain untouched. Fan control retains its prior running or stopped
state; a failed replacement restores the previous managed configuration files.
Reloading after a successful restore shows the restored settings.

Store backups somewhere other than the Unraid flash drive. Back up custom sensor
scripts separately. Restored USB identities are resolved by the existing device
migration at service startup; a missing or ambiguous recorded controller is not
assigned to another device.

## Fan identity and USB port changes

For USB controllers that expose a unique serial number, fan labels and
configurations are identified by VID:PID, serial number, interface, and PWM
channel. The plugin records this identity when a label or configuration is
saved, and automatically upgrades existing entries at service startup while
their controller can still be identified. Labels use an encoded `usb:` key in
`pwm_labels.cfg`; `controller_identity` stores the same key in each fan
configuration, or a path binding when no unique serial is available. The
runtime `controller` path is resolved again whenever the service starts.

This preserves assignments when a controller moves to another USB port, even
when an identical model occupies the old port. Missing or ambiguous identities
(including duplicate serial numbers for the same channel) remain saved but
unassigned until they can be resolved; the plugin does not guess another device.

Controllers without a serial number, controllers whose serial is already
duplicated when first saved, and older entries whose serial has not yet been
recorded use the same-port path migration. Their existing port assignments
remain separate. Keep those controllers on the same port. If an unrecorded
controller has already moved, reassign it once so its serial can be saved.
Restart the service after a driver reload or port move. If multiple
configurations resolve to one channel, the already-current assignment wins;
blocked bindings remain saved for recovery when the conflicting assignment
is removed.
Saving a configuration without a resolved controller, including a rename,
retains its binding. Selecting a different controller replaces the binding.
Clearing a label also removes its dormant USB identity entry, so it cannot
reappear after serial ambiguity is resolved.
The identity identifies the controller's header, not the physical fan attached
to it; replacing the fan does not change the saved assignment.

Support / Issues
- https://github.com/andrebrait/fanctrlplus/issues

## License and provenance

FanCtrl Plus 2 is free software licensed under the GNU General Public
License, version 2 only (`GPL-2.0-only`). See [LICENSE](LICENSE) for the
complete license text and [NOTICE](NOTICE) for the copyright, attribution,
and source-lineage record.

The plugin is derived from FanCtrl Plus by ck9393, which is itself derived
from Dynamix System AutoFan by Bergware International and its contributors.
Bundled third-party components retain their own compatible licenses; see
[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
