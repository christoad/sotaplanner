/*
 * RFI field layer for the SOTAplanner summit map.
 *
 * Adapted from natgrid.js and engine.js in KM6AM's Summit RFI project
 * (https://km6am.com/summitRFI/, https://github.com/km6am/sota-rf), used with his permission.
 * Changes: blocks come through SOTAplanner's rfi_grid.php relay (cached on our server) instead of
 * straight from his site; no live-compute mask; plain script instead of an ES module.
 *
 * MIT License
 *
 * Copyright (c) 2026 Badgie Miller, KM6AM
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */
(function () {
  'use strict';

  // National field grid: precomputed ~250 m values for the lower 48. Level k files cover
  // 2^k-degree blocks at 450 x 360 cells; a cell is the max of what it covers.
  var ROWS = 450, COLS = 360, REF_LAT = 96, REF_LON = -192;
  var LEVELS = [0.05, 0.15, 0.5, 1.5, 5, 10];                                    // V/m
  var RAMP = ['#3f9e4a', '#e8de5a', '#f39a35', '#e2492a', '#a8161c', '#4f0610'];
  var codeOf = function (E) { return (Math.log10(E) + 2) * 50 + 1; };           // V/m -> uint8 code
  var MAX_BLOCKS = 160;

  function prepIndex(ix) {
    ix.sets = {};
    Object.keys(ix.keys).forEach(function (k) { ix.sets[k] = new Set(ix.keys[k]); });
    return ix;
  }

  // The relay sends the block still gzipped; unpack it here (or use it as is if something
  // along the way already unpacked it).
  function gunzip(buf) {
    var b = new Uint8Array(buf);
    if (b.length > 2 && b[0] === 0x1f && b[1] === 0x8b) {
      return new Response(new Blob([buf]).stream().pipeThrough(new DecompressionStream('gzip'))).arrayBuffer()
        .then(function (out) { return new Uint8Array(out); });
    }
    return Promise.resolve(b);
  }

  var cache = new Map();   // `${layer}/${k}/${key}` -> Promise<Uint8Array>, oldest first
  function block(ix, layer, k, key) {
    var id = layer + '/' + k + '/' + key;
    var p = cache.get(id);
    if (p) { cache.delete(id); cache.set(id, p); return p; }
    p = fetch('rfi_grid.php?l=' + layer + '&k=' + k + '&b=' + encodeURIComponent(key) + '&v=' + encodeURIComponent(ix.created),
              { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('RFI grid ' + id + ': HTTP ' + r.status); return r.arrayBuffer(); })
      .then(gunzip);
    p.catch(function () { cache.delete(id); });
    cache.set(id, p);
    while (cache.size > MAX_BLOCKS) cache.delete(cache.keys().next().value);
    return p;
  }

  // Copy cells [r0, r1] x [c0, c1] (global indices at level k) into one array; missing blocks read 0.
  function mosaic(ix, layer, k, r0, r1, c0, c1) {
    var s = Math.pow(2, k), h = r1 - r0 + 1, w = c1 - c0 + 1, out = new Uint8Array(h * w), jobs = [];
    for (var bn = Math.floor(r0 / ROWS); bn <= Math.floor(r1 / ROWS); bn++) {
      for (var bc = Math.floor(c0 / COLS); bc <= Math.floor(c1 / COLS); bc++) {
        var key = (REF_LAT - (bn + 1) * s) + '_' + (REF_LON + bc * s);
        if (!ix.sets[k] || !ix.sets[k].has(key)) continue;
        jobs.push((function (bn, bc) {
          return block(ix, layer, k, key).then(function (a) {
            var ra = Math.max(r0, bn * ROWS), rb = Math.min(r1, bn * ROWS + ROWS - 1);
            var ca = Math.max(c0, bc * COLS), cb = Math.min(c1, bc * COLS + COLS - 1);
            for (var r = ra; r <= rb; r++) {
              var src = (r - bn * ROWS) * COLS - bc * COLS;
              out.set(a.subarray(src + ca, src + cb + 1), (r - r0) * w + (ca - c0));
            }
          });
        })(bn, bc));
      }
    }
    return Promise.all(jobs).then(function () { return { a: out, w: w, h: h }; });
  }

  function rowOf(lat, s) { return (REF_LAT - lat) / (s / ROWS) - 0.5; }   // fractional global row/col
  function colOf(lon, s) { return (lon - REF_LON) / (s / COLS) - 0.5; }   // of a cell center

  // Leaflet tile layer. opts: index (from prepIndex), layer() -> grid layer index, alpha (0-1), pane
  function layer(opts) {
    var lut = new Uint8ClampedArray(256 * 4), cut = LEVELS.map(codeOf), a = Math.round(opts.alpha * 255);
    for (var v = 0; v < 256; v++) {
      var q = -1;
      for (var i = 0; i < cut.length; i++) if (v >= cut[i]) q = i;
      if (q < 0) continue;
      var hx = RAMP[q];
      lut.set([parseInt(hx.slice(1, 3), 16), parseInt(hx.slice(3, 5), 16), parseInt(hx.slice(5, 7), 16), a], v * 4);
    }
    var Layer = L.GridLayer.extend({
      createTile: function (coords, done) {
        var ix = opts.index, dpr = Math.min(2, window.devicePixelRatio || 1), N = 256 * dpr;
        var cv = document.createElement('canvas'); cv.width = cv.height = N;
        var z = coords.z, k = Math.max(0, Math.min(ix.levels - 1, 9 - z)), s = Math.pow(2, k), world = 256 * Math.pow(2, z);
        var lonAt = function (px) { return (coords.x * 256 + px) / world * 360 - 180; };
        var latAt = function (py) { return Math.atan(Math.sinh(Math.PI * (1 - 2 * (coords.y * 256 + py) / world))) * 180 / Math.PI; };
        var lons = new Float64Array(N), rows = new Float64Array(N);
        for (var i = 0; i < N; i++) { lons[i] = colOf(lonAt((i + 0.5) / dpr), s); rows[i] = rowOf(latAt((i + 0.5) / dpr), s); }
        var r0 = Math.floor(rows[0]), r1 = Math.floor(rows[N - 1]) + 1, c0 = Math.floor(lons[0]), c1 = Math.floor(lons[N - 1]) + 1;
        // Draw each tile in its own task (setTimeout), not straight in the promise chain: with blocks
        // already cached, every tile would otherwise draw before the browser paints anything, so the
        // page froze and a loading spinner never showed.
        mosaic(ix, opts.layer(), k, r0, r1, c0, c1).then(function (res) {
          setTimeout(function () { paint(res); }, 0);
        }, function (err) { done(err, cv); });
        function paint(res) {
          var m = res.a, w = res.w;
          var g = cv.getContext('2d'), img = g.createImageData(N, N), px = img.data;
          for (var y = 0; y < N; y++) {
            var fy = rows[y] - r0, y0 = Math.floor(fy), ty = fy - y0;
            for (var x = 0; x < N; x++) {
              var fx = lons[x] - c0, x0 = Math.floor(fx), tx = fx - x0, o = y0 * w + x0;
              var val = (m[o] * (1 - tx) + m[o + 1] * tx) * (1 - ty) + (m[o + w] * (1 - tx) + m[o + w + 1] * tx) * ty;
              var li = (val | 0) * 4;
              if (lut[li + 3]) px.set(lut.subarray(li, li + 4), (y * N + x) * 4);
            }
          }
          g.putImageData(img, 0, 0);
          done(null, cv);
        }
        return cv;
      }
    });
    return new Layer({
      opacity: 1, maxZoom: 20, pane: opts.pane,
      attribution: 'RFI: <a href="https://github.com/km6am/sota-rf" target="_blank" rel="noopener">Summit RFI by KM6AM</a>'
    });
  }

  window.RfiLayer = { prepIndex: prepIndex, layer: layer, LEVELS: LEVELS, RAMP: RAMP };
})();
