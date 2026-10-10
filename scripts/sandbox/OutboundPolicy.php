<?php
namespace PiieSandbox;

use Psr\Http\Message\RequestInterface;

final class OutboundPolicy
{
    public static function assertAllowed(string $method, string $url, array $settings, bool $readOnlyIpn = false): void
    {
        if (!$readOnlyIpn && !($settings['registration']??false) && !($settings['transactions']??false)) throw new \RuntimeException('External requests disabled in sandbox preparation.');
        $parts=parse_url($url);
        $base='/pesapalv3/api/';
        $allowed=($settings['registration'] || $settings['transactions'])?['POST '.$base.'Auth/RequestToken']:[];
        if ($settings['registration']) $allowed[]='POST '.$base.'URLSetup/RegisterIPN';
        if ($settings['transactions']) $allowed=array_merge($allowed,['POST '.$base.'Transactions/SubmitOrderRequest','GET '.$base.'Transactions/GetTransactionStatus']);
        // Process-only reconciliation override: never combines with write permissions.
        if ($readOnlyIpn) $allowed=['POST '.$base.'Auth/RequestToken','GET '.$base.'URLSetup/GetIpnList'];
        if (!is_array($parts) || ($parts['scheme']??'')!=='https' || ($parts['host']??'')!=='cybqa.pesapal.com'
            || (isset($parts['port']) && $parts['port']!==443) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\\\\%]/',($parts['path']??'')) || !in_array($method.' '.($parts['path']??''),$allowed,true)) {
            throw new \RuntimeException('Sandbox outbound request refused.');
        }
        if ($readOnlyIpn && isset($parts['query'])) throw new \RuntimeException('Sandbox listing query refused.');
        if (isset($parts['query'])) {
            if ($method!=='GET' || !preg_match('/\AorderTrackingId=[a-fA-F0-9]{8}-(?:[a-fA-F0-9]{4}-){3}[a-fA-F0-9]{12}\z/',$parts['query'])) throw new \RuntimeException('Sandbox outbound query refused.');
        } elseif ($method==='GET' && !$readOnlyIpn) throw new \RuntimeException('Sandbox tracking identity required.');
    }

    public static function publicAddresses(array $addresses): array
    {
        if (!$addresses) throw new \RuntimeException('Sandbox provider DNS unavailable.');
        foreach ($addresses as $address) if (!is_string($address) || !filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_GLOBAL_RANGE)) throw new \RuntimeException('Non-public provider address refused.');
        return array_values(array_unique($addresses));
    }

    public static function middleware(array $settings, ?callable $resolve=null, bool $readOnlyIpn = false): callable
    {
        $resolve??=static function (): array {
            $rows=dns_get_record('cybqa.pesapal.com',DNS_A|DNS_AAAA);
            return array_values(array_filter(array_map(fn($row)=>$row['ip']??$row['ipv6']??null,$rows?:[])));
        };
        return static function (callable $handler) use ($settings,$resolve,$readOnlyIpn): callable {
            return static function (RequestInterface $request, array $options) use ($handler,$settings,$resolve,$readOnlyIpn) {
                self::assertAllowed($request->getMethod(),(string)$request->getUri(),$settings,$readOnlyIpn);
                if (!extension_loaded('curl')) throw new \RuntimeException('Pinned TLS transport unavailable.');
                $ips=self::publicAddresses($resolve());
                $options['verify']=true; $options['allow_redirects']=false; $options['proxy']=''; $options['idn_conversion']=false;
                // Replace caller curl overrides: no URL, CONNECT_TO, proxy or weakened TLS options can survive.
                $options['curl']=[CURLOPT_RESOLVE=>['cybqa.pesapal.com:443:'.implode(',',array_map(fn($ip)=>str_contains($ip,':')?'['.$ip.']':$ip,$ips))],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
                return $handler($request,$options)->then(static function ($response) {
                    if ($response->getStatusCode()>=300 && $response->getStatusCode()<400) throw new \RuntimeException('Provider redirects refused.');
                    return $response;
                });
            };
        };
    }
}
