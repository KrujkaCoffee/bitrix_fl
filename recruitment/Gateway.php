<?php
declare(strict_types=1);

namespace Kelast\Recruitment;

interface Gateway
{
    public function preflight(int $actor, int $responsible): void;

    /** @return array{id: int, created: bool} */
    public function ensureVacancy(
        int $taskId,
        int $actor,
        int $responsible,
        array $canonicalValues,
        string $canonicalDescription
    ): array;
}
