<?php
declare(strict_types=1);

require_once __DIR__ . '/project_path_helper.php';

function ItourComplaintEvidenceRoot(): string
{
    $configured = trim((string)(getenv('ITOUR_PRIVATE_STORAGE_DIR') ?: ''));
    if ($configured !== '' && !preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#D', $configured)) {
        throw new RuntimeException('ITOUR_PRIVATE_STORAGE_DIR must be an absolute path.');
    }
    $base = $configured !== ''
        ? rtrim($configured, "\\/")
        : ItourProjectPath('storage/private');
    return $base . DIRECTORY_SEPARATOR . 'complaints';
}

function ItourEnsureComplaintEvidenceRoot(): string
{
    $root = ItourComplaintEvidenceRoot();
    if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
        throw new RuntimeException('Complaint evidence storage is unavailable.');
    }
    return $root;
}

function ItourComplaintEvidenceReference(string $filename): string
{
    if (!preg_match('/^[A-Za-z0-9._-]+$/D', $filename)) {
        throw new InvalidArgumentException('Invalid complaint evidence filename.');
    }
    return 'private:' . $filename;
}

function ItourResolveComplaintEvidence(string $reference): ?string
{
    if (preg_match('/^private:([A-Za-z0-9._-]+)$/D', $reference, $matches)) {
        $root = ItourComplaintEvidenceRoot();
        $candidate = $root . DIRECTORY_SEPARATOR . $matches[1];
    } elseif (preg_match('#^uploads/complaints/([A-Za-z0-9._-]+)$#D', $reference, $matches)) {
        // Read-only compatibility for records created before private storage.
        $root = ItourProjectPath('uploads/complaints');
        $candidate = $root . DIRECTORY_SEPARATOR . $matches[1];
    } else {
        return null;
    }

    $resolvedRoot = realpath($root);
    $resolved = realpath($candidate);
    if (!$resolvedRoot || !$resolved || !is_file($resolved)
        || !str_starts_with(strtolower($resolved), strtolower($resolvedRoot . DIRECTORY_SEPARATOR))) {
        return null;
    }
    return $resolved;
}
