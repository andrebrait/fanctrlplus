<?php
$label_file = "/boot/config/plugins/fanctrlplus2/pwm_labels.cfg";
$pwm_labels = [];
if (is_file($label_file)) {
  foreach (file($label_file, FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match('/^(.+?)=(.+)$/', $line, $m)) {
      $pwm_labels[$m[1]] = $m[2];
    }
  }
}

// Build the list of disk groups for a fan cfg: [{name, disks:[...], low, high}, ...].
// disk_group_count>0 -> read disk_group_{g}_* keys. Otherwise fall back to ONE group
// synthesized from the legacy flat disks/low/high fields (pre-group configs, or a
// brand-new never-saved fan block) -- keeps every existing config rendering exactly
// as before until the user actually saves it in the new group shape.
function fcp_build_disk_groups($cfg) {
  $count = isset($cfg['disk_group_count']) ? intval($cfg['disk_group_count']) : 0;
  if ($count > 0) {
    $groups = [];
    for ($g = 0; $g < $count; $g++) {
      $groups[] = [
        'name'  => $cfg["disk_group_{$g}_name"] ?? '',
        'disks' => array_filter(explode(',', $cfg["disk_group_{$g}_disks"] ?? '')),
        'low'   => isset($cfg["disk_group_{$g}_low"]) && is_numeric($cfg["disk_group_{$g}_low"]) ? intval($cfg["disk_group_{$g}_low"]) : 40,
        'high'  => isset($cfg["disk_group_{$g}_high"]) && is_numeric($cfg["disk_group_{$g}_high"]) ? intval($cfg["disk_group_{$g}_high"]) : 60,
      ];
    }
    return $groups;
  }
  return [[
    'name'  => '',
    'disks' => array_filter(explode(',', $cfg['disks'] ?? '')),
    'low'   => isset($cfg['low']) && is_numeric($cfg['low']) ? intval($cfg['low']) : 40,
    'high'  => isset($cfg['high']) && is_numeric($cfg['high']) ? intval($cfg['high']) : 60,
  ]];
}

// Render one disk-group row for fan $i, group $g. $grp = {name, disks:[...], low, high}.
// Single source of truth for the row markup -- both the initial page render (via
// fcp_build_disk_groups()) and the "Add Disk Group" AJAX button (FanctrlLogic.php
// op=newdiskgroup, an empty $grp) call this, so there is never a second template to
// keep in sync.
function render_disk_group_row($i, $g, $grp, $disks) {
  ob_start();
  ?>
  <div class="disk-group-row" data-fan-index="<?=$i?>" data-group="<?=$g?>">
    <table class="fcp-w-100">
      <tr>
        <td class="fcp-help-cursor" title="A short label for this group of devices, e.g. HDD, SATA SSD, NVMe.">Group Name:</td>
        <td>
          <div class="disk-group-heading">
            <input type="text"
                  id="disk_group_name_input_<?=$i?>_<?=$g?>"
                  name="disk_group_name[<?=$i?>][<?=$g?>]"
                  class="disk-group-name-input"
                  value="<?=htmlspecialchars($grp['name'])?>"
                  placeholder="e.g. HDD">
            <button type="button" class="remove-disk-group-btn" data-fan-index="<?=$i?>" data-group="<?=$g?>" title="Remove this disk group">
              <i class="fa fa-trash"></i>
            </button>
          </div>
        </td>
      </tr>
      <tr>
        <td class="fcp-help-cursor" title="Select disks, NVMe drives, or other block devices belonging to this group.">Include Disk(s):</td>
        <td>
          <select class="disk-select fcp-w-300" name="disk_group_disks[<?=$i?>][<?=$g?>][]" multiple>
            <?php foreach ($disks as $group => $entries): ?>
              <optgroup label="<?=htmlspecialchars($group)?>">
                <?php foreach ($entries as $disk):
                  $aliases = $disk['aliases'] ?? [$disk['id']];
                  $sel = array_intersect($aliases, $grp['disks']) ? 'selected' : '';
                ?>
                  <option value="<?=htmlspecialchars($disk['id'], ENT_QUOTES)?>"
                          <?=$sel?>
                          data-name="<?=htmlspecialchars($disk['name'], ENT_QUOTES)?>"
                          data-description="<?=htmlspecialchars($disk['description'], ENT_QUOTES)?>"
                          data-icon="<?=htmlspecialchars($disk['icon'], ENT_QUOTES)?>"
                          data-type="<?=htmlspecialchars($disk['type'], ENT_QUOTES)?>"
                          title="<?=htmlspecialchars($disk['title'], ENT_QUOTES)?>"><?=htmlspecialchars($disk['label'])?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </td>
      </tr>
      <tr>
        <td class="fcp-help-cursor" title="Fan runs at minimum speed at or below Low Temp, and maximum speed at or above High Temp for this group's hottest selected disk.">Group Temperature Range:</td>
        <td>
          <div class="fcp-range-grid">
            <input type="text"
                  id="low_temp_input_<?=$i?>_<?=$g?>"
                  name="disk_group_low[<?=$i?>][<?=$g?>]"
                  class="low-temp-input fcp-input-fullleft"
                  inputmode="numeric"
                  value="<?=$grp['low']?>°C"
                  title="Low Temp: <?=$grp['low']?>°C"
                  placeholder="Low °C">

            <span class="fcp-center">~</span>

            <input type="text"
                  id="high_temp_input_<?=$i?>_<?=$g?>"
                  name="disk_group_high[<?=$i?>][<?=$g?>]"
                  class="high-temp-input fcp-input-fullleft"
                  inputmode="numeric"
                  value="<?=$grp['high']?>°C"
                  title="High Temp: <?=$grp['high']?>°C"
                  placeholder="High °C">
          </div>
        </td>
      </tr>
    </table>
  </div>
  <?php
  $html = ob_get_contents();
  ob_end_clean();
  return $html;
}

