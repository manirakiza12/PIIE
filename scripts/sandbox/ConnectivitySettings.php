<?php
namespace PiieSandbox;

final class ConnectivitySettings
{
    public static function read(string $root, string $defaultOrigin): array
    {
        $settings = ['origin'=>$defaultOrigin,'registration'=>false,'transactions'=>false];
        if (is_file($root.'/connectivity.json')) {
            $saved=json_decode(file_get_contents($root.'/connectivity.json'),true,512,JSON_THROW_ON_ERROR);
            if (!is_array($saved) || array_diff(array_keys($saved),array_keys($settings))) throw new \RuntimeException('Invalid connectivity settings.');
            $settings=array_replace($settings,$saved);
        }
        self::validate($settings);
        return $settings;
    }

    public static function validate(array $settings): void
    {
        $origin=$settings['origin']??null;
        $parts=is_string($origin)?parse_url($origin):false;
        if (!is_bool($settings['registration']??null) || !is_bool($settings['transactions']??null)
            || !is_array($parts)
            || isset($parts['user'],$parts['pass']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path']??'',['','/'],true)
            || preg_match('/[\x00-\x20\\\\]/',$origin)
            || ($origin!=='http://127.0.0.1:8002' && (($parts['scheme']??'')!=='https' || isset($parts['port'])
                || !preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/',$parts['host']??'')
                || !str_contains($parts['host']??'','.') || filter_var($parts['host']??'',FILTER_VALIDATE_IP)))) {
            throw new \RuntimeException('Connectivity settings refused.');
        }
        if (($settings['registration'] || $settings['transactions']) && ($parts['scheme']??'')!=='https') throw new \RuntimeException('HTTPS origin required before provider access.');
    }
}
