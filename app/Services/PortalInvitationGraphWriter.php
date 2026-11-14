<?php

namespace App\Services;

/** Internal contract; implementations may perform local DB writes only. */
interface PortalInvitationGraphWriter
{
    public function create(?string $existingUserId): array;
}
