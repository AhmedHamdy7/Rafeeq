<?php

namespace App\Domains\Verification\Contracts;

/**
 * The seam for a real scanning engine (ClamAV or a managed service).
 *
 * Chapter 3 §14 requires uploads to be scanned and executables refused. A
 * document stays unusable — it cannot be submitted for review, and no admin
 * screen will open it — until a scanner has said `clean`, so the absence of a
 * real engine blocks the flow rather than quietly allowing it.
 */
interface VirusScanner
{
    /**
     * @param  string  $contents  the raw bytes, already read into memory
     * @return bool true when the file is safe to keep
     */
    public function isClean(string $contents, string $originalName): bool;
}
