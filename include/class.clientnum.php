<?php
/**
 * ClientnumReservation
 *
 * Filesystem-based reservation system for client numbers ("num usager").
 *
 * Goals:
 * - Prevent two staff members from getting the same clientnum when opening
 *   the "new user" form at the same time.
 * - Allow the same staff member to keep the same reserved number if they
 *   navigate away and come back to the new user form (without having
 *   actually created the user yet).
 * - No database schema changes.
 *
 * Reservations are stored as files in:
 *   include/tmp/clientnum-reservations/00019261.res
 *
 * Each file contains a small JSON payload with staff_id, session_id and timestamp.
 */

class ClientnumReservation
{
    const RES_DIR = INCLUDE_DIR . 'tmp/clientnum-reservations/';
    const LOCK_FILE = self::RES_DIR . '.lock';
    const EXPIRATION = 600; // 10 minutes

    /**
     * Returns a reserved clientnum for this staff + session.
     * If the staff/session already has an active reservation, it is returned.
     * Otherwise a new number is reserved (under lock).
     */
    public static function getOrReserve($staffId, $sessionId)
    {
        if (!$staffId || !$sessionId) {
            return self::getNextFromDb();
        }

        // Occasional cleanup (cheap enough)
        if (mt_rand(1, 50) === 1) {
            self::cleanupStale();
        }

        $lock = self::acquireLock();
        if (!$lock) {
            return self::getNextFromDb();
        }

        try {
            $existing = self::findReservationForSession($staffId, $sessionId);
            if ($existing) {
                return $existing;
            }

            $next = self::computeNextAvailableNumber();
            self::writeReservation($next, $staffId, $sessionId);

            return $next;

        } finally {
            self::releaseLock($lock);
        }
    }

    /**
     * Called when a user is successfully created with this clientnum.
     * Removes the reservation so the number is no longer "pending".
     */
    public static function release($clientnum)
    {
        $file = self::getReservationFile($clientnum);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Cleanup old/stale reservation files.
     * Can be called from a cron or occasionally on form load.
     */
    public static function cleanupStale()
    {
        if (!is_dir(self::RES_DIR)) {
            return;
        }

        $now = time();
        $files = glob(self::RES_DIR . '*.res');

        foreach ($files as $file) {
            $data = @json_decode(file_get_contents($file), true);
            if (!$data || !isset($data['reserved_at'])) {
                @unlink($file);
                continue;
            }
            if (($now - $data['reserved_at']) > self::EXPIRATION) {
                @unlink($file);
            }
        }
    }

    // -------------------- Internal helpers --------------------

    private static function acquireLock()
    {
        if (!is_dir(self::RES_DIR)) {
            @mkdir(self::RES_DIR, 0755, true);
        }

        $fp = @fopen(self::LOCK_FILE, 'c+');
        if (!$fp) {
            return false;
        }

        // Try to get lock, wait up to ~2 seconds
        $start = microtime(true);
        while (!flock($fp, LOCK_EX | LOCK_NB)) {
            usleep(100000); // 100ms
            if ((microtime(true) - $start) > 2) {
                fclose($fp);
                return false;
            }
        }

        return $fp;
    }

    private static function releaseLock($fp)
    {
        if (is_resource($fp)) {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private static function getReservationFile($clientnum)
    {
        return self::RES_DIR . str_pad($clientnum, 8, '0', STR_PAD_LEFT) . '.res';
    }

    private static function writeReservation($clientnum, $staffId, $sessionId)
    {
        $data = [
            'staff_id'    => (int)$staffId,
            'session_id'  => $sessionId,
            'reserved_at' => time(),
        ];

        $file = self::getReservationFile($clientnum);
        @file_put_contents($file, json_encode($data));
    }

    private static function findReservationForSession($staffId, $sessionId)
    {
        $files = glob(self::RES_DIR . '*.res');
        $now = time();

        foreach ($files as $file) {
            $data = @json_decode(@file_get_contents($file), true);
            if (!$data) continue;

            if ((int)$data['staff_id'] === (int)$staffId &&
                $data['session_id'] === $sessionId &&
                ($now - $data['reserved_at']) < self::EXPIRATION) {
                // extract number from filename
                if (preg_match('/(\d{8})\.res$/', $file, $m)) {
                    return $m[1];
                }
            }
        }
        return null;
    }

    private static function computeNextAvailableNumber()
    {
        // Get current max from database
        $sql = "SELECT clientnum FROM " . USER_CDATA_TABLE .
               " WHERE clientnum IS NOT NULL AND clientnum != '' " .
               " ORDER BY clientnum + 0 DESC LIMIT 1";
        $res = db_query($sql, true);
        $max = 0;
        if ($row = db_fetch_row($res)) {
            $max = (int)trim($row[0]);
        }

        $candidate = $max + 1;

        // Collect currently reserved numbers
        $reserved = [];
        $files = glob(self::RES_DIR . '*.res');
        $now = time();

        foreach ($files as $file) {
            if (preg_match('/(\d{8})\.res$/', $file, $m)) {
                $num = (int)$m[1];
                $data = @json_decode(@file_get_contents($file), true);
                if ($data && ($now - $data['reserved_at']) < self::EXPIRATION) {
                    $reserved[$num] = true;
                } else {
                    // stale file, clean it
                    @unlink($file);
                }
            }
        }

        // Find first free slot starting from candidate
        while (isset($reserved[$candidate])) {
            $candidate++;
        }

        return str_pad((string)$candidate, 8, '0', STR_PAD_LEFT);
    }

    private static function getNextFromDb()
    {
        // Fallback when we cannot reserve (no lock, etc.)
        $user = new User();
        return $user->getNewClientNum();
    }
}
