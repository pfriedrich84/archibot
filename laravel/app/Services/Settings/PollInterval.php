<?php

namespace App\Services\Settings;

use App\Models\AppSetting;

class PollInterval
{
    public function seconds(): int
    {
        $configured = AppSetting::getValue('worker.poll_interval_seconds');

        return max(0, (int) ($configured ?? config('archibot.poll_interval_seconds', 600)));
    }
}
