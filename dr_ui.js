/* Discovered routes panel on the summit page. Config in window.DR_CFG (discovered_routes_lib.php, dr_render_panel()).
 * Loads the summit's 3 shown routes from discovered_routes.php, draws them on its own small map and lets the user
 * vote (Looks viable / Not viable) and pick one (Use this route). Keeps to itself: no globals besides DR. */
(function () {
  var C = window.DR_CFG;
  if (!C || !document.getElementById('dr-panel')) return;
  var COLORS = ['#B8561B', '#2F6DB5', '#2E8A5A'];
  var LEVEL = { high: 'High', medium: 'Medium', low: 'Low' };
  var data = null, map = null, layers = {}, active = null, confirmUse = null;
  var el = function (id) { return document.getElementById(id); };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function dist(m) {
    return C.units === 'metric' ? (m / 1000).toFixed(m < 10000 ? 1 : 0) + ' km' : (m / 1609.344).toFixed(1) + ' mi';
  }
  function climb(m) {
    return C.units === 'metric' ? Math.round(m).toLocaleString() + ' m' : Math.round(m * 3.28084).toLocaleString() + ' ft';
  }
  function dur(min) {
    min = Math.round(min);
    if (min < 60) return min + ' min';
    var h = Math.floor(min / 60), m = min % 60;
    return h + ' h' + (m ? ' ' + m + ' min' : '');
  }
  function level(band, conf) {
    if (!band) return '';
    return '<span class="dr-level dr-level-' + band + '">' + LEVEL[band] + '</span>' +
      (data && data.god && conf != null ? ' <span style="font-size:0.72rem;color:var(--ink-3)">' + conf + '%</span>' : '');
  }

  function post(params) {
    var body = new URLSearchParams(params);
    return fetch('discovered_routes.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected answer from the server (HTTP ' + r.status + ')' }; }); });
  }

  /* ───────── map ───────── */
  function pin(color, text) {
    return L.divIcon({ className: '', html: '<div class="dr-pin" style="background:' + color + '">' + text + '</div>', iconSize: [22, 22], iconAnchor: [11, 11] });
  }
  function drawMap() {
    map = L.map('dr-map', { scrollWheelZoom: false }).setView([C.lat, C.lon], 13);
    L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://opentopomap.org">OpenTopoMap</a>, &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>', maxZoom: 17
    }).addTo(map);
    var bounds = L.latLngBounds([[C.lat, C.lon]]);
    if (C.compare && C.compare.length > 1) {
      L.polyline(C.compare, { color: '#6B6965', weight: 3, opacity: 0.7, dashArray: '2 6' }).addTo(map)
        .bindTooltip('Route already on this summit');
      C.compare.forEach(function (p) { bounds.extend(p); });
    }
    (data.parking || []).forEach(function (p) {
      var t = p.k === 'T';
      var tip = (t ? 'Trailhead' : 'Parking') + (p.name ? ': ' + p.name : '') + (p.access && p.access !== 'yes' ? ' (access: ' + p.access + ')' : '') + (p.fee === 'yes' ? ', fee' : '');
      L.marker([p.lat, p.lon], { icon: L.divIcon({ className: '', html: '<div class="dr-pk" style="background:' + (t ? '#2E8A5A' : '#2F6DB5') + '">' + (t ? 'T' : 'P') + '</div>', iconSize: [16, 16], iconAnchor: [8, 8] }), zIndexOffset: -500 })
        .bindTooltip(esc(tip)).addTo(map);
    });
    data.routes.forEach(function (r, i) {
      var col = COLORS[i % COLORS.length], g = L.layerGroup();
      var line = r.route.map(function (p) { return [p[0], p[1]]; });
      var main = L.polyline(line, { color: col, weight: 4, opacity: 0.9 }).addTo(g);
      if (r.offtrail && line.length) {
        L.polyline([line[line.length - 1], [C.lat, C.lon]], { color: col, weight: 3, opacity: 0.9, dashArray: '4 6' }).addTo(g)
          .bindTooltip('No mapped path to the top: straight line, off trail');
      }
      L.marker([r.lat, r.lon], { icon: pin(col, r.rank), zIndexOffset: 500 }).addTo(g).bindTooltip('Route ' + r.rank + ': ' + esc(r.label));
      if (r.closed) {
        var cl = r.closed.route.map(function (p) { return [p[0], p[1]]; });
        L.polyline(cl, { color: col, weight: 3, opacity: 0.55, dashArray: '8 7' }).addTo(g);
        L.marker([r.closed.lat, r.closed.lon], { icon: L.divIcon({ className: '', html: '<div class="dr-sq" style="background:' + col + '"></div>', iconSize: [14, 14], iconAnchor: [7, 7] }) })
          .addTo(g).bindTooltip('Route ' + r.rank + ' if the dirt road is gated: ' + esc(r.closed.label));
        cl.forEach(function (p) { bounds.extend(p); });
      }
      main.on('click', function () { select(i); });
      g.addTo(map);
      layers[i] = { group: g, main: main };
      line.forEach(function (p) { bounds.extend(p); });
    });
    L.marker([C.lat, C.lon], { icon: L.divIcon({ className: '', html: '<svg width="22" height="20" viewBox="0 0 22 20"><path d="M11 1 L21 19 L1 19 Z" fill="#1C1B19" stroke="#fff" stroke-width="1.5"/></svg>', iconSize: [22, 20], iconAnchor: [11, 18] }), zIndexOffset: 1000 })
      .addTo(map).bindTooltip(esc((data.summit && data.summit.ref) || C.ref));
    map.fitBounds(bounds, { padding: [24, 24], maxZoom: 15 });
    var lg = '';
    data.routes.forEach(function (r, i) { lg += '<span><i style="border-color:' + COLORS[i] + '"></i>Route ' + r.rank + '</span>'; });
    if (data.routes.some(function (r) { return r.closed; })) lg += '<span><i class="dash" style="border-color:var(--ink-3)"></i>If the dirt road is gated</span>';
    if (C.compare) lg += '<span><i class="dash" style="border-color:#6B6965"></i>Route already on this summit</span>';
    if ((data.parking || []).length) lg += '<span>P parking, T trailhead (OpenStreetMap)</span>';
    el('dr-legend').innerHTML = lg;
  }
  function select(i) {
    active = active === i ? null : i;
    Object.keys(layers).forEach(function (k) {
      var on = active === null || +k === active;
      layers[k].group.eachLayer(function (l) { if (l.setStyle) l.setStyle({ opacity: on ? 0.9 : 0.2 }); if (l.setOpacity) l.setOpacity(on ? 1 : 0.35); });
      if (+k === active) layers[k].main.bringToFront();
    });
    document.querySelectorAll('.dr-card').forEach(function (c) { c.classList.toggle('dr-active', +c.dataset.i === active); });
    if (active !== null) map.fitBounds(layers[active].group.getLayers().reduce(function (b, l) {
      return l.getBounds ? b.extend(l.getBounds()) : (l.getLatLng ? b.extend(l.getLatLng()) : b);
    }, L.latLngBounds([[C.lat, C.lon]])), { padding: [30, 30], maxZoom: 15 });
  }

  /* ───────── cards ───────── */
  function renderNotice() {
    var v = data.votes || {}, n = el('dr-notice'), html = '';
    var used = v.used && C.usingDiscovered ? data.routes.filter(function (r) { return r.no === v.used.no; })[0] : null;
    if (used && !v.confirmed) {
      html = '<div class="dr-notice dr-notice-review"><span><strong>Review required.</strong> This dashboard uses Route ' + used.rank +
        ', a computer generated route. Check it on the map before you go.</span>' +
        '<button type="button" class="btn btn-ghost btn-sm" id="dr-confirm">Looks right</button></div>';
    } else if (used && v.confirmed) {
      html = '<div class="dr-notice dr-notice-ok">This dashboard uses Route ' + used.rank + ', confirmed by ' + esc(v.confirmed.by) + '.</div>';
    } else if (C.ownRoute) {
      html = '<div class="dr-notice dr-notice-info">This summit already has a route of its own (gray dotted line). Shown for comparison because Discovery mode is on.</div>';
    }
    n.innerHTML = html;
    var b = el('dr-confirm');
    if (b) b.onclick = function () {
      b.disabled = true;
      post({ action: 'confirm', ref: C.ref, summit_id: C.summitId, run: data.run_id, route: used.no }).then(function (j) {
        if (!j.ok) { b.disabled = false; alertBox(j.error); return; }
        data.votes = j.votes; renderNotice();
      });
    };
  }
  function alertBox(msg) {
    el('dr-notice').innerHTML = '<div class="dr-notice dr-notice-review">' + esc(msg || 'Something went wrong.') + '</div>';
  }
  function renderCards() {
    var v = data.votes || { counts: {}, mine: {} };
    var usedNo = v.used && C.usingDiscovered ? v.used.no : null;
    var html = '';
    data.routes.forEach(function (r, i) {
      var cnt = (v.counts && v.counts[r.no]) || {}, mine = v.mine && v.mine[r.no];
      var lines = [];
      if (r.driveup) lines.push('<div class="dr-line dr-line-warn">A dirt road reaches the summit. Whether it is open to cars is unknown.</div>');
      if (r.offtrail_m > 120) lines.push('<div class="dr-line dr-line-warn">No mapped path for the last ' + dist(r.offtrail_m) + ': off trail to the top.</div>');
      if (r.sac >= 3) lines.push('<div class="dr-line dr-line-warn">Includes demanding or alpine trail (SAC ' + r.sac + ').</div>');
      if (r.near_lot) lines.push('<div class="dr-line">' + (r.near_lot.k === 'T' ? 'Mapped trailhead' : 'Mapped parking') + (r.near_lot.name ? ' (' + esc(r.near_lot.name) + ')' : '') +
        ' ' + Math.round(r.near_lot.d) + ' m from this starting point, not on the route.</div>');
      if (r.closed) lines.push('<div class="dr-line">If the dirt road is gated: park at the ' + esc(r.closed.label.toLowerCase()) + ' and add about ' + dur(r.closed.extra_min) + ' each way.</div>');
      var why = data.god && r.reasons && r.reasons.length
        ? '<details class="dr-why"><summary>Why this score</summary><ul>' + r.reasons.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></details>' : '';
      var useBtn;
      if (usedNo === r.no) useBtn = '<div class="dr-in-use">In use on this dashboard</div>';
      else if (confirmUse === i) useBtn = '<div class="dr-confirm-row"><span>' + (C.ownRoute || C.usingDiscovered ? 'Replace the current route?' : 'Fill in hike data from this route?') +
        '</span><span class="dr-confirm-btns"><button type="button" class="btn btn-primary btn-sm" data-act="use-yes">Yes, use it</button><button type="button" class="btn btn-ghost btn-sm" data-act="use-no">Cancel</button></span></div>';
      else useBtn = '<button type="button" class="btn btn-primary btn-sm dr-use" data-act="use">Use this route</button>';
      html += '<div class="dr-card' + (usedNo === r.no ? ' dr-used' : '') + (active === i ? ' dr-active' : '') + '" data-i="' + i + '" style="--dr-color:' + COLORS[i] + '">' +
        '<div class="dr-card-top"><span class="dr-card-name" style="color:' + COLORS[i] + '">Route ' + r.rank + '</span>' + level(r.band, r.confidence) + '</div>' +
        '<div class="dr-start">' + esc(r.label) + '</div>' +
        '<div class="dr-stats"><span><b>' + dist(r.dist_m * 2) + '</b> round trip</span><span><b>' + climb(r.gain_m) + '</b> climb</span><span><b>' + dur(r.rt_min) + '</b> hike</span></div>' +
        lines.join('') + why +
        '<div class="dr-actions">' +
          '<div class="dr-votes">' +
            '<button type="button" class="dr-vote dr-yes" data-act="viable" aria-pressed="' + (mine === 'viable') + '">Looks viable' + (cnt.viable ? ' <span class="dr-count">' + cnt.viable + '</span>' : '') + '</button>' +
            '<button type="button" class="dr-vote dr-no" data-act="not_viable" aria-pressed="' + (mine === 'not_viable') + '">Not viable' + (cnt.not_viable ? ' <span class="dr-count">' + cnt.not_viable + '</span>' : '') + '</button>' +
          '</div>' +
          useBtn +
        '</div></div>';
    });
    el('dr-cards').innerHTML = html;
  }
  el('dr-cards').addEventListener('click', function (e) {
    var card = e.target.closest('.dr-card');
    if (!card) return;
    var i = +card.dataset.i, r = data.routes[i], btn = e.target.closest('[data-act]');
    if (!btn) { select(i); return; }
    var act = btn.dataset.act;
    if (act === 'viable' || act === 'not_viable') {
      var verdict = btn.getAttribute('aria-pressed') === 'true' ? 'clear' : act;
      btn.disabled = true;
      post({ action: 'vote', ref: C.ref, run: data.run_id, route: r.no, verdict: verdict }).then(function (j) {
        if (!j.ok) { alertBox(j.error); btn.disabled = false; return; }
        data.votes = j.votes; renderCards();
      });
    } else if (act === 'use') { confirmUse = i; renderCards(); }
    else if (act === 'use-no') { confirmUse = null; renderCards(); }
    else if (act === 'use-yes') {
      btn.disabled = true; btn.textContent = 'Saving...';
      post({ action: 'use', ref: C.ref, summit_id: C.summitId, run: data.run_id, route: r.no }).then(function (j) {
        if (!j.ok) { confirmUse = null; renderCards(); alertBox(j.error); return; }
        location.reload();
      });
    }
  });

  /* ───────── load ───────── */
  fetch('discovered_routes.php?ref=' + encodeURIComponent(C.ref) + '&summit_id=' + C.summitId, { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (j) {
      if (!j.ok) { el('dr-cards').innerHTML = '<div class="dr-empty">' + esc(j.error || 'Could not load the routes.') + '</div>'; return; }
      data = j;
      if (!j.routes || !j.routes.length) {
        el('dr-map').style.display = 'none';
        el('dr-cards').innerHTML = '<div class="dr-empty">' + (j.status === 'none' ? 'Trail discovery searched this summit and found no route to a road or parking spot. It stays Needs Research.' : 'No discovered routes for this summit.') + '</div>';
        return;
      }
      if (j.band) el('dr-summit-conf').innerHTML = 'Summit confidence ' + level(j.band, j.confidence);
      drawMap();
      renderNotice();
      renderCards();
    })
    .catch(function () { el('dr-cards').innerHTML = '<div class="dr-empty">Could not load the routes.</div>'; });
})();
