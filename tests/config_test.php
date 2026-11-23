<?php

declare(strict_types=1);

// Static checks that the Content-Security-Policy actually permits everything
// the UI loads. This exists because a mismatch here fails silently in the
// browser: a blocked font used to render icons as blank boxes, and there was no
// PHP test that would have caught it.

function check(string $label, bool $ok): void
{
    echo ($ok ? "PASS" : "FAIL") . " - $label\n";
}

$root = dirname(__DIR__);
$vhost = (string) file_get_contents($root . '/docker/apache-vhost.conf');
$security = (string) file_get_contents($root . '/src/Security.php');
$html = (string) file_get_contents($root . '/public/music_list.html');
$api = (string) file_get_contents($root . '/public/api.php');

// --- Page CSP, from the Apache vhost that serves music_list.html ---
preg_match('/Content-Security-Policy "([^"]+)"/', $vhost, $m);
$pageCsp = $m[1] ?? '';
check('vhost defines a CSP for music_list.html', $pageCsp !== '');

// --- Fragment CSP, from Security::headers(): join the concatenated string
//     literals of the header(...) call so no runtime execution is needed. ---
preg_match_all('/"([^"]*)"/', $security, $lits);
$fragParts = array_filter(
    $lits[1] ?? [],
    static fn (string $lit): bool => str_contains($lit, 'default-src')
        || str_contains($lit, 'img-src')
        || str_contains($lit, 'base-uri')
);
$fragCsp = trim(implode('', $fragParts));
check('Security::headers defines a CSP', str_contains($fragCsp, "default-src 'none'"));

// Every external origin the page references must appear in its CSP.
$origins = [];
preg_match_all('#(?:src|href)="https?://([^/"]+)#', $html, $mm);
foreach ($mm[1] as $origin) {
    $origins['https://' . $origin] = true;
}
check('page references at least one CDN', $origins !== []);
foreach (array_keys($origins) as $origin) {
    check("page CSP allows $origin", str_contains($pageCsp, $origin));
}

// --- Icons are self-hosted SVG images, covered by img-src 'self'. Nothing
//     loads a font any more, so the CSPs must NOT carry a font-src, and the
//     old Glyphicon font classes must be gone from the markup. ---
check('markup no longer uses glyphicon classes', !str_contains($html, 'glyphicon') && !str_contains($api, 'glyphicon'));

$icons = [];
preg_match_all('#img/(icon-[a-z-]+\.svg)#', $html . $api, $im);
foreach ($im[1] as $icon) {
    $icons[$icon] = true;
}
check('UI references self-hosted icon SVGs', $icons !== []);
foreach (array_keys($icons) as $icon) {
    check("icon asset exists: $icon", is_file($root . '/public/img/' . $icon));
}

check("page CSP allows own images", str_contains($pageCsp, "img-src 'self'"));
check('page CSP has no font-src', !str_contains($pageCsp, 'font-src'));
check('fragment CSP has no font-src', !str_contains($fragCsp, 'font-src'));

// --- Guardrails: the CSP must not silently allow everything ---
check(
    "page CSP does not fall back to anything",
    !str_contains($pageCsp, "default-src 'self'") && !str_contains($pageCsp, 'default-src *')
);
check("page CSP keeps frame-ancestors 'none'", str_contains($pageCsp, "frame-ancestors 'none'"));
check("fragment CSP keeps default-src 'none'", str_contains($fragCsp, "default-src 'none'"));
