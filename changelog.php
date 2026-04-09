<?php require_once 'config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SOTA Planner — Changelog</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; color: #333; }
        .header { background: linear-gradient(135deg, #1E3A5F 0%, #2d5a8e 100%); color: white; padding: 2rem 1.5rem; text-align: center; }
        .header h1 { font-size: 1.6rem; font-weight: 700; margin-bottom: 0.3rem; }
        .header p { opacity: 0.75; font-size: 0.9rem; }
        .container { max-width: 760px; margin: 2rem auto; padding: 0 1rem 4rem; }
        .version-block { background: white; border-radius: 10px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,0.07); border-left: 5px solid #4A90A4; }
        .version-block.current { border-left-color: #2e7d32; }
        .version-header { display: flex; align-items: baseline; gap: 1rem; margin-bottom: 0.75rem; flex-wrap: wrap; }
        .version-number { font-size: 1.15rem; font-weight: 800; color: #1E3A5F; }
        .version-date { font-size: 0.82rem; color: #999; }
        .version-badge { font-size: 0.7rem; font-weight: 700; background: #2e7d32; color: white; border-radius: 20px; padding: 0.15rem 0.6rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .version-desc { font-size: 0.88rem; color: #555; margin-bottom: 0.85rem; line-height: 1.55; }
        ul { padding-left: 1.25rem; }
        ul li { font-size: 0.88rem; line-height: 1.6; margin-bottom: 0.3rem; color: #444; }
        .tag { display: inline-block; font-size: 0.68rem; font-weight: 700; border-radius: 4px; padding: 0.1rem 0.4rem; margin-right: 0.35rem; text-transform: uppercase; letter-spacing: 0.04em; vertical-align: middle; }
        .tag-new { background: #e8f5e9; color: #2e7d32; }
        .tag-fix { background: #fff3e0; color: #e65100; }
        .tag-improve { background: #e3f2fd; color: #1565c0; }
        .back { display: inline-block; margin-bottom: 1.5rem; color: #4A90A4; font-size: 0.88rem; text-decoration: none; font-weight: 600; }
        .back:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="header">
    <h1>⛰️ SOTA Planner — Changelog</h1>
    <p>Release history and update notes</p>
</div>

<div class="container">
    <a href="index.php" class="back">← Back to Planner</a>

    <!-- v1.0.0 -->
    <div class="version-block current">
        <div class="version-header">
            <span class="version-number">v1.0.0</span>
            <span class="version-date">April 2026</span>
            <span class="version-badge">Current</span>
        </div>
        <p class="version-desc">Initial public release. Full feature set for planning, researching, and sharing SOTA activations.</p>
        <ul>
            <li><span class="tag tag-new">New</span> Multi-group support — multiple planning groups share the same summit database</li>
            <li><span class="tag tag-new">New</span> GPX track upload and analysis — hiking time, activation time, rest breaks, elevation, speed</li>
            <li><span class="tag tag-new">New</span> Activation zone overlay using the activation.zone API with terrain-based polygon</li>
            <li><span class="tag tag-new">New</span> Elevation profile chart with interactive map crosshair hover on summit detail and invitation pages</li>
            <li><span class="tag tag-new">New</span> Activation invitation page for sharing with non-ham guests — timeline, map, driving directions</li>
            <li><span class="tag tag-new">New</span> Real-time location sharing link field on planned activations</li>
            <li><span class="tag tag-new">New</span> SOTA Maps GPX import — pull community tracks directly from sotamaps.org</li>
            <li><span class="tag tag-new">New</span> Cell coverage overlay (T-Mobile, Verizon, AT&amp;T) on summit and invitation maps</li>
            <li><span class="tag tag-new">New</span> Planned activations with calendar (.ics) export and shareable invite links</li>
            <li><span class="tag tag-new">New</span> Drive time calculation from saved home addresses via Google Maps</li>
            <li><span class="tag tag-new">New</span> Shared summit data — new groups can inherit trail research from existing groups</li>
            <li><span class="tag tag-new">New</span> Auto-import GPX track from source group when adopting a shared summit</li>
            <li><span class="tag tag-new">New</span> SOTLAS integration for trail and summit reference data</li>
            <li><span class="tag tag-fix">Fix</span> Invitation URL double-slash when site is hosted at domain root</li>
            <li><span class="tag tag-fix">Fix</span> SOTLAS link encoding — forward slash in summit reference no longer percent-encoded</li>
            <li><span class="tag tag-fix">Fix</span> Cookie star buttons now reflect immediately without requiring a page reload</li>
            <li><span class="tag tag-improve">Improve</span> Safety and location sharing info on invitation page is now generic and configurable per activation</li>
        </ul>
    </div>

</div>

<footer style="text-align:center; padding:1.5rem 1rem; color:#bbb; font-size:0.78rem;">
    SOTA Planner &nbsp;·&nbsp; <a href="changelog.php" style="color:#bbb; text-decoration:none;">v<?= APP_VERSION ?></a> &nbsp;·&nbsp; <a href="https://sotaplanner.com" style="color:#bbb; text-decoration:none;">sotaplanner.com</a>
</footer>

</body>
</html>
