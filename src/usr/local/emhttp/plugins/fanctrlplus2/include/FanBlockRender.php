<?php
require_once __DIR__.'/Common.php';
require_once __DIR__.'/CurveConfig.php';
$label_file = "/boot/config/plugins/fanctrlplus2/pwm_labels.cfg";
$pwm_labels = fcp_load_pwm_labels($label_file);

// Disk memberships, including the single group used by pre-group configurations.
function fcp_build_disk_groups($cfg) {
  $count = isset($cfg['disk_group_count']) ? intval($cfg['disk_group_count']) : 0;
  if ($count > 0) {
    $groups = [];
    for ($g = 0; $g < $count; $g++) {
      $groups[] = [
        'name'  => $cfg["disk_group_{$g}_name"] ?? '',
        'disks' => array_filter(explode(',', $cfg["disk_group_{$g}_disks"] ?? '')),
      ];
    }
    return $groups;
  }
  if (empty($cfg['disks'])) return [];
  return [[
    'name'  => '',
    'disks' => array_filter(explode(',', $cfg['disks'] ?? '')),
  ]];
}

// Render one disk-group membership row for fan $i, group $g.
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
        <td class="fcp-help-cursor" title="Select disks, NVMe drives, or other block devices belonging to this group.">Disk(s):</td>
        <td class="fcp-ddcl-cell">
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
        <td>Curve:</td>
        <td>
          <button type="button" onclick="openFanCurveEditor(this, 'disk:<?=$g?>')">Edit fan curve</button>
          <span class="fcp-curve-summary" data-source="disk:<?=$g?>"></span>
        </td>
      </tr>
    </table>
  </div>
  <?php
  $html = ob_get_contents();
  ob_end_clean();
  return $html;
}

