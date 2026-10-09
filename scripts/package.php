<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$output = $argv[1] ?? null;
if (!is_string($output) || preg_match('#\Adist/[A-Za-z0-9._-]+\.zip\z#', $output) !== 1) {
    fwrite(STDERR, "Usage: php scripts/package.php dist/<artifact>.zip\n");
    exit(2);
}
if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "The PHP zip extension is required.\n");
    exit(2);
}

$files = ['composer.json', 'LICENSE', 'README.md', 'SECURITY.md', 'config/services.php', 'config/routes/customer_api.php'];
$source = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS),
);
foreach ($source as $file) {
    $customerApiResource = preg_match('#/src/CustomerApi/resources/(customer-api-operations\.json|admin-ui\.html)\z#', str_replace('\\', '/', $file->getPathname())) === 1;
    if (!$file->isFile() || $file->isLink() || ($file->getExtension() !== 'php' && !$customerApiResource)) {
        fwrite(STDERR, "Refusing unexpected source entry.\n");
        exit(1);
    }
    $files[] = substr($file->getPathname(), strlen($root) + 1);
}
sort($files, SORT_STRING);

$secretPattern = '/(?:-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|SENDREPUTE_API_(?:KEY|TOKEN)\s*=\s*\S+|Bearer\s+[A-Za-z0-9._~+\/=-]{16,})/i';
$contents = [];
foreach ($files as $relative) {
    $path = $root.'/'.$relative;
    if (!is_file($path) || is_link($path)) {
        fwrite(STDERR, "Refusing missing or linked allowlisted file: {$relative}\n");
        exit(1);
    }
    $value = file_get_contents($path);
    if (!is_string($value) || preg_match($secretPattern, $value) === 1) {
        fwrite(STDERR, "Refusing possible credential material in: {$relative}\n");
        exit(1);
    }
    $contents[$relative] = $value;
}

$destination = $root.'/'.$output;
if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0775, true) && !is_dir(dirname($destination))) {
    fwrite(STDERR, "Unable to create output directory.\n");
    exit(1);
}
$zip = new ZipArchive();
if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Unable to create archive.\n");
    exit(1);
}
foreach ($contents as $relative => $value) {
    $name = 'sendrepute-symfony/'.$relative;
    if (!$zip->addFromString($name, $value) || !$zip->setMtimeName($name, 946684800)) {
        $zip->close();
        @unlink($destination);
        fwrite(STDERR, "Unable to add allowlisted file: {$relative}\n");
        exit(1);
    }
}
$zip->close();
fwrite(STDOUT, "Created unpublished local artifact: {$destination}\n");