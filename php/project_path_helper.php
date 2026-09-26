<?php
declare(strict_types=1);

/**
 * Cross-platform project filesystem paths for runtime media.
 *
 * Database and browser values must stay project-relative (for example,
 * uploads/boat_1_img1.png). Absolute paths returned here are filesystem-only
 * and must never be persisted.
 */

function ItourProjectRoot(): string
{
    static $root = null;
    if ($root === null) {
        $resolved = realpath(dirname(__DIR__));
        if ($resolved === false || !is_dir($resolved)) {
            throw new RuntimeException('The application root could not be resolved.');
        }
        $root = rtrim($resolved, "\\/");
    }
    return $root;
}

function ItourProjectRelativePath(string $relativePath): string
{
    $relativePath = trim(str_replace('\\', '/', $relativePath));
    if ($relativePath === '' || str_contains($relativePath, "\0")
        || str_starts_with($relativePath, '/')
        || preg_match('/^[A-Za-z]:\//D', $relativePath)) {
        throw new InvalidArgumentException('A project-relative path is required.');
    }

    $segments = explode('/', $relativePath);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new InvalidArgumentException('The project-relative path is invalid.');
        }
    }
    return implode('/', $segments);
}

function ItourProjectPath(string $relativePath): string
{
    $relativePath = ItourProjectRelativePath($relativePath);
    return ItourProjectRoot() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}

function ItourPathIsInside(string $path, string $root): bool
{
    $path = rtrim($path, "\\/");
    $root = rtrim($root, "\\/");
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $root = strtolower($root);
    }
    return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
}

function ItourEnsureProjectDirectory(string $relativeDirectory, int $mode = 0755): string
{
    $relativeDirectory = ItourProjectRelativePath($relativeDirectory);
    $directory = ItourProjectPath($relativeDirectory);
    $root = ItourProjectRoot();

    // Validate the nearest existing parent before creating anything. This also
    // prevents an upload directory symlink from escaping the application root.
    $existingParent = $directory;
    while (!file_exists($existingParent)) {
        $parent = dirname($existingParent);
        if ($parent === $existingParent) break;
        $existingParent = $parent;
    }
    $resolvedParent = realpath($existingParent);
    if ($resolvedParent === false || !ItourPathIsInside($resolvedParent, $root)) {
        throw new RuntimeException('The requested media directory is outside the application root.');
    }

    if (!is_dir($directory) && !mkdir($directory, $mode, true) && !is_dir($directory)) {
        throw new RuntimeException('The media directory could not be created.');
    }
    $resolvedDirectory = realpath($directory);
    if ($resolvedDirectory === false || !ItourPathIsInside($resolvedDirectory, $root)) {
        throw new RuntimeException('The requested media directory is unavailable.');
    }
    return $resolvedDirectory;
}

/**
 * Confirm that newly generated media is readable and lives below the web
 * document root. This prevents an upload handler from reporting success when
 * a deployment points runtime storage at a non-public release directory.
 */
function ItourAssertPublicMediaFile(string $absolutePath): void
{
    $resolvedFile = realpath($absolutePath);
    $size = $resolvedFile !== false ? @filesize($resolvedFile) : false;
    if ($resolvedFile === false || !is_file($resolvedFile) || !is_readable($resolvedFile)
        || $size === false || $size < 1) {
        throw new RuntimeException('The optimized image could not be verified after saving.');
    }

    if (PHP_SAPI === 'cli') return;
    $documentRootValue = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $documentRoot = $documentRootValue !== '' ? realpath($documentRootValue) : false;
    if ($documentRoot === false || !ItourPathIsInside($resolvedFile, $documentRoot)) {
        throw new RuntimeException('The image storage directory is not publicly available on this server.');
    }
}
