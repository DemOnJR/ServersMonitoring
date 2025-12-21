<?php
declare(strict_types=1);

namespace Server;

class ServerService
{
    public function __construct(
        private ServerRepository $repo
    ) {
    }

    /**
     * Sanitize and update display name
     */
    public function rename(int $id, string $name): void
    {
        $name = trim($name);

        if ($id <= 0) {
            throw new \InvalidArgumentException('Invalid server id');
        }

        if ($name === '') {
            return; // silently ignore empty
        }

        if (mb_strlen($name) > 64) {
            $name = mb_substr($name, 0, 64);
        }

        $this->repo->updateDisplayName($id, $name);
    }
}
