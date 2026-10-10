<?php
namespace PiieSandbox;
use App\Models\Admission;
use App\Models\ApplicationPayment;
use App\Support\Payments\PesaPalConfiguration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/CheckoutGrant.php';
final class ControlledCheckout
{
    public static function grant(): CheckoutGrant
    {
        $root=app()->environmentPath();
        return new CheckoutGrant($root,is_file($root.'/checkout-grant-visa.json')?'checkout-grant-visa':'checkout-grant');
    }
    public static function active(): bool
    {
        $g=self::grant()->read();$s=config('sandbox.connectivity');
        return CheckoutGrant::live($g) && !$s['registration'] && $s['transactions']
            && $g['origin']===$s['origin'] && $g['database']===DB::connection()->getDatabaseName();
    }
    public static function ownerMatches($applicant): bool
    {
        $g=self::grant()->read();
        return $g && $applicant && (int)$applicant->id===$g['applicant_id'] && (int)$applicant->school_id===1
            && ApplicantWorkflowGuard::syntheticEmail($applicant->email) && (int)$applicant->currentAdmission()?->id===$g['application_id'];
    }
    public static function buttonEnabled($admission): bool
    {
        return self::active() && self::grant()->read()['state']==='armed' && (int)$admission->id===self::grant()->read()['application_id']
            && self::ownerMatches(auth('applicant')->user());
    }
    public static function assertApplication(Admission $a,array $g,bool $empty): void
    {
        $number=($g['version']??1)===2?'PSS-2627-S-P0005':'PSS-2627-S-P0004';
        if((int)$a->id!==$g['application_id'] || $a->app_number!==$number || (int)$a->school_id!==1
            || (int)$a->applicant_id!==$g['applicant_id'] || $a->status!=='submitted' || !$a->submitted_at
            || (string)$a->application_fee_amount!=='50000.00'||$a->application_fee_currency!=='UGX'
            || ($empty && ($a->fee_status!=='unpaid'||$a->payments()->exists())))throw new \RuntimeException('Application scope refused');
        $c=PesaPalConfiguration::forSchool(1);
        if($c->environment!=='sandbox'||$c->configurationId!==$g['configuration_id']||!$c->notificationId)throw new \RuntimeException('Configuration scope refused');
    }
    public static function startGuard(Admission $a): void
    {
        if(!self::active()||config('sandbox.checkout.scope')!=='start'||!self::ownerMatches(auth('applicant')->user()))abort(403);
        try {self::grant()->consume((int)auth('applicant')->id(),fn($g)=>self::assertApplication($a,$g,true));}
        catch(\RuntimeException $e){abort(403,'The one-use sandbox checkout is no longer available.');}
    }
    public static function payment(): ?ApplicationPayment
    {
        $g=self::grant()->read(); if(!$g)return null;
        $rows=ApplicationPayment::where('admission_id',$g['application_id'])->get();
        if($rows->count()!==1)return null;
        $p=$rows->first();
        return (int)$p->school_id===1 && (int)$p->applicant_id===$g['applicant_id'] && $p->method==='pesapal'
            && (string)$p->amount==='50000.00' && $p->currency==='UGX'
            && ($p->gateway_payload['configuration_id']??null)===$g['configuration_id'] ? $p : null;
    }
    public static function reconcileGuard(ApplicationPayment $p): void
    {
        $expected=self::payment();
        if(!self::active()||config('sandbox.checkout.scope')!=='verify'||!$expected||$expected->id!==$p->id)throw new \App\Support\Payments\PesaPalException();
    }
    public static function close(string $reason): void
    {
        // Disable transport first, then revoke the durable authority. Never delete the grant.
        $root=app()->environmentPath();$lock=fopen($root.'/connectivity.lock','c+');
        if(!$lock||!flock($lock,LOCK_EX))throw new \RuntimeException('Shutdown lock refused');
        try {$s=ConnectivitySettings::read($root,config('app.url'));$s['transactions']=false;$s['registration']=false;
            file_put_contents($root.'/connectivity.json.tmp',json_encode($s,JSON_PRETTY_PRINT),LOCK_EX);
            if(!rename($root.'/connectivity.json.tmp',$root.'/connectivity.json'))throw new \RuntimeException('Shutdown persistence refused');
            config(['sandbox.connectivity'=>$s]);
        } finally {flock($lock,LOCK_UN);fclose($lock);}
        self::grant()->mutate(function($g)use($reason){if(!$g)throw new \RuntimeException();$g['state']='revoked';$g['closed_at']=time();$g['close_reason']=$reason;return $g;});
    }
    public static function finishIfTerminal(): void
    {
        $p=self::payment();
        if($p && ($p->status==='paid'||$p->status==='failed'||($p->gateway_payload['classification']??null)==='REVERSED'))self::close('terminal_'.$p->status);
    }
    public function handle($request,\Closure $next)
    {
        $g=self::grant()->read();
        if(!$g)return $next($request);
        $path='/'.$request->path();
        if(str_starts_with($path,'/payments/application/') && $path!=='/payments/application/'.$g['application_id'])abort(403);
        if(in_array($path,['/payments/pesapal/callback','/payments/pesapal/ipn'],true)) {
            $p=self::payment();
            abort_unless($request->isMethod('GET') && self::active() && $p && $g['state']==='consumed'
                && is_string($request->input('OrderMerchantReference')) && hash_equals($p->reference,$request->input('OrderMerchantReference'))
                && is_string($request->input('OrderTrackingId')) && $p->gateway_txn_id && strtolower($request->input('OrderTrackingId'))===$p->gateway_txn_id,403);
            config(['sandbox.checkout.scope'=>'verify']);
        } elseif($path==='/applicant/payment/pesapal/start') {
            abort_unless($request->isMethod('POST') && self::buttonEnabled(Admission::findOrFail($g['application_id'])),403);
            config(['sandbox.checkout.scope'=>'start']);
        } elseif(preg_match('~\A/applicant/payment/pesapal/([1-9][0-9]*)/status\z~',$path,$m)) {
            $p=self::payment();
            abort_unless($request->isMethod('POST')&&self::active()&&self::ownerMatches(auth('applicant')->user())&&$p&&(int)$m[1]===$p->id,403);
            config(['sandbox.checkout.scope'=>'verify']);
        } elseif(str_starts_with($path,'/payments/application/') && !$request->isMethod('GET'))abort(403);
        try {return $next($request);}
        finally {
            if(config('sandbox.checkout.scope')==='start') {
                $p=self::payment();
                if($p)self::grant()->mutate(function($g)use($p){$g['payment_id']=(int)$p->id;return $g;});
                if(self::grant()->read()['state']==='consumed' && (!$p||!$p->gateway_txn_id||empty($p->gateway_payload['checkout_url'])))self::close('initiation_failed_or_uncertain');
            }
            self::finishIfTerminal();config(['sandbox.checkout.scope'=>null]);
        }
    }
    public static function transportMiddleware(): callable
    {
        return static fn(callable $handler)=>static function($request,array $options)use($handler){
            if(!self::active())throw new \RuntimeException('Controlled transport inactive');
            $scope=config('sandbox.checkout.scope');$path=$request->getUri()->getPath();$method=$request->getMethod();
            if(!in_array($scope,['start','verify'],true)||self::grant()->read()['state']!=='consumed')throw new \RuntimeException('Controlled request context required');
            if($path==='/pesapalv3/api/Auth/RequestToken'&&$method==='POST')return $handler($request,$options);
            $p=self::payment();if(!$p)throw new \RuntimeException('Controlled order missing');
            if($scope==='start'&&$path==='/pesapalv3/api/Transactions/SubmitOrderRequest'&&$method==='POST') {
                $body=json_decode((string)$request->getBody(),true,512,JSON_THROW_ON_ERROR);$c=PesaPalConfiguration::forSchool(1);
                if(($body['id']??null)!==$p->reference||(string)($body['amount']??'')!=='50000.00'||($body['currency']??null)!=='UGX'
                    ||($body['notification_id']??null)!==$c->notificationId||($body['callback_url']??null)!==config('app.url').'/payments/pesapal/callback')throw new \RuntimeException('Controlled order mismatch');
                self::grant()->reserveSubmission();return $handler($request,$options);
            }
            if($scope==='verify'&&$path==='/pesapalv3/api/Transactions/GetTransactionStatus'&&$method==='GET'
                &&$p->gateway_txn_id&&$request->getUri()->getQuery()==='orderTrackingId='.strtolower($p->gateway_txn_id))return $handler($request,$options);
            throw new \RuntimeException('Controlled provider operation refused');
        };
    }
}
