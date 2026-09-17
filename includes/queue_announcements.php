<?php

function ensureQueueAnnouncementTable(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS queue_announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            purpose ENUM('walk_in','appointment','document_claim') NOT NULL,
            ticket_number VARCHAR(10) NOT NULL,
            window_number INT NOT NULL,
            status ENUM('pending','playing','completed') NOT NULL DEFAULT 'pending',
            requested_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            started_at TIMESTAMP(6) NULL DEFAULT NULL,
            completed_at TIMESTAMP(6) NULL DEFAULT NULL,
            INDEX idx_status_requested (status, requested_at),
            INDEX idx_purpose_status (purpose, status)
        ) ENGINE=InnoDB"
    );
}

function queueAnnouncementDaySql(string $column = 'requested_at'): string
{
    return "DATE($column) = CURDATE()";
}

function queueRecoverStaleAnnouncements(PDO $pdo): void
{
    ensureQueueAnnouncementTable($pdo);
    $day = queueAnnouncementDaySql('requested_at');
    $pdo->exec(
        "UPDATE queue_announcements
         SET status = 'completed', completed_at = NOW(6)
         WHERE status = 'playing'
           AND $day
           AND started_at IS NOT NULL
           AND started_at < NOW(6) - INTERVAL 90 SECOND"
    );
}

function queuePurposeHasActiveAnnouncement(PDO $pdo, string $purpose): bool
{
    ensureQueueAnnouncementTable($pdo);
    $day = queueAnnouncementDaySql('requested_at');
    $stmt = $pdo->prepare(
        "SELECT 1 FROM queue_announcements
         WHERE purpose = ? AND status IN ('pending', 'playing') AND $day
         LIMIT 1"
    );
    $stmt->execute([$purpose]);

    return (bool) $stmt->fetchColumn();
}

function queueFormatAnnouncementRow(array $row): array
{
    return [
        'id'             => (int) $row['id'],
        'purpose'        => (string) $row['purpose'],
        'ticket_number'  => (string) $row['ticket_number'],
        'window_number'  => (int) $row['window_number'],
        'status'         => (string) $row['status'],
        'requested_at'   => (string) ($row['requested_at'] ?? ''),
        'started_at'     => (string) ($row['started_at'] ?? ''),
        'completed_at'   => (string) ($row['completed_at'] ?? ''),
    ];
}

