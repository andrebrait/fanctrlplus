(function () {
  'use strict';

  const loadedVersion = (window.FCP_LOADED_ASSET_VERSION ?? '').toString();
  if (!/^[a-f0-9]{16}$/.test(loadedVersion)) return;

  function showReloadNotice() {
    const notice = document.getElementById('fcp-asset-update-notice');
    if (notice) notice.hidden = false;
  }

  async function checkAssetVersion() {
    try {
      const response = await fetch('/plugins/fanctrlplus2/include/FanctrlLogic.php?op=asset_version', {
        cache: 'no-store',
      });
      if (!response.ok) return;

      const current = await response.json();
      if (current.version && current.version !== loadedVersion) {
        showReloadNotice();
        if (window.__fcpAssetVersionTimer) {
          clearInterval(window.__fcpAssetVersionTimer);
          window.__fcpAssetVersionTimer = null;
        }
      }
    } catch (_error) {
      // A temporary network failure must not interfere with fan configuration.
    }
  }

  function reloadWithConfirmation() {
    const applyButton = document.getElementById('apply-btn');
    if (applyButton && !applyButton.disabled) {
      const discard = window.confirm('Reloading will discard unapplied FanCtrl Plus 2 changes. Continue?');
      if (!discard) return;
    }
    window.location.reload();
  }

  function startAssetMonitor() {
    document.getElementById('fcp-asset-reload')?.addEventListener('click', reloadWithConfirmation);
    checkAssetVersion();
    if (window.__fcpAssetVersionTimer) clearInterval(window.__fcpAssetVersionTimer);
    window.__fcpAssetVersionTimer = setInterval(checkAssetVersion, 15000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startAssetMonitor, { once: true });
  } else {
    startAssetMonitor();
  }
})();
