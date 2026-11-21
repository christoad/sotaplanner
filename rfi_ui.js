// RFI layer controls on the summit maps (summit_detail.php and the public /s/ page).
// Data: Summit RFI by KM6AM (https://github.com/km6am/sota-rf), used with his permission. He asked to be
// credited wherever it appears; credits link to his GitHub repo. Heat map = his precomputed national field
// grid (lower 48, drawn by rfi_layer.js). Badge = his report card for this summit.
//
// Page needs: Leaflet, rfi_layer.js, rfi_ui.css, a button #btn-rfi (onclick="toggleRfi()") and any
// .rfi-ctl elements (hidden until the data says this summit is covered), an optional #rfi-note under
// the map, then: RfiUi.init({ map, ref, lat, lon }). The map's wrapper must be position: relative.
(function () {
  'use strict';
  var RFI_REPO = 'https://github.com/km6am/sota-rf';
  var RFI_RISK = {
    HIGH:     { bg: '#C03030', fg: '#fff' },
    MODERATE: { bg: '#F39A35', fg: '#1C1B19' },
    LOW:      { bg: '#3F9E4A', fg: '#fff' },
    CLEAR:    { bg: '#8C8A86', fg: '#fff' }
  };
  var RFI_LAYER_NAMES = { 'total': 'All bands', 'HF': 'HF (40m to 10m)', '6m–70cm': '6m to 70cm', 'Above 70cm': 'Above 70cm (23cm and up)' };
  var RFI_SPIN_MIN = 600;   // loading card stays up at least this long, so a quick redraw shows it instead of a flicker
  var LOGO_SVG = '<svg viewBox="0 0 110 110" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
    + '<circle cx="55" cy="55" r="50" fill="none" stroke="#1c1b19" stroke-width="1.5" opacity="0.2"/>'
    + '<path class="rfi-logo-path" d="M26,79.5l17-30,7,8,12-20,22,42" fill="none" stroke="#1c1b19" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>'
    + '<circle class="rfi-logo-ring2" cx="62" cy="35.5" r="15" fill="none" stroke-width="0.8"/>'
    + '<circle class="rfi-logo-ring1" cx="62" cy="35.5" r="9" fill="none" stroke-width="1.2"/>'
    + '<circle class="rfi-logo-dot" cx="62" cy="35.5" r="3.5"/></svg>';

  var rfi = { map: null, lat: 0, lon: 0, data: null, index: null, layer: null, badge: null, legend: null,
              on: false, sel: 0, loading: false, card: null, spinTimer: null, spinSince: 0 };

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function bands(s) {
    var parts = [];
    if (s.high.length) parts.push('High: ' + s.high.join(', '));
    if (s.moderate.length) parts.push('Moderate: ' + s.moderate.join(', '));
    if (parts.length) return parts.join(' · ');
    return s.risk === 'CLEAR' ? 'Clear on all ham bands' : 'Low on all ham bands';
  }

  function init(opts) {
    rfi.map = opts.map; rfi.lat = opts.lat; rfi.lon = opts.lon;
    var wrap = rfi.map.getContainer().parentNode;
    rfi.card = document.createElement('div');
    rfi.card.className = 'rfi-loading-card';
    rfi.card.setAttribute('role', 'status');
    rfi.card.setAttribute('aria-live', 'polite');
    rfi.card.innerHTML = LOGO_SVG + 'Drawing RFI heat map';
    wrap.insertBefore(rfi.card, rfi.map.getContainer().nextSibling);

    fetch('rfi_summit.php?ref=' + encodeURIComponent(opts.ref) + '&lat=' + opts.lat + '&lon=' + opts.lon, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || d.error || (!d.covered && !d.summit)) return;   // outside his coverage (Alaska, Hawaii)
        rfi.data = d;
        document.querySelectorAll('.rfi-ctl').forEach(function (el) { el.style.display = ''; });
        // The dot on the button shows this summit's risk at a glance
        var dot = document.querySelector('#btn-rfi .rfi-dot');
        if (dot) dot.style.background = d.summit && RFI_RISK[d.summit.risk] ? RFI_RISK[d.summit.risk].bg : '#B8B5B0';
      })
      .catch(function () {});
  }

  function makeBadge() {
    var s = rfi.data.summit, html, title = '';
    if (s) {
      var c = RFI_RISK[s.risk] || RFI_RISK.CLEAR;
      html = '<div class="rfi-badge" style="border-color:' + c.bg + ';">'
        + '<div class="rfi-badge-head"><span class="rfi-badge-chip" style="background:' + c.bg + ';color:' + c.fg + ';">' + esc(s.risk || '?') + '</span>RFI'
        + (s.report_url ? '<span class="rfi-badge-link">Full report &#8599;</span>' : '') + '</div>'
        + '<div>' + esc(bands(s)) + '</div></div>';
      if (s.report_url) title = "Open KM6AM's full RFI report for this summit";
    } else {
      html = '<div class="rfi-badge rfi-none" style="border-color:#B8B5B0;"><div class="rfi-badge-head">RFI</div><div>No transmitters mapped near this summit</div></div>';
    }
    var m = L.marker([rfi.lat, rfi.lon], {
      icon: L.divIcon({ className: 'rfi-badge-icon', html: html, iconSize: [0, 0], iconAnchor: [0, 14] }),
      zIndexOffset: 900, keyboard: false, title: title, interactive: !!(s && s.report_url)
    });
    if (s && s.report_url) m.on('click', function () { window.open(s.report_url, '_blank', 'noopener'); });
    return m;
  }

  function makeLegend() {
    var R = RfiLayer.RAMP, BASE = '#ECEEF0';
    var sw = function (cols) { return '<span class="rfi-sw">' + cols.map(function (c) { return '<i style="background:' + c + ';opacity:' + (c === BASE ? 1 : 0.75) + ';"></i>'; }).join('') + '</span>'; };
    var tiers = [
      ['CLEAR', 'under 0.05 V/m', 'Even a cheap HT is fine', [BASE]],
      ['LOW', '0.05 to 0.15', 'Cheap HT degraded; a weak S5 is still copyable', [R[0]]],
      ['MODERATE', '0.15 to 1.5', 'A cheap HT (Baofeng, Quansheng) loses a weak S5', [R[1], R[2]]],
      ['HIGH', '1.5 and up', 'Even a good radio loses a weak S5, worse with every step', [R[3], R[4], R[5]]]
    ];
    var short = { CLEAR: 'Clear', LOW: 'Low', MODERATE: 'Mod.', HIGH: 'High' };
    var Ctl = L.Control.extend({
      options: { position: 'bottomleft' },
      onAdd: function () {
        var div = L.DomUtil.create('div', 'rfi-legend');
        // Compact: title, band group, four-step key, credit. Details on hover (desktop) or the "i" (phones).
        var h = '<div class="rfi-legend-head"><span class="rfi-legend-title">RFI estimate</span>'
          + '<button type="button" class="rfi-legend-info" aria-label="What the colors mean" aria-expanded="false" title="What the colors mean">i</button></div>';
        if (rfi.layer) {
          h += '<select id="rfi-layer-sel" aria-label="Band group">'
            + rfi.data.grid.layers.map(function (n, i) { return '<option value="' + i + '"' + (i === rfi.sel ? ' selected' : '') + '>' + esc(RFI_LAYER_NAMES[n] || n) + '</option>'; }).join('')
            + '</select>';
        } else {
          h += '<div style="margin-bottom:0.3rem;color:var(--ink-3);">Heat map not available here right now.</div>';
        }
        h += '<div class="rfi-key">' + tiers.map(function (t) { return '<div>' + sw(t[3]) + short[t[0]] + '</div>'; }).join('') + '</div>';
        h += '<div class="rfi-more">';
        tiers.forEach(function (t) {
          h += '<div class="rfi-legend-row">' + sw(t[3]) + '<span><b>' + t[0] + '</b> <span style="color:var(--ink-3);">' + t[1] + '</span><br>' + t[2] + '</span></div>';
        });
        h += '<div class="rfi-more-foot">Estimates from registered transmitter power (FCC, NOAA Weather Radio) and USGS terrain, not measurements. Federal transmitters are not included.'
          + (rfi.data.data_date ? ' Data as of ' + esc(rfi.data.data_date) + '.' : '') + '</div></div>';
        h += '<div class="rfi-legend-credit">Data: <a href="' + RFI_REPO + '" target="_blank" rel="noopener">KM6AM</a></div>';
        div.innerHTML = h;
        L.DomEvent.disableClickPropagation(div);
        L.DomEvent.disableScrollPropagation(div);
        var info = div.querySelector('.rfi-legend-info');
        info.addEventListener('click', function () {
          var open = div.classList.toggle('open');
          info.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        var sel = div.querySelector('#rfi-layer-sel');
        if (sel) sel.addEventListener('change', function () {
          rfi.sel = parseInt(sel.value, 10) || 0;
          if (rfi.layer) afterPaint(function () { rfi.layer.redraw(); });
        });
        return div;
      }
    });
    return new Ctl();
  }

  // Kept short on purpose (Chris, 2026-11-20): report link plus source. The details live in his
  // report card, and the estimate and federal caveats sit in the legend's "i" panel.
  function noteHtml() {
    var s = rfi.data.summit, h = '';
    if (s && s.report_url) h += '<a href="' + esc(s.report_url) + '" target="_blank" rel="noopener">Full RFI report &#8599;</a> &middot; ';
    return h + 'Source: <a href="' + RFI_REPO + '" target="_blank" rel="noopener">Summit RFI by KM6AM</a>, used with permission';
  }

  // Loading card on while heat map tiles load and draw (first time on, and after a band change)
  function setLoading(on) {
    clearTimeout(rfi.spinTimer);
    if (!on && rfi.spinSince) {
      var left = RFI_SPIN_MIN - (Date.now() - rfi.spinSince);
      if (left > 0) { rfi.spinTimer = setTimeout(function () { setLoading(false); }, left); return; }
    }
    if (on && !rfi.loading) rfi.spinSince = Date.now();
    if (!on) rfi.spinSince = 0;
    rfi.loading = on;
    if (rfi.card) rfi.card.classList.toggle('show', on);
  }
  // Show the loading card, let the browser paint it, then run the heavy work
  function afterPaint(fn) {
    setLoading(true);
    requestAnimationFrame(function () {
      setTimeout(function () {
        fn();
        // If nothing needed loading (no 'load' event will come), don't leave the card up
        if (!rfi.layer || !rfi.layer.isLoading()) setLoading(false);
      }, 0);
    });
  }

  function toggle() {
    if (!rfi.data) return;
    var map = rfi.map, btn = document.getElementById('btn-rfi'), note = document.getElementById('rfi-note');
    if (rfi.on) {
      if (rfi.layer) map.removeLayer(rfi.layer);
      if (rfi.badge) map.removeLayer(rfi.badge);
      if (rfi.legend) map.removeControl(rfi.legend);
      if (note) note.style.display = 'none';
      setLoading(false);
      if (btn) { btn.classList.remove('btn-map-active'); btn.classList.add('btn-secondary'); }
      rfi.on = false;
      return;
    }
    if (!rfi.layer && rfi.data.grid && rfi.data.covered && window.RfiLayer && 'DecompressionStream' in window) {
      if (!map.getPane('rfiPane')) {
        map.createPane('rfiPane');
        map.getPane('rfiPane').style.zIndex = 250;          // above the base map, below tracks and pins
        map.getPane('rfiPane').style.pointerEvents = 'none';
      }
      rfi.index = RfiLayer.prepIndex(rfi.data.grid);
      rfi.layer = RfiLayer.layer({ index: rfi.index, layer: function () { return rfi.sel; }, alpha: 0.55, pane: 'rfiPane' });
      // Leaflet fires 'loading' when tiles start and 'load' once every visible tile is done (failed ones too)
      rfi.layer.on('loading', function () { setLoading(true); });
      rfi.layer.on('load', function () { setLoading(false); });
    }
    rfi.on = true;
    if (rfi.layer) { var lyr = rfi.layer; afterPaint(function () { if (rfi.on) lyr.addTo(map); }); }
    if (!rfi.badge) rfi.badge = makeBadge();
    rfi.badge.addTo(map);
    if (!rfi.legend) rfi.legend = makeLegend();
    map.addControl(rfi.legend);
    if (note) { note.innerHTML = noteHtml(); note.style.display = ''; }
    if (btn) { btn.classList.remove('btn-secondary'); btn.classList.add('btn-map-active'); }
  }

  window.RfiUi = { init: init };
  window.toggleRfi = toggle;
})();
