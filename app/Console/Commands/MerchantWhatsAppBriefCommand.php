<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WhatsAppMerchantSession;
use App\Services\MerchantWhatsAppService;
use Illuminate\Console\Command;

class MerchantWhatsAppBriefCommand extends Command
{
    protected $signature = 'storehause:merchant-whatsapp-brief';

    protected $description = 'Send a morning WhatsApp brief to merchants with an open 24-hour window';

    public function handle(MerchantWhatsAppService $whatsapp): int
    {
        $sessions = WhatsAppMerchantSession::query()
            ->whereNotNull('user_id')
            ->where('last_inbound_at', '>=', now()->subDays(7))
            ->get();

        $sent = 0;
        foreach ($sessions as $session) {
            if ($whatsapp->sendDailyBrief($session)) {
                $sent++;
            }
        }

        $this->info("Sent {$sent} morning brief(s).");

        return self::SUCCESS;
    }
}
