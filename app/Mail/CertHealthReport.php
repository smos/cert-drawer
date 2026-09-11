<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CertHealthReport extends Mailable
{
    use Queueable, SerializesModels;

    public array $changes;
    public array $expiryAlerts;
    public array $thresholds;

    public function __construct(array $changes, array $expiryAlerts = [])
    {
        $this->changes = $this->consolidateChanges($changes);
        $this->expiryAlerts = $this->consolidateExpiryAlerts($expiryAlerts);

        $settings = \App\Models\Setting::all()->pluck('value', 'key');
        $this->thresholds = [
            'yellow' => (int) ($settings['expiry_yellow'] ?? 30),
            'orange' => (int) ($settings['expiry_orange'] ?? 20),
            'red'    => (int) ($settings['expiry_red'] ?? 10),
        ];
    }

    protected function consolidateChanges(array $changes): array
    {
        $consolidated = [];
        foreach ($changes as $change) {
            $domainId = is_object($change['domain'] ?? null) 
                ? ($change['domain']->id ?? $change['domain']->name) 
                : ($change['domain']['id'] ?? $change['domain']['name'] ?? 'unknown');
            $checkType = $change['check_type'] ?? ($change['new']['check_type'] ?? 'unknown');
            $oldThumb = $change['old']['thumbprint_sha256'] ?? '';
            $newThumb = $change['new']['thumbprint_sha256'] ?? '';
            $oldError = $change['old']['error'] ?? '';
            $newError = $change['new']['error'] ?? '';

            $key = "{$domainId}_{$checkType}_{$oldThumb}_{$newThumb}_{$oldError}_{$newError}";

            $ips = [];
            if (!empty($change['ips']) && is_array($change['ips'])) {
                $ips = $change['ips'];
            } elseif (!empty($change['ip'])) {
                $ips = array_map('trim', explode(',', $change['ip']));
            }

            if (!isset($consolidated[$key])) {
                $change['check_type'] = $checkType;
                $change['ips'] = $ips;
                $change['ip'] = implode(', ', $ips);
                $consolidated[$key] = $change;
            } else {
                foreach ($ips as $ip) {
                    if (!in_array($ip, $consolidated[$key]['ips'])) {
                        $consolidated[$key]['ips'][] = $ip;
                    }
                }
                $consolidated[$key]['ip'] = implode(', ', $consolidated[$key]['ips']);
                if (!empty($change['has_mismatch'])) {
                    $consolidated[$key]['has_mismatch'] = true;
                }
            }
        }

        // Detect if any domain has multiple distinct active thumbprints WITHIN THE SAME check_type
        $byDomainAndType = [];
        foreach ($consolidated as $k => $c) {
            $domId = is_object($c['domain'] ?? null) 
                ? ($c['domain']->id ?? $c['domain']->name) 
                : ($c['domain']['id'] ?? $c['domain']['name'] ?? 'unknown');
            $type = $c['check_type'] ?? ($c['new']['check_type'] ?? 'unknown');
            $byDomainAndType["{$domId}_{$type}"][] = $k;
        }
        foreach ($byDomainAndType as $groupKey => $keys) {
            $thumbs = [];
            foreach ($keys as $k) {
                if (!empty($consolidated[$k]['new']['thumbprint_sha256']) && empty($consolidated[$k]['new']['error'])) {
                    $thumbs[] = $consolidated[$k]['new']['thumbprint_sha256'];
                }
            }
            if (count(array_unique($thumbs)) > 1) {
                foreach ($keys as $k) {
                    $consolidated[$k]['has_mismatch'] = true;
                }
            }
        }

        return array_values($consolidated);
    }

    protected function consolidateExpiryAlerts(array $expiryAlerts): array
    {
        $consolidated = [];
        foreach ($expiryAlerts as $alert) {
            $domainId = is_object($alert['domain'] ?? null) 
                ? ($alert['domain']->id ?? $alert['domain']->name) 
                : ($alert['domain']['id'] ?? $alert['domain']['name'] ?? 'unknown');
            $expiry = $alert['expiry'] ?? '';
            $days = $alert['days'] ?? '';

            $key = "{$domainId}_{$expiry}_{$days}";

            $ips = [];
            if (!empty($alert['ips']) && is_array($alert['ips'])) {
                $ips = $alert['ips'];
            } elseif (!empty($alert['ip'])) {
                $ips = array_map('trim', explode(',', $alert['ip']));
            }

            if (!isset($consolidated[$key])) {
                $alert['ips'] = $ips;
                $alert['ip'] = implode(', ', $ips);
                $consolidated[$key] = $alert;
            } else {
                foreach ($ips as $ip) {
                    if (!in_array($ip, $consolidated[$key]['ips'])) {
                        $consolidated[$key]['ips'][] = $ip;
                    }
                }
                $consolidated[$key]['ip'] = implode(', ', $consolidated[$key]['ips']);
            }
        }
        return array_values($consolidated);
    }

    public function build()
    {
        return $this->subject('Certificate Health Alert')
                    ->view('emails.cert_health');
    }
}
