<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;

class SubscriptionAccess
{
    /** Preserve contracted access until a scheduled cancellation takes effect. */
    public static function usable(Builder $query, string $alias = 'subscription'): Builder
    {
        return $query->where(function (Builder $status) use ($alias): void {
            $status->where($alias.'.status', 'ativa')->orWhere(function (Builder $scheduled) use ($alias): void {
                $scheduled->where($alias.'.status', 'cancelamento_agendado')->where($alias.'.cancel_at', '>', now());
            });
        });
    }
}
