<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * The private upload folders under storage/uploads/private.
 *
 * Uploads are written by whichever account runs the application, and on a development machine
 * that is usually two of them: Apache, and the command line that runs the smoke tests. A folder
 * created by one at its default mode is not writable by the other, and PHP then reports the
 * failure as "the file could not be stored" - which points at the student's upload instead of at
 * the folder. So the folder is checked before the move, and the message names the folder.
 */
final class PrivateStorage
{
    /**
     * Absolute path of one upload folder, created when it is missing.
     *
     * @param string $folder the folder name students' files are filed under, such as `assignments`
     */
    public static function directory(string $folder): string
    {
        // The folder name reaches the filesystem, so only the shapes this application writes are accepted.
        if (preg_match('#^[a-z][a-z-]{1,31}$#', $folder) !== 1) {
            throw new RuntimeException('The requested upload folder is not available.');
        }
        $directory = BASE_PATH . '/storage/uploads/private/' . $folder;
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('The private upload folder storage/uploads/private/' . $folder . ' could not be created, so nothing could be saved in it. Give the account that serves the site write access to storage/uploads/private.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException(self::unavailable($directory));
        }
        return $directory;
    }

    private static function unavailable(string $directory): string
    {
        $ownerId = fileowner($directory);
        $ownerName = 'unknown';
        if ($ownerId !== false) {
            $ownerName = (string) $ownerId;
            if (function_exists('posix_getpwuid')) {
                $account = posix_getpwuid($ownerId);
                if (is_array($account) && isset($account['name'])) $ownerName = (string) $account['name'];
            }
        }
        return sprintf(
            'The private upload folder %s belongs to %s and is not writable by the account running this page, so nothing could be saved. Point the web server at a folder it can write to, or let this account write into that one.',
            str_replace(BASE_PATH . '/', '', $directory),
            $ownerName
        );
    }
}
