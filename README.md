# **FanCtrl Plus 2**

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
- Dashboard tile and system integration
- Optional FCP Airflow Dashboard tile, similar to Unraid’s built-in Airflow tile but enhanced with support for custom fan labels
- Drag and drop fan configuration boxes to reorder them as you like. The new order is saved and reflected in both the UI and Dashboard.

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

Any sensor the plugin does not know about can be added as a script. Drop an
executable file in:

```text
/boot/config/plugins/fanctrlplus2/sensors.d/
```

Each executable in that directory becomes one auxiliary sensor, named after the
file, selectable per fan alongside the built-in sources. The contract is:

- print the temperature in degrees Celsius and nothing else (a fractional
  reading is truncated to whole degrees);
- on any error, print nothing and exit with a non-zero status.

A sensor that errors, prints anything other than a temperature, or takes longer
than 5 seconds is skipped for that round of checks only; the fan keeps being
driven by its other sources, and the script is tried again on the next round.
Errors are reported by the exit status, never by a sentinel reading: a script
that prints `0` has reported 0 °C.

Readings are clamped to 0–200 °C, as they are for every other source, so a
value outside that range drives the fan at the corresponding end of its curve
rather than being discarded. A script that reads 0 °C when the settings page
is opened is taken for a dead sensor and is not offered in the list.

```sh
#!/bin/bash
# /boot/config/plugins/fanctrlplus2/sensors.d/ambient
temp=$(some-tool --read-ambient 2>/dev/null) || exit 1
[[ "$temp" =~ ^[0-9]+$ ]] || exit 1
echo "$temp"
```

Remember to make the file executable (`chmod +x`).

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
