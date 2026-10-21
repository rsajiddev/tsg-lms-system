<?php
declare(strict_types=1);
namespace App\Core;

use PDO;
use RuntimeException;

final class RateLimiter
{
    public function __construct(private PDO $db) {}

    public function assertAllowed(string $action, array $subjects, int $limit, int $windowSeconds): void
    {
        $query = $this->db->prepare(
            'SELECT COUNT(*) FROM auth_rate_limits WHERE action = ? AND subject_hash = ? AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)'
        );
        foreach ($subjects as $subject) {
            $query->execute([$action, $this->hash((string) $subject), $windowSeconds]);
            if ((int) $query->fetchColumn() >= $limit) {
                throw new RuntimeException('Too many requests. Please wait and try again.');
            }
        }
    }

    public function record(string $action, array $subjects): void
    {
        $insert = $this->db->prepare('INSERT INTO auth_rate_limits (action, subject_hash, created_at) VALUES (?, ?, UTC_TIMESTAMP())');
        foreach ($subjects as $subject) {
            $insert->execute([$action, $this->hash((string) $subject)]);
        }
    }

    public function clear(string $action, array $subjects): void
    {
        $delete = $this->db->prepare('DELETE FROM auth_rate_limits WHERE action = ? AND subject_hash = ?');
        foreach ($subjects as $subject) {
            $delete->execute([$action, $this->hash((string) $subject)]);
        }
    }

    private function hash(string $subject): string
    {
        return hash('sha256', $subject);
    }
}
