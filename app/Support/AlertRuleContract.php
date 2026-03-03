<?php

declare(strict_types=1);

namespace App\Support;

final class AlertRuleContract
{
    /**
     * @var list<string>
     */
    public const METRICS = [
        'sync_failed_24h',
        'sync_warning_24h',
        'unattributed_orders_24h',
        'unpaid_balance',
        'pending_payouts',
    ];

    /**
     * @var list<string>
     */
    public const OPERATORS = ['>', '>=', '<', '<=', '==', '!='];

    /**
     * @var list<string>
     */
    public const CHANNELS = ['in_app', 'telegram', 'both'];
}

