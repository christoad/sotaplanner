<?php
/**
 * SOTA articles and links ("resources") for one summit, from SOTA's database (the same list SOTLAS shows).
 * Cached per summit in app_settings for 6 hours; a failed fetch keeps the stale copy and retries in ~15 minutes.
 */

/** Reduce article HTML to a few safe tags; links keep only an http(s) href. */
function sota_resource_clean_html(string $html): string {
    if (trim($html) === '') return '';
    $allowed = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'a', 'blockquote'];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $clean = function (DOMNode $node) use (&$clean, $allowed, $doc) {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE) continue;
            if ($child->nodeType !== XML_ELEMENT_NODE) { $node->removeChild($child); continue; }
            $tag = strtolower($child->nodeName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed'], true)) { $node->removeChild($child); continue; }
            $clean($child);
            if (!in_array($tag, $allowed, true)) {
                // Unwrap: keep the children, drop the tag
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }
            $href = $tag === 'a' ? trim($child->getAttribute('href')) : '';
            foreach (iterator_to_array($child->attributes) as $attr) $child->removeAttributeNode($attr);
            if ($tag === 'a') {
                if (preg_match('~^https?://~i', $href)) {
                    $child->setAttribute('href', $href);
                    $child->setAttribute('target', '_blank');
                    $child->setAttribute('rel', 'noopener noreferrer');
                } else {
                    while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                    $node->removeChild($child);
                }
            }
        }
    };
    $root = $doc->getElementById('root');
    if (!$root) return htmlspecialchars(strip_tags($html));
    $clean($root);
    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return $out;
}

function sota_resources_for_ref(PDO $db, string $sota_ref): array {
    $key = 'sota_resources_' . strtoupper($sota_ref);
    $st = $db->prepare("SELECT setting_value, updated_at FROM app_settings WHERE setting_key = ?");
    $st->execute([$key]);
    $cache = $st->fetch();
    $items = $cache ? json_decode($cache['setting_value'], true) : null;
    $age = $cache ? time() - strtotime($cache['updated_at']) : PHP_INT_MAX;

    if ($age >= 6 * 3600) {
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: SOTAplanner/1.0\r\n"]]);
        $raw = @file_get_contents('https://api-db2.sota.org.uk/api/resources/' . $sota_ref, false, $ctx);
        $fetched = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($fetched)) {
            $items = [];
            foreach ($fetched as $r) {
                if (isset($r['valid']) && !$r['valid']) continue;
                $url = trim($r['url'] ?? '');
                if ($url !== '' && !preg_match('~^https?://~i', $url)) $url = '';
                $items[] = [
                    'title'    => (string)($r['title'] ?? ''),
                    'url'      => $url !== '' ? $url : ('https://www.sotadata.org.uk/en/summits/article/' . (int)$r['resourceId'] . '/view'),
                    'article'  => $url === '' ? sota_resource_clean_html((string)($r['article'] ?? '')) : '',
                    'callsign' => (string)($r['callsign'] ?? ''),
                    'ts'       => (int)($r['dateSubmitted'] ?? 0),
                ];
            }
            usort($items, fn($a, $b) => $b['ts'] <=> $a['ts']);
            $db->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
                          ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=NOW()")
               ->execute([$key, json_encode($items)]);
        } elseif ($cache) {
            $db->prepare("UPDATE app_settings SET updated_at = NOW() - INTERVAL 20700 SECOND WHERE setting_key = ?")
               ->execute([$key]);
        }
    }
    return is_array($items) ? $items : [];
}
