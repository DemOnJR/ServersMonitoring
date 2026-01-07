<?php
declare(strict_types=1);

namespace Server;

use InvalidArgumentException;

/**
 * Application service for server-related write operations.
 *
 * Encapsulates validation and normalization logic before delegating
 * persistence to the repository layer.
 */
class ServerService
{
    /**
     * ServerService constructor.
     *
     * @param ServerRepository $repo Server repository.
     */
    public function __construct(
        private ServerRepository $repo
    ) {
    }

    /**
     * Renames a server display name.
     *
     * Applies basic validation and length normalization before persisting.
     * Empty names are ignored to avoid accidental data loss from UI glitches.
     *
     * @param int $id Server id.
     * @param string $name New display name.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the server id is invalid.
     */
    public function rename(int $id, string $name): void
    {
        $name = trim($name);

        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid server id');
        }

        if ($name === '') {
            return;
        }

        if (mb_strlen($name) > 64) {
            $name = mb_substr($name, 0, 64);
        }

        $this->repo->updateDisplayName($id, $name);
    }
}
