<?php
declare(strict_types=1);

$zipPath = '/home/u934838461/domains/biographydisk.com/public_html/zip.zip';
$outputDir = '/home/u934838461/domains/biographydisk.com/public_html';

if (!class_exists(ZipArchive::class)) {
    exit("Error: ekstensi PHP ZipArchive belum aktif.\n");
}

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    exit("Error: file ZIP tidak bisa dibuka: {$zipPath}\n");
}

// Batasi jumlah file dan total ukuran hasil ekstraksi.
if ($zip->numFiles > 1000) {
    $zip->close();
    exit("Error: ZIP berisi terlalu banyak file.\n");
}

$totalSize = 0;

for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    $stat = $zip->statIndex($i);

    if ($name === false || $stat === false || str_contains($name, "\0")) {
        $zip->close();
        exit("Error: isi ZIP tidak valid.\n");
    }

    $name = str_replace('\\', '/', $name);

    // Tolak path absolut, drive Windows, dan path traversal seperti ../
    if (
        str_starts_with($name, '/') ||
        preg_match('/^[A-Za-z]:/', $name) ||
        in_array('..', explode('/', $name), true)
    ) {
        $zip->close();
        exit("Error: ZIP berisi path yang tidak aman.\n");
    }

    // Tolak symbolic link.
    $opsys = 0;
    $attributes = 0;
    if (
        $zip->getExternalAttributesIndex($i, $opsys, $attributes) &&
        $opsys === ZipArchive::OPSYS_UNIX &&
        (($attributes >> 16) & 0170000) === 0120000
    ) {
        $zip->close();
        exit("Error: ZIP berisi symbolic link.\n");
    }

    $totalSize += (int) $stat['size'];
    if ($totalSize > 250 * 1024 * 1024) {
        $zip->close();
        exit("Error: ukuran hasil ekstraksi melebihi 250 MB.\n");
    }
}

if (!is_dir($outputDir) && !mkdir($outputDir, 0700, true)) {
    $zip->close();
    exit("Error: folder tujuan tidak bisa dibuat.\n");
}

if (!$zip->extractTo($outputDir)) {
    $zip->close();
    exit("Error: proses ekstraksi gagal.\n");
}

$zip->close();
echo "Berhasil diekstrak ke: {$outputDir}\n";