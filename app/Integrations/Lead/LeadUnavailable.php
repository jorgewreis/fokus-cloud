<?php

namespace App\Integrations\Lead;

use Symfony\Component\HttpKernel\Exception\HttpException;

class LeadUnavailable extends HttpException
{
    public function __construct(public readonly string $reason = 'unavailable')
    {
        parent::__construct(503, 'O acesso aos dados do Fokus Lead está temporariamente indisponível.');
    }
}