// $frozen: the fan's frozen-sensor conclusions (fcp_frozen_sensors), read
// from its loop's runtime file when not given.
function render_fan_block($cfg, $i, $pwms, $disks, $pwm_labels, $cpu_sensors, $aux_sensors = [], $frozen = null) {
  $frozen ??= fcp_frozen_sensors('/var/tmp/fanctrlplus2/frozen_' . basename((string)($cfg['custom'] ?? '')),
    2 * cfg_interval_seconds($cfg) + 300);
  // Sources as the state beside a sensor names them, as in the fan's history.
  $aux_names = [];
  foreach ($aux_sensors as $entries) {
    foreach ($entries as $sensor) $aux_names[$sensor['path']] = preg_replace('/\s*\(-?[\d.]+°C\)$/u', '', $sensor['label']);
  }
  $source_name = function (string $src) use ($cfg, $aux_names): string {
    if ($src === 'cpu') return 'CPU';
    if (!preg_match('/^disk:(\d+)$/', $src, $m)) return $aux_names[$src] ?? $src;
    if (intval($cfg['disk_group_count'] ?? 0) === 0) return 'Disk';
    $name = (string)($cfg["disk_group_{$m[1]}_name"] ?? '');
    return 'Disk: ' . ($name !== '' ? $name : 'Group ' . ($m[1] + 1));
  };
  $curve_error = '';
  try { $curve_rules = fcp_curve_rules($cfg); }
  catch (InvalidArgumentException $error) { $curve_rules = null; $curve_error = $error->getMessage(); }
  // Use 100% as the normal cap when it is missing.
  $max_raw = isset($cfg['max']) && is_numeric($cfg['max']) ? $cfg['max'] : 255;

  $max_pct = round($max_raw * 100 / 255) . '%';

  $disk_groups = fcp_build_disk_groups($cfg);

  $idle_abs = isset($cfg['idle']) && is_numeric($cfg['idle']) ? (int)$cfg['idle'] : 0;
  $idle_pct = round($idle_abs * 100 / 255) . '%';

  ob_start();
  ?>
  <div class="fan-block" data-index="<?=$i?>" data-file="<?=htmlspecialchars($cfg['file'])?>">
    <input type="hidden" name="#file[<?=$i?>]" value="<?=htmlspecialchars($cfg['file'])?>" class="cfg-file">
    <input type="hidden" name="curve_config[<?=$i?>]" class="fcp-curve-config"
           value="<?=htmlspecialchars(json_encode($curve_rules), ENT_QUOTES)?>">
    <?php if ($curve_error !== ''): ?>
      <p role="alert"><?=htmlspecialchars("Invalid saved fan curve: $curve_error Restore a valid configuration backup before applying settings.")?></p>
    <?php endif; ?>

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
      </div> 

      <!-- Reordering: a button per direction, which a touch screen can use and
           which says exactly where a block will land. -->
      <div class="fcp-reorder">
        <button type="button" class="fcp-move-up" title="Move this fan configuration earlier" aria-label="Move earlier">
          <i class="fa fa-arrow-up"></i>
        </button>
        <button type="button" class="fcp-move-down" title="Move this fan configuration later" aria-label="Move later">
          <i class="fa fa-arrow-down"></i>
        </button>
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

        <tr>
          <td class="fcp-help-cursor" title="Limits normal output without changing curve points. Emergency cooling can bypass this cap.">Normal Cap:</td>
          <td>
            <input type="text" id="max_percent_input_<?=$i?>" name="max_percent[<?=$i?>]"
                   inputmode="numeric" class="fcp-input-idle" data-pwm="<?=intval($max_raw)?>" value="<?=$max_pct?>" title="Normal output cap">
          </td>
        </tr>

        <tr>
          <td class="fcp-help-cursor"
              title="Fan speed used when no temperature source is available. Must not exceed the normal cap, disk curves’ first points, or enabled CPU/Aux rules’ first points. Default 0% may stop the fan.">
            Speed on Idle:
          </td>
          <td>
            <input type="text"
                  id="idle_percent_input_<?=$i?>"
                  name="idle_percent[<?=$i?>]"
                  inputmode="numeric"
                  class="fcp-input-idle"
                  data-pwm="<?=$idle_abs?>"
                  value="<?=$idle_pct?>"
                  title="Idle speed: <?=$idle_pct?> = <?=htmlspecialchars($idle_abs)?> PWM"
                  placeholder="Idle %">
          </td>
        </tr>

        <!-- Interval -->
        <tr>
          <td class="fcp-help-cursor" title="Check temperature and adjust fan speed every X seconds (5-3600).&#10;Sub-minute intervals suit CPU cooler control; for disks a minute or more is plenty.">Interval:</td>
          <td>
            <input type="text"
                  id="interval_input_<?=$i?>"
                  name="interval[<?=$i?>]"
                  class="interval-input fcp-interval-input" 
                  inputmode="numeric"
                  value="<?=htmlspecialchars(cfg_interval_seconds($cfg) . ' sec')?>"
                  placeholder="Recommended: 60–300 sec">

            <span class="fanctrlplus2-interval-refresh fcp-runnow"
                  title="Manual Run: Read current temperature and set fan speed immediately"
                  data-label="<?=htmlspecialchars($cfg['custom'] ?? '')?>">
              <span class="fa fa-refresh fcp-fs-13"></span> Run Now
            </span>
          </td>
        </tr>

        <tr><td colspan="2" class="subhead">Disk Temperature Settings (optional)</td></tr>

        <!-- Each group's hottest selected disk drives its own curve. -->
        <tr>
          <td colspan="2" class="fcp-groups-cell">
            <div class="disk-groups" id="disk-groups-<?=$i?>">
              <?php foreach ($disk_groups as $g => $grp): ?>
                <?= render_disk_group_row($i, $g, $grp, $disks) ?>
              <?php endforeach; ?>
            </div>
            <button type="button" class="add-disk-group-btn" data-fan-index="<?=$i?>" title="Add another disk group with its own fan curve">
              <i class="fa fa-plus"></i> Add Disk Group
            </button>
          </td>
        </tr>

        <tr><td colspan="2" class="subhead">CPU Temperature Settings</td></tr>

        <!-- CPU Temp Monitoring Dropdown -->
        <tr>
          <td class="fcp-help-cursor" title="Enable or disable monitoring CPU temperature for this fan.">Monitor:</td>
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
            <?php if (isset($frozen['cpu'])): ?>
              <span class="fcp-sensor-state" role="status"><?=htmlspecialchars(fcp_frozen_text($frozen['cpu'], $source_name($frozen['cpu']['other'])))?></span>
            <?php endif; ?>
          </td>
        </tr>

        <tr class="cpu-control cpu-control-<?=$i?>">
          <td>Curve:</td>
          <td>
            <button type="button" class="cpu-input" onclick="openFanCurveEditor(this, 'cpu')" <?=($cfg['cpu_enable'] ?? '') != '1' ? 'disabled' : ''?>>Edit fan curve</button>
            <span class="fcp-curve-summary" data-source="cpu"></span>
          </td>
        </tr>

        <tr><td colspan="2" class="subhead">Auxiliary Sensor Settings</td></tr>

        <!-- Aux Temp Monitoring Dropdown -->
        <tr>
          <td class="fcp-help-cursor" title="Enable or disable monitoring an auxiliary temperature sensor (e.g. network card, chipset, VRM) for this fan.">Monitor:</td>
          <td>
            <select id="aux-enable-<?=$i?>" name="aux_enable[<?=$i?>]" class="fcp-enable-select" onchange="handleAuxEnableChange(this, <?=$i?>);">
              <option value="0" <?=($cfg['aux_enable'] ?? '') != '1' ? 'selected' : ''?>>Disabled</option>
              <option value="1" <?=($cfg['aux_enable'] ?? '') == '1' ? 'selected' : ''?>>Enabled</option>
            </select>
          </td>
        </tr>

        <!-- Aux Sensor(s) -->
        <tr class="aux-control aux-control-<?=$i?>">
          <td class="aux-label fcp-help-cursor" title="Select one or more auxiliary temperature sensors to monitor. The highest temperature across all selected sensors is used. Lists non-CPU, non-NVMe hwmon sensors, plus storcli, nvidia-smi and Mellanox NICs if available, and any script you dropped in sensors.d.">Sensor(s):</td>
          <td class="fcp-ddcl-cell">
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
            <?php foreach ($aux_selected as $sensor): if (!isset($frozen[$sensor])) continue; ?>
              <span class="fcp-sensor-state" role="status"><?=htmlspecialchars($source_name($sensor) . ': ' . fcp_frozen_text($frozen[$sensor], $source_name($frozen[$sensor]['other'])))?></span>
            <?php endforeach; ?>
          </td>
        </tr>

        <tr class="aux-control aux-control-<?=$i?>">
          <td>Curve:</td>
          <td>
            <button type="button" class="aux-input" onclick="openFanCurveEditor(this, 'aux')" <?=($cfg['aux_enable'] ?? '') != '1' ? 'disabled' : ''?>>Edit fan curve</button>
            <span class="fcp-curve-summary" data-source="aux"></span>
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
