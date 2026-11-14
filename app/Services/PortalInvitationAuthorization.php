<?php

namespace App\Services;

/** Internal contract; implementations must use trusted authorization rules. */
interface PortalInvitationAuthorization
{
    public function check(string $companyId): mixed;
}
