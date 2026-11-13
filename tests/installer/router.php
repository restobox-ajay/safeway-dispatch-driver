<?php
// Fake GitHub API for tests/installer/e2e.sh: /repos/x/y/releases/latest + /releases/assets/{1|2}.
// $FAKE_RELEASES_DIR/current names the served version; a file called `corrupt` there makes the tarball differ from its checksum.
$dir = getenv('FAKE_RELEASES_DIR');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer fake') {
    http_response_code(401);
    exit;
}
$version = trim((string) @file_get_contents("$dir/current"));
$path    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#/releases/latest$#', $path)) {
    header('Content-Type: application/json');
    echo json_encode(['tag_name' => "v$version", 'assets' => [
        ['id' => 1, 'name' => "symfony-auth-boilerplate-$version.tar.gz"],
        ['id' => 2, 'name' => "symfony-auth-boilerplate-$version.tar.gz.sha256"],
    ]]);
} elseif (preg_match('#/releases/assets/([12])$#', $path, $m)) {
    readfile("$dir/symfony-auth-boilerplate-$version.tar.gz" . ($m[1] === '2' ? '.sha256' : ''));
    if ($m[1] === '1' && is_file("$dir/corrupt")) {
        echo 'tampered';
    }
} else {
    http_response_code(404);
}