function queueEnqueueAnnouncement(PDO $pdo, string $purpose, string $ticketNumber, int $windowNumber, bool $allowAfterPlaying = false): bool
{
    if (!isset(queuePurposeConfig()[$purpose])) {
        throw new InvalidArgumentException('Invalid queue purpose.');
    }

    ensureQueueAnnouncementTable($pdo);
    queueRecoverStaleAnnouncements($pdo);

    $ticketNumber = trim($ticketNumber);
    if ($ticketNumber === '') {
        return false;
    }

    $day = queueAnnouncementDaySql('requested_at');
    $pdo->beginTransaction();
    try {
        $activeStmt = $pdo->prepare(
            "SELECT id, status, ticket_number FROM queue_announcements
             WHERE purpose = ? AND status IN ('pending', 'playing') AND $day
             FOR UPDATE"
        );
        $activeStmt->execute([$purpose]);
        $activeRows = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

        $playingRow = null;
        $pendingRow = null;
        foreach ($activeRows as $row) {
            if ($row['status'] === 'playing' && $playingRow === null) {
                $playingRow = $row;
            }
            if ($row['status'] === 'pending' && $pendingRow === null) {
                $pendingRow = $row;
            }
        }

        if ($pendingRow) {
            if ($pendingRow['ticket_number'] === $ticketNumber) {
                $pdo->commit();

                return false;
            }

            $pdo->prepare(
                'UPDATE queue_announcements
                 SET ticket_number = ?, window_number = ?, requested_at = NOW(6)
                 WHERE id = ?'
            )->execute([$ticketNumber, $windowNumber, (int) $pendingRow['id']]);
            $pdo->commit();

            return true;
        }

        if ($playingRow) {
            if ($playingRow['ticket_number'] === $ticketNumber && !$allowAfterPlaying) {
                $pdo->commit();

                return false;
            }

            if ($playingRow['ticket_number'] === $ticketNumber && $allowAfterPlaying) {
                $pdo->prepare(
                    'INSERT INTO queue_announcements (purpose, ticket_number, window_number, status)
                     VALUES (?, ?, ?, ?)'
                )->execute([$purpose, $ticketNumber, $windowNumber, 'pending']);
                $pdo->commit();

                return true;
            }

            if ($playingRow['ticket_number'] !== $ticketNumber) {
                $pdo->prepare(
                    'INSERT INTO queue_announcements (purpose, ticket_number, window_number, status)
                     VALUES (?, ?, ?, ?)'
                )->execute([$purpose, $ticketNumber, $windowNumber, 'pending']);
                $pdo->commit();

                return true;
            }

            $pdo->commit();

            return false;
        }

        $pdo->prepare(
            'INSERT INTO queue_announcements (purpose, ticket_number, window_number, status)
             VALUES (?, ?, ?, ?)'
        )->execute([$purpose, $ticketNumber, $windowNumber, 'pending']);
        $pdo->commit();

        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function queueFetchPlayingAnnouncement(PDO $pdo): ?array
{
    ensureQueueAnnouncementTable($pdo);
    queueRecoverStaleAnnouncements($pdo);

    $day = queueAnnouncementDaySql('requested_at');
    $stmt = $pdo->query(
        "SELECT id, purpose, ticket_number, window_number, status, requested_at, started_at, completed_at
         FROM queue_announcements
         WHERE status = 'playing' AND $day
         ORDER BY started_at ASC, id ASC
         LIMIT 1"
    );
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

    return $row ? queueFormatAnnouncementRow($row) : null;
}

function queueCountPendingAnnouncements(PDO $pdo): int
{
    ensureQueueAnnouncementTable($pdo);
    $day = queueAnnouncementDaySql('requested_at');

    return (int) $pdo->query(
        "SELECT COUNT(*) FROM queue_announcements WHERE status = 'pending' AND $day"
    )->fetchColumn();
}

function queueFetchFirstPendingAnnouncement(PDO $pdo): ?array
{
    ensureQueueAnnouncementTable($pdo);
    $day = queueAnnouncementDaySql('requested_at');
    $stmt = $pdo->query(
        "SELECT id, purpose, ticket_number, window_number, status, requested_at, started_at, completed_at
         FROM queue_announcements
         WHERE status = 'pending' AND $day
         ORDER BY requested_at ASC, id ASC
         LIMIT 1"
    );
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

    return $row ? queueFormatAnnouncementRow($row) : null;
}

function queueClaimNextAnnouncement(PDO $pdo): ?array
{
    ensureQueueAnnouncementTable($pdo);
    queueRecoverStaleAnnouncements($pdo);

    $day = queueAnnouncementDaySql('requested_at');
    $pdo->beginTransaction();
    try {
        $playingStmt = $pdo->query(
            "SELECT id, purpose, ticket_number, window_number, status, requested_at, started_at, completed_at
             FROM queue_announcements
             WHERE status = 'playing' AND $day
             ORDER BY started_at ASC, id ASC
             LIMIT 1 FOR UPDATE"
        );
        $playing = $playingStmt ? $playingStmt->fetch(PDO::FETCH_ASSOC) : false;
        if ($playing) {
            $pdo->commit();

            return queueFormatAnnouncementRow($playing);
        }

        $pendingStmt = $pdo->query(
            "SELECT id, purpose, ticket_number, window_number, status, requested_at, started_at, completed_at
             FROM queue_announcements
             WHERE status = 'pending' AND $day
             ORDER BY requested_at ASC, id ASC
             LIMIT 1 FOR UPDATE"
        );
        $pending = $pendingStmt ? $pendingStmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!$pending) {
            $pdo->commit();

            return null;
        }

        $pdo->prepare(
            "UPDATE queue_announcements
             SET status = 'playing', started_at = NOW(6)
             WHERE id = ?"
        )->execute([(int) $pending['id']]);
        $pdo->commit();

        $pending['status'] = 'playing';
        $pending['started_at'] = date('Y-m-d H:i:s.u');

        return queueFormatAnnouncementRow($pending);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function queueCompleteAnnouncement(PDO $pdo, int $announcementId): bool
{
    ensureQueueAnnouncementTable($pdo);
    $day = queueAnnouncementDaySql('requested_at');
    $stmt = $pdo->prepare(
        "UPDATE queue_announcements
         SET status = 'completed', completed_at = NOW(6)
         WHERE id = ? AND status = 'playing' AND $day"
    );
    $stmt->execute([$announcementId]);

    return $stmt->rowCount() > 0;
}

function queueAnnouncementRevision(PDO $pdo): string
{
    ensureQueueAnnouncementTable($pdo);
    $day = queueAnnouncementDaySql('requested_at');
    $row = $pdo->query(
        "SELECT
            COALESCE(
                MAX(UNIX_TIMESTAMP(COALESCE(completed_at, started_at, requested_at))),
                0
            ) AS max_ts,
            SUM(status IN ('pending', 'playing')) AS active_count
         FROM queue_announcements
         WHERE $day"
    )->fetch(PDO::FETCH_ASSOC);

    return ((int) ($row['max_ts'] ?? 0)) . ':' . ((int) ($row['active_count'] ?? 0));
}

function queueFetchAnnouncementSnapshot(PDO $pdo): array
{
    $active = queueFetchPlayingAnnouncement($pdo);
    $pendingCount = queueCountPendingAnnouncements($pdo);

    return [
        'active'         => $active,
        'pending_count'  => $pendingCount,
        'revision'       => queueAnnouncementRevision($pdo),
    ];
}
