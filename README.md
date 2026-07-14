# **FanCtrl Plus 2**

**FanCtrl Plus 2** is an Unraid plugin that provides automatic fan control based on the temperatures of HDDs, NVMe drives, Unassigned Devices, and optionally the CPU.
Each fan configuration can monitor specific drives or the CPU, define a temperature range, and scale fan speed automatically using a linear control algorithm.  
Configuration is done through a user-friendly interface, with custom thresholds, intervals, and labels available per fan.

## ✨ Features

- Full-featured Web UI for configuration and monitoring
- Supports temporary fan configuration with safe validation and custom naming
- Automatically starts with the Unraid array for hands-free operation
- Set custom thresholds and intervals per fan
- Control multiple PWM fans independently
- Monitor temps from array disks, NVMe, unassigned devices, and optionally the CPU
- Monitor auxiliary hwmon, storcli, and NVIDIA GPU temperature sensors
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

Support / Issues
- https://github.com/andrebrait/fanctrlplus/issues
- Original plugin: https://github.com/ck9393/fanctrlplus

- Support original FanCtrl Plus author ck9393:

<p align="left">
  <a href="https://www.paypal.com/paypalme/cck9393" target="_blank">
    <img src="https://raw.githubusercontent.com/ck9393/fanctrlplus/main/.github/assets/donate.png" alt="Donate" width="90">
  </a>
</p>