function render_fan_block($cfg, $i, $pwms, $disks, $pwm_labels, $cpu_sensors, $aux_sensors = []) {
  // Use 40% and 100% as the PWM defaults when values are missing.
  $pwm_raw = isset($cfg['pwm']) && is_numeric($cfg['pwm']) ? $cfg['pwm'] : 102;
  $max_raw = isset($cfg['max']) && is_numeric($cfg['max']) ? $cfg['max'] : 255;

  $pwm_pct = round($pwm_raw * 100 / 255) . '%';
  $max_pct = round($max_raw * 100 / 255) . '%';

  $disk_groups = fcp_build_disk_groups($cfg);

  $idle_abs = isset($cfg['idle']) && is_numeric($cfg['idle']) ? (int)$cfg['idle'] : 0;
  $idle_pct = round($idle_abs * 100 / 255) . '%';

  ob_start();
  ?>
  <div class="fan-block" data-index="<?=$i?>" data-file="<?=htmlspecialchars($cfg['file'])?>">
    <input type="hidden" name="#file[<?=$i?>]" value="<?=htmlspecialchars($cfg['file'])?>" class="cfg-file">

    <fieldset class="fan-fieldset">
      <div class="fcp-fan-icon-wrap">
        <div class="fan-svg-container fcp-abs-fill">
          <div class="fcp-abs-fill-help"></div>
          <svg class="fcp-fan-icon" id="fan-icon-<?=$i?>" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg">
            <defs>
              <linearGradient id="flameGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="5%" stop-color="#FFD700"/>
                <stop offset="25%" stop-color="#FFA500"/>
                <stop offset="50%" stop-color="#FF8C00"/>
                <stop offset="75%" stop-color="#FF4500"/>
                <stop offset="100%" stop-color="#B22222"/>
              </linearGradient>
            </defs>
        
            <g class="rotor">
              <!-- fan blades -->
              <path fill="url(#flameGradient)" d="M176.713,229.639c5.603-16.892,16.465-31.389,30.628-41.571c-14.778-34.253-22.268-74.165-20.636-112.788 c0.217-5.095-4.279-13.455-15.648-8.54c-22.522,9.728-42.142,24.48-59.949,40.872c-17.008,15.667-20.853,40.637-7.96,56.168 C124.507,189.491,149.096,213.274,176.713,229.639z"/>
              <path fill="url(#flameGradient)" d="M290.516,179.908c22.286-29.938,53.094-56.375,87.366-74.264c4.534-2.367,9.52-10.436-0.435-17.843 c-19.674-14.634-42.268-24.253-65.352-31.47c-22.086-6.909-45.623,2.249-52.623,21.198c-11.605,31.334-19.892,64.536-20.254,96.632 C256.644,170.561,274.614,172.728,290.516,179.908z"/>
              <path fill="url(#flameGradient)" d="M412.281,169.754c-32.949,5.63-65.842,15.041-93.822,30.772c11.841,13.3,18.949,29.956,20.69,47.319 c37.064,4.324,75.362,17.798,107.983,38.524c4.316,2.738,13.799,3.029,15.232-9.302c2.847-24.354-0.108-48.724-5.403-72.334 C451.884,182.157,432.191,166.345,412.281,169.754z"/>
              <path fill="url(#flameGradient)" d="M335.287,282.361c-5.603,16.881-16.464,31.38-30.627,41.56c14.779,34.254,22.267,74.165,20.635,112.789 c-0.217,5.095,4.28,13.455,15.667,8.54c22.504-9.729,42.142-24.48,59.93-40.872c17.008-15.667,20.853-40.637,7.96-56.168 C387.511,322.508,362.904,298.717,335.287,282.361z"/>
              <path fill="url(#flameGradient)" d="M221.501,332.091c-22.267,29.93-53.075,56.367-87.348,74.264c-4.533,2.358-9.519,10.427,0.435,17.834 c19.675,14.634,42.269,24.253,65.352,31.471c22.086,6.908,45.623-2.249,52.623-21.198c11.605-31.334,19.892-64.527,20.254-96.632 C255.392,341.43,237.404,339.263,221.501,332.091z"/>
              <path fill="url(#flameGradient)" d="M172.85,264.146c-37.064-4.326-75.362-17.798-107.982-38.525c-4.316-2.738-13.8-3.028-15.233,9.303 c-2.846,24.352,0.109,48.724,5.422,72.333c5.059,22.576,24.752,38.388,44.663,34.979c32.948-5.631,65.842-15.042,93.82-30.772 C181.699,298.164,174.591,281.509,172.85,264.146z"/>
            </g>
        
            <!-- fan hub -->
            <path class="hub" fill="var(--hub-color)" d="M255.991,195.503c-33.402,0-60.475,27.091-60.475,60.492c0,33.411,27.073,60.493,60.475,60.493 c33.419,0,60.51-27.082,60.51-60.493C316.502,222.594,289.411,195.503,255.991,195.503z"/>
        
            <!-- frame -->
            <path class="frame" fill="var(--frame-color)" d="M463.017,0H49.001C21.928,0,0.005,21.932,0.005,48.987v414.016C0.005,490.059,21.928,512,49.001,512h414.016 c27.055,0,48.978-21.941,48.978-48.996V48.987C511.995,21.932,490.073,0,463.017,0z M463.017,31.706 c9.539,0,17.281,7.743,17.281,17.282c0,9.547-7.742,17.28-17.281,17.28c-9.556,0-17.299-7.734-17.299-17.28 C445.718,39.448,453.461,31.706,463.017,31.706z M49.001,31.706c9.538,0,17.281,7.743,17.281,17.282 c0,9.556-7.743,17.28-17.281,17.28c-9.556,0-17.299-7.724-17.299-17.28C31.702,39.448,39.445,31.706,49.001,31.706z M48.983,480.284c-9.538,0-17.281-7.734-17.281-17.281s7.743-17.281,17.281-17.281c9.556,0,17.299,7.734,17.299,17.281 S58.539,480.284,48.983,480.284z M463.017,480.284c-9.556,0-17.299-7.734-17.299-17.281c0-9.538,7.743-17.281,17.299-17.281 c9.539,0,17.281,7.743,17.281,17.281C480.298,472.55,472.556,480.284,463.017,480.284z M255.991,489.324 c-128.855,0-233.32-104.466-233.32-233.33c0-128.854,104.466-233.319,233.32-233.319c128.873,0,233.338,104.465,233.338,233.319 C489.329,384.858,384.864,489.324,255.991,489.324z"/>
          </svg>
        </div>
            <span class="drag-handle" ><i class="fa fa-reorder"></i></span>
      </div> 

      <!-- Fan controls inside each fan block. -->
      <div class="fan-tools fcp-fan-tools">
        <button type="button" class="show-chart-btn" onclick="showFanChart(this)" title="Preview this fan's speed curve based on current Disk/CPU settings">
          <i class="fa fa-line-chart" style= "color: var(--blue-800); font:"></i> Chart
        </button>
        <button type="button" class="delete-btn" title="Delete this fan configuration">Delete</button>
      </div>

      <table class="fcp-w-100">
        <!-- Custom Name -->
        <tr>
          <td class="fcp-help-cursor" 
              title="Enter a unique name for this fan configuration. Avoid spaces or special characters.">
            Custom Name
          </td>
          <td>
            <div>
              <input type="text"
                    name="custom[<?=$i?>]"
                    class="custom-name-input"
                    value="<?=htmlspecialchars($cfg['custom'] ?? '')?>"
                    placeholder="Required (e.g. HDDBay)"
                    required>
            </div>
          </td>
        </tr>

        <!-- Fan Control Dropdown -->
        <tr>
          <td class="fcp-help-cursor" title="Enable or disable this fan controller">Fan Control:</td>
          <td>
            <select name="service[<?=$i?>]" class="fcp-enable-select">
              <option value="0" <?=($cfg['service'] ?? '') == '0' ? 'selected' : ''?>>Disabled</option>
              <option value="1" <?=($cfg['service'] ?? '') == '1' ? 'selected' : ''?>>Enabled</option>
            </select>
          </td>
        </tr>



        <!-- PWM Controller -->
        <tr>
          <td class="fcp-help-cursor" title="Each fan corresponds to a PWM controller (pwm1, pwm2, etc). Select the one controlling this fan. You can use the Identify section below to locate and label each fan.">PWM Controller:</td>
          <td>
            <select name="controller[<?=$i?>]" class="pwm-controller">
              <option value="">-- Select PWM Controller --</option>
              <?php foreach ($pwms as $pwm): 
                $label = $pwm_labels[$pwm['sensor']] ?? '';
                $display = $pwm['chip'] . ' - ' . $pwm['name'];
                if ($label) $display .= '（' . htmlspecialchars($label) . '）';
              ?>
                <option value="<?=$pwm['sensor']?>" <?=($cfg['controller'] ?? '') == $pwm['sensor'] ? 'selected' : ''?>>
                  <?= $display ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>

        <!-- Fan Speed Range -->
        <tr>
          <td class="fcp-help-cursor" title="Set the minimum and maximum fan speed (0–100%). % will be automatically converted to PWM. Hover to see actual values.">Fan Speed Range:</td>
          <td>
            <div class="fcp-range-grid">

              <!-- Minimum. -->
              <input type="text"
                    id="pwm_percent_input_<?=$i?>"
                    name="pwm_percent[<?=$i?>]"
                    inputmode="numeric"
                    class="fcp-input-fullleft"
                    value="<?=$pwm_pct?>"
                    title="Minimum speed: <?=$pwm_pct?> = <?=htmlspecialchars($pwm_raw)?> PWM"
                    placeholder="Min %">


              <!-- Range separator. -->
              <span class="fcp-center">~</span>

              <!-- Maximum. -->
              <input type="text"
                    id="max_percent_input_<?=$i?>"
                    name="max_percent[<?=$i?>]"
                    inputmode="numeric"
                    class="fcp-input-fullleft"
                    value="<?=$max_pct?>"
                    title="Maximum speed: <?=$max_pct?> = <?=htmlspecialchars($max_raw)?> PWM"
                    placeholder="Max %">
            </div>
          </td>
        </tr>

        <tr>
          <td class="fcp-help-cursor"
              title="Fan speed used when there is no temperature source (all HDDs are spun down and CPU monitoring is not enabled).&#10;Must be ≤ the Min value in Fan Speed Range.&#10;Default 0% = completely stopped.">
            Fan Speed on Idle:
          </td>
          <td>
            <input type="text"
                  id="idle_percent_input_<?=$i?>"
                  name="idle_percent[<?=$i?>]"
                  inputmode="numeric"
                  class="fcp-input-idle"
                  value="<?=$idle_pct?>"
                  title="Idle speed: <?=$idle_pct?> = <?=htmlspecialchars($idle_abs)?> PWM"
                  placeholder="Idle %">
          </td>
        </tr>

        <!-- Interval -->
        <tr>
          <td class="fcp-help-cursor" title="Check temperature and adjust fan speed every X minutes.">Interval:</td>
          <td>
            <input type="text"
                  id="interval_input_<?=$i?>"
                  name="interval[<?=$i?>]"
                  class="interval-input fcp-interval-input" 
                  inputmode="numeric"
                  value="<?=htmlspecialchars(($cfg['interval'] ?? '') . ' min')?>"
                  placeholder="Recommended: 1–5 min">

            <span class="fanctrlplus2-interval-refresh fcp-runnow"
                  title="Manual Run: Read current temperature and set fan speed immediately"
                  data-label="<?=htmlspecialchars($cfg['custom'] ?? '')?>">
              <span class="fa fa-refresh fcp-fs-13"></span> Run Now
            </span>
          </td>
        </tr>

        <tr><td colspan="2" class="subhead">Disk Temperature Settings</td></tr>

        <!-- Disk Groups: each group has its own disk selection and its own
             Low/High temperature range. The fan reacts to whichever group's
             computed PWM (driven by ITS hottest selected disk) is highest --
             lets e.g. HDDs, SATA SSDs, and NVMe SSDs each get their own range
             on the same fan. -->
        <tr>
          <td colspan="2">
            <div class="disk-groups" id="disk-groups-<?=$i?>">
              <?php foreach ($disk_groups as $g => $grp): ?>
                <?= render_disk_group_row($i, $g, $grp, $disks) ?>
              <?php endforeach; ?>
            </div>
            <button type="button" class="add-disk-group-btn" data-fan-index="<?=$i?>" title="Add another disk group with its own temperature range">
              <i class="fa fa-plus"></i> Add Disk Group
            </button>
          </td>
        </tr>

        <tr><td colspan="2" class="subhead">CPU Temperature Settings</td></tr>

        <!-- CPU Temp Monitoring Dropdown -->
        <tr>
          <td class="fcp-help-cursor" title="Enable or disable monitoring CPU temperature for this fan.">CPU Temp Monitor:</td>
          <td>
            <select id="cpu-enable-<?=$i?>" name="cpu_enable[<?=$i?>]" class="fcp-enable-select" onchange="handleCpuEnableChange(this, <?=$i?>);">
              <option value="0" <?=($cfg['cpu_enable'] ?? '') != '1' ? 'selected' : ''?>>Disabled</option>
              <option value="1" <?=($cfg['cpu_enable'] ?? '') == '1' ? 'selected' : ''?>>Enabled</option>
            </select>
          </td>
        </tr>

        <!-- CPU Sensor -->
        <tr class="cpu-control cpu-control-<?=$i?>">
          <td class="cpu-label fcp-help-cursor" title="Automatically selected the most reliable CPU temperature sensor. Change only if necessary.">CPU Sensor:</td>
          <td>
            <select name="cpu_sensor[<?=$i?>]" class="cpu-input fcp-w-300" <?=($cfg['cpu_enable'] ?? '') != '1' ? 'disabled' : ''?>>
              <?php foreach ($cpu_sensors as $path => $label): ?>
                <option value="<?=htmlspecialchars($path)?>" <?=($cfg['cpu_sensor'] ?? '') == $path ? 'selected' : ''?>><?=htmlspecialchars($label)?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>

        <!-- CPU Temp Range -->
        <tr class="cpu-control cpu-control-<?=$i?>">
          <td class="cpu-label fcp-help-cursor" title="Fan runs at minimum speed at or below Low Temp, and maximum speed at or above High Temp. See chart for details.">CPU Temperature Range:</td>
          <td>
            <div class="fcp-range-grid">
              <input type="text"
                    id="cpu_low_temp_input_<?=$i?>"
                    name="cpu_min_temp[<?=$i?>]"
                    class="cpu-input fcp-input-fullleft"
                    inputmode="numeric"
                    value="<?=htmlspecialchars(($cfg['cpu_min_temp'] ?? '') . '°C')?>"
                    title="Low Temp: <?=intval($cfg['cpu_min_temp'] ?? 50)?>°C"
                    placeholder="Low °C"
                    <?=($cfg['cpu_enable'] ?? '') != '1' ? 'disabled' : ''?>>

              <span class="fcp-center">~</span>

              <input type="text"
                    id="cpu_high_temp_input_<?=$i?>"
                    name="cpu_max_temp[<?=$i?>]"
                    class="cpu-input fcp-input-fullleft"
                    inputmode="numeric"
                    value="<?=htmlspecialchars(($cfg['cpu_max_temp'] ?? '') . '°C')?>"
                    title="High Temp: <?=intval($cfg['cpu_max_temp'] ?? 75)?>°C"
                    placeholder="High °C"
                    <?=($cfg['cpu_enable'] ?? '') != '1' ? 'disabled' : ''?>>
            </div>
          </td>
        </tr>

        <tr><td colspan="2" class="subhead">Auxiliary Sensor Settings</td></tr>

        <!-- Aux Temp Monitoring Dropdown -->
        <tr>
          <td class="fcp-help-cursor" title="Enable or disable monitoring an auxiliary temperature sensor (e.g. network card, chipset, VRM) for this fan.">Aux Temp Monitor:</td>
          <td>
            <select id="aux-enable-<?=$i?>" name="aux_enable[<?=$i?>]" class="fcp-enable-select" onchange="handleAuxEnableChange(this, <?=$i?>);">
              <option value="0" <?=($cfg['aux_enable'] ?? '') != '1' ? 'selected' : ''?>>Disabled</option>
              <option value="1" <?=($cfg['aux_enable'] ?? '') == '1' ? 'selected' : ''?>>Enabled</option>
            </select>
          </td>
        </tr>

        <!-- Aux Sensor(s) -->
        <tr class="aux-control aux-control-<?=$i?>">
          <td class="aux-label fcp-help-cursor" title="Select one or more auxiliary temperature sensors to monitor. The highest temperature across all selected sensors is used. Lists non-CPU, non-NVMe hwmon sensors, plus storcli/nvidia-smi if available.">Include Sensor(s):</td>
          <td>
            <?php $aux_selected = array_filter(explode(',', $cfg['aux_sensor'] ?? '')); ?>
            <select class="aux-select aux-input fcp-w-300" name="aux_sensor[<?=$i?>][]" multiple <?=($cfg['aux_enable'] ?? '') != '1' ? 'disabled' : ''?>>
              <?php foreach ($aux_sensors as $group => $entries): ?>
                <optgroup label="<?=htmlspecialchars($group)?>">
                  <?php foreach ($entries as $sensor): ?>
                    <option value="<?=htmlspecialchars($sensor['path'])?>" <?=in_array($sensor['path'], $aux_selected) ? 'selected' : ''?>><?=htmlspecialchars($sensor['label'])?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>

        <!-- Aux Temp Range -->
        <tr class="aux-control aux-control-<?=$i?>">
          <td class="aux-label fcp-help-cursor" title="Fan runs at minimum speed at or below Low Temp, and maximum speed at or above High Temp. See chart for details.">Aux Temperature Range:</td>
          <td>
            <div class="fcp-range-grid">
              <input type="text"
                    id="aux_low_temp_input_<?=$i?>"
                    name="aux_min_temp[<?=$i?>]"
                    class="aux-input fcp-input-fullleft"
                    inputmode="numeric"
                    value="<?=htmlspecialchars(($cfg['aux_min_temp'] ?? '') . '°C')?>"
                    title="Low Temp: <?=intval($cfg['aux_min_temp'] ?? 40)?>°C"
                    placeholder="Low °C"
                    <?=($cfg['aux_enable'] ?? '') != '1' ? 'disabled' : ''?>>

              <span class="fcp-center">~</span>

              <input type="text"
                    id="aux_high_temp_input_<?=$i?>"
                    name="aux_max_temp[<?=$i?>]"
                    class="aux-input fcp-input-fullleft"
                    inputmode="numeric"
                    value="<?=htmlspecialchars(($cfg['aux_max_temp'] ?? '') . '°C')?>"
                    title="High Temp: <?=intval($cfg['aux_max_temp'] ?? 70)?>°C"
                    placeholder="High °C"
                    <?=($cfg['aux_enable'] ?? '') != '1' ? 'disabled' : ''?>>
            </div>
          </td>
        </tr>
      </table>
    </fieldset>
  </div>
  <?php
  $html = ob_get_contents();
  ob_end_clean();

  return $html;
}
