<?php
// src/Mpesa.php — per-tenant M-Pesa (Daraja) STK push + voucher issuance.
// Each tenant supplies their OWN Daraja credentials (tenant_settings), so
// funds settle directly to the tenant. Ported from the single-tenant
// initiate_payment.php / mpesa_callback.php with tenant scoping added.

class Mpesa
{
    public function __construct(private PDO $pdo, private array $tenant) {}

    private function cfg(string $k, $default = null) {
        return $this->tenant['settings'][$k] ?? $default;
    }

    private function base(): string {
        return $this->cfg('mpesa_env') === 'production'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    /** Initiate an STK push. Returns [ok, payload|errorMessage, httpStatus]. */
    public function stkPush(array $voucher, string $phone): array
    {
        $timeout = (int)($this->cfg('timeout', 30) ?: 30);
        $env = $this->base();
        $verify = $this->cfg('mpesa_env') === 'production';

        // 1. OAuth token
        $ch = curl_init($env . '/oauth/v1/generate?grant_type=client_credentials');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode($this->cfg('consumer_key') . ':' . $this->cfg('consumer_secret'))],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => $verify,
        ]);
        $resp = json_decode(curl_exec($ch), true);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || empty($resp['access_token'])) {
            error_log('M-Pesa auth failed (tenant ' . $this->tenant['id'] . '): ' . json_encode($resp));
            return [false, 'Payment service authentication failed.', 502];
        }
        $token = $resp['access_token'];

        // 2. STK push
        $timestamp = date('YmdHis');
        $password = base64_encode($this->cfg('shortcode') . $this->cfg('passkey') . $timestamp);
        $callback = rtrim($this->cfg('callback_base'), '/') . '/mpesa_callback.php?tenant=' . $this->tenant['slug'] . '&voucher_id=' . $voucher['id'];

        $payload = [
            'BusinessShortCode' => $this->cfg('shortcode'),
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => max(1, (int)$voucher['price']),
            'PartyA' => $phone,
            'PartyB' => $this->cfg('shortcode'),
            'PhoneNumber' => $phone,
            'CallBackURL' => $callback,
            'AccountReference' => $this->tenant['slug'] . '-V' . $voucher['id'],
            'TransactionDesc' => 'Voucher: ' . $voucher['name'],
        ];
        $ch = curl_init($env . '/mpesa/stkpush/v1/processrequest');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer $token"],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => $verify,
        ]);
        $resp = json_decode(curl_exec($ch), true);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || ($resp['ResponseCode'] ?? null) !== '0') {
            error_log('STK push failed (tenant ' . $this->tenant['id'] . '): ' . json_encode($resp));
            return [false, 'Failed to initiate payment. Please try again.', 502];
        }
        return [true, $resp, 200];
    }

    /**
     * Issue a namespaced RADIUS voucher account for a successful payment.
     * Time-limited vouchers store a TOTAL budget as Max-All-Session (radcheck).
     * The FreeRADIUS no-reset sqlcounter (see install-allinone.sh) then sets
     * Session-Timeout to the REMAINING budget on every auth and rejects once it's
     * spent — so a voucher can't be reused after its time runs out, while a
     * partially-used one can still log back in for the leftover time.
     */
    public function issueVoucher(array $voucher): array
    {
        $slug = $this->tenant['slug'];
        $tid = (int)$this->tenant['id'];
        do {
            $username = $slug . '-VCH-' . strtoupper(bin2hex(random_bytes(3)));
            $chk = $this->pdo->prepare("SELECT 1 FROM radcheck WHERE username = ?");
            $chk->execute([$username]);
        } while ($chk->fetchColumn());
        $password = bin2hex(random_bytes(4));

        $this->pdo->prepare("INSERT INTO radcheck (tenant_id, username, attribute, op, value) VALUES (?, ?, 'Cleartext-Password', ':=', ?)")
            ->execute([$tid, $username, $password]);

        if ((int)$voucher['time_limit'] > 0) {
            // Total lifetime budget (check item). sqlcounter enforces it across
            // reconnects and derives the per-session Session-Timeout from it.
            $this->pdo->prepare("INSERT INTO radcheck (tenant_id, username, attribute, op, value) VALUES (?, ?, 'Max-All-Session', ':=', ?)")
                ->execute([$tid, $username, (int)$voucher['time_limit']]);
        }
        if (!empty($voucher['bandwidth_limit'])) {
            $bw = $voucher['bandwidth_limit'];
            [$up, $down] = strpos($bw, '/') !== false ? array_map('trim', explode('/', $bw, 2)) : [$bw, $bw];
            $this->pdo->prepare("INSERT INTO radreply (tenant_id, username, attribute, op, value) VALUES (?, ?, 'pfSense-Bandwidth-Max-Up', ':=', ?)")
                ->execute([$tid, $username, $this->toBytes($up)]);
            $this->pdo->prepare("INSERT INTO radreply (tenant_id, username, attribute, op, value) VALUES (?, ?, 'pfSense-Bandwidth-Max-Down', ':=', ?)")
                ->execute([$tid, $username, $this->toBytes($down)]);
        }
        $this->pdo->prepare("INSERT INTO radusergroup (tenant_id, username, groupname, priority) VALUES (?, ?, 'voucher', 1)")
            ->execute([$tid, $username]);

        return [$username, $password];
    }

    private function toBytes(string $val): int {
        $val = strtoupper(trim($val));
        $n = (float)$val;
        if (str_contains($val, 'G')) return (int)($n * 1024 ** 3);
        if (str_contains($val, 'M')) return (int)($n * 1024 ** 2);
        if (str_contains($val, 'K')) return (int)($n * 1024);
        return (int)$n;
    }
}
