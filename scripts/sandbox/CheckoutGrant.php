<?php
namespace PiieSandbox;

/** Durable one-shot authority; no DB, credentials or provider I/O. */
final class CheckoutGrant
{
    public function __construct(private string $root, private string $file = 'checkout-grant')
    {
        if (!in_array($file, ['checkout-grant', 'checkout-grant-visa'], true)) throw new \RuntimeException('Grant path refused');
    }
    public function read(): ?array
    {
        $path=$this->root.'/'.$this->file.'.json';
        if(!is_file($path))return null;
        return json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    }
    public static function live(?array $g): bool
    {
        $scope=is_array($g) && (($g['version']??1)===2
            ? (($g['application_id']??null)===7 && ($g['application_number']??null)==='PSS-2627-S-P0005'
                && ($g['database']??null)==='piie_sandbox_7e0cae6b2d2eaa4e' && ($g['port']??null)===3307
                && ($g['single_use']??null)===true && ($g['max_provider_orders']??null)===1
                && ($g['max_payment_attempts']??null)===1)
            : (($g['version']??1)===1 && ($g['application_id']??null)===6));
        return $scope && ($g['school_id']??null)===1
            && ($g['amount']??null)==='50000.00' && ($g['currency']??null)==='UGX'
            && is_int($g['applicant_id']??null) && is_int($g['expires_at']??null)
            && $g['expires_at']>time() && ($g['heartbeat_at']??0)>time()-45
            && in_array($g['state']??null,['armed','consumed'],true);
    }
    public function mutate(callable $action): array
    {
        $lock=fopen($this->root.'/'.$this->file.'.lock','c+');
        if(!$lock || !flock($lock,LOCK_EX))throw new \RuntimeException('Grant lock refused');
        try {
            $g=$this->read(); $next=$action($g);
            $temp=$this->root.'/'.$this->file.'.json.tmp';
            if(file_put_contents($temp,json_encode($next,JSON_PRETTY_PRINT),LOCK_EX)===false || !rename($temp,$this->root.'/'.$this->file.'.json'))throw new \RuntimeException('Grant persistence refused');
            return $next;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
    public function consume(int $owner, callable $preflight): array
    {
        return $this->mutate(function($g)use($owner,$preflight){
            if(!self::live($g)||$g['state']!=='armed'||$g['applicant_id']!==$owner)throw new \RuntimeException('Checkout authority unavailable');
            $preflight($g);
            $g['state']='consumed';$g['consumed_at']=time();return $g;
        });
    }
    public function reserveSubmission(): void
    {
        $this->mutate(function($g){
            if(!self::live($g)||$g['state']!=='consumed'||($g['submission_used']??false))throw new \RuntimeException('Order authority unavailable');
            $g['submission_used']=true;$g['submission_at']=time();return $g;
        });
    }
}
