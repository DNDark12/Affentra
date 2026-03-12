<?php

declare(strict_types=1);

namespace App\Jobs\Tracking;

use App\Contracts\Repositories\ClickRepositoryInterface;
use App\Contracts\Repositories\TrackingLinkRepositoryInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class RecordClickJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Max retry attempts.
     */
    public int $tries = 3;

    /**
     * Backoff between retries (seconds).
     */
    public int $backoff = 5;

    /**
     * @param  array<string, mixed>  $clickData   Click record data (tracking_link_id, sub_id, ip, user_agent, referer)
     * @param  int                   $linkId      TrackingLink ID to increment clicks_count
     */
    public function __construct(
        private readonly array $clickData,
        private readonly int $linkId,
    ) {}

    public function handle(
        ClickRepositoryInterface $clickRepository,
        TrackingLinkRepositoryInterface $trackingLinkRepository,
    ): void {
        $link = \App\Models\TrackingLink::with('user')->find($this->linkId);
        
        if (! $link || ! $link->user) {
            return; // Soft fail if link or user is deleted before job runs
        }

        $user = $link->user;
        
        // Resolve scope keys
        $ownerId = null;
        $leaderId = null;
        $partnerUserId = null;

        if ($user->isOwner()) {
            $ownerId = $user->id;
        } elseif ($user->isLeader()) {
            $ownerId = $user->parent_id ?? $user->id; // Fallback if schema is somehow malformed
            $leaderId = $user->id;
        } elseif ($user->isPartner()) {
            $partnerUserId = $user->id;
            $leader = $user->parent;
            if ($leader) {
                $leaderId = $leader->id;
                $ownerId = $leader->parent_id ?? $leader->id;
            }
        }

        // Basic Bot Detection
        $ua = $this->clickData['user_agent'] ?? '';
        $isBot = false;
        $botReason = null;
        
        $botPatterns = ['bot', 'crawler', 'spider', 'slurp', 'mediapartners', 'lighthouse', 'whatsapp', 'telegram', 'facebook', 'twitter'];
        foreach ($botPatterns as $pattern) {
            if (stripos($ua, $pattern) !== false) {
                $isBot = true;
                $botReason = 'matched_ua_pattern: ' . $pattern;
                break;
            }
        }
        if (trim($ua) === '') {
            $isBot = true;
            $botReason = 'empty_user_agent';
        }

        // Device Type
        $deviceType = 'desktop';
        if (preg_match('/(android|bb\d+|meego).+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows ce|xda|xiino/i', $ua)) {
            $deviceType = 'mobile';
        } elseif (preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i', substr($ua, 0, 4))) {
            $deviceType = 'mobile';
        } elseif (preg_match('/ipad|tablet|playbook|silk/i', $ua)) {
            $deviceType = 'tablet';
        }

        // Referer Domain
        $referer = $this->clickData['referer'] ?? '';
        $refererDomain = null;
        if (!empty($referer)) {
            $host = parse_url($referer, PHP_URL_HOST);
            if ($host) {
                // remove www.
                $refererDomain = preg_replace('/^www\./', '', $host);
            }
        }

        // Fingerprint Hash (IP + UA + version)
        $ip = $this->clickData['ip'] ?? '';
        $plainText = $ip . '|' . ltrim(substr($ua, 0, 150)); // Avoid huge UA poisoning
        $fingerprintHash = hash_hmac('sha256', $plainText, config('app.key'));
        
        // UTM Normalization & Sanitization
        $rawUtmSource   = $this->clickData['utm_source'] ?? null;
        $rawUtmMedium   = $this->clickData['utm_medium'] ?? null;
        $rawUtmCampaign = $this->clickData['utm_campaign'] ?? null;
        $rawUtmTerm     = $this->clickData['utm_term'] ?? null;
        $rawUtmContent  = $this->clickData['utm_content'] ?? null;

        // Clean values for physical column indexing
        $cleanUtmSource   = empty(trim((string)$rawUtmSource)) ? null : Str::lower(trim((string)$rawUtmSource));
        $cleanUtmMedium   = empty(trim((string)$rawUtmMedium)) ? null : Str::lower(trim((string)$rawUtmMedium));
        $cleanUtmCampaign = empty(trim((string)$rawUtmCampaign)) ? null : trim((string)$rawUtmCampaign); // Preserve case for campaign
        
        // Store everything in source meta for audit/raw access
        $sourceMeta = [
            'utm_source'   => $rawUtmSource,
            'utm_medium'   => $rawUtmMedium,
            'utm_campaign' => $rawUtmCampaign,
            'utm_term'     => $rawUtmTerm,
            'utm_content'  => $rawUtmContent,
        ];

        // Filter out nulls to save space in JSON
        $sourceMeta = array_filter($sourceMeta, fn($val) => $val !== null && $val !== '');
        
        $enrichedData = array_merge($this->clickData, [
            'owner_id' => $ownerId,
            'leader_id' => $leaderId,
            'partner_user_id' => $partnerUserId,
            'utm_source' => $cleanUtmSource,
            'utm_medium' => $cleanUtmMedium,
            'utm_campaign' => $cleanUtmCampaign,
            'fingerprint_hash' => $fingerprintHash,
            'hash_version' => 1,
            'is_bot' => $isBot,
            'bot_reason' => $botReason,
            'device_type' => $deviceType,
            'referer_domain' => $refererDomain,
            'source_meta' => empty($sourceMeta) ? null : json_encode($sourceMeta),
        ]);

        $clickRepository->insertClick($enrichedData);
        $trackingLinkRepository->incrementClicksCount($this->linkId);
    }
}
