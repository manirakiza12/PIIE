<?php
declare(strict_types=1);
// Explicitly isolated regression harness. Requires a separately initialized
// local MariaDB runtime on 127.0.0.1:3313. Never inherits .env credentials.
chdir(dirname(__DIR__));
foreach (['APP_ENV'=>'testing','APP_CONFIG_CACHE'=>getcwd().'/storage/phase13-runtime/no-config.php','DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'3313','DB_DATABASE'=>'piie_phase13_test','DB_USERNAME'=>'root','DB_PASSWORD'=>'','CACHE_DRIVER'=>'array','SESSION_DRIVER'=>'array','MAIL_MAILER'=>'array','QUEUE_CONNECTION'=>'sync'] as $k=>$v) { putenv("$k=$v"); $_ENV[$k]=$v; $_SERVER[$k]=$v; }
require 'vendor/autoload.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Http;
use App\Models\ApplicationPayment as P;
use App\Models\Admission as A;
use App\Support\Payments\ApplicationPaymentSettlement as S;
use App\Support\Payments\VerifiedApplicationPayment as V;
function guard(PDO $pdo): void { if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'piie_phase13_test') throw new RuntimeException('ABORT: wrong active database'); }
$mode=$argv[1]??'main';
if (!in_array($mode, ['create', 'main', 'worker', 'raw-worker', 'unique-race', 'drop', 'supplement'], true)) throw new RuntimeException('Unknown mode');
if ($mode==='create') { $pdo=new PDO('mysql:host=127.0.0.1;port=3313','root',''); $pdo->exec('CREATE DATABASE piie_phase13_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $pdo->exec('USE piie_phase13_test'); guard($pdo); echo $pdo->query('SELECT VERSION()')->fetchColumn()." database created\n"; exit; }
$app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default'=>'mysql','database.connections.mysql'=>['driver'=>'mysql','host'=>'127.0.0.1','port'=>3313,'database'=>'piie_phase13_test','username'=>'root','password'=>'','charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci','prefix'=>'','strict'=>true,'engine'=>'InnoDB'],'cache.default'=>'array','session.driver'=>'array','mail.default'=>'array']);
DB::purge('mysql'); guard(DB::connection()->getPdo()); Http::preventStrayRequests(); Mail::fake();
if ($mode==='drop') { guard(DB::connection()->getPdo()); DB::statement('DROP DATABASE piie_phase13_test'); echo "guarded drop complete\n"; exit; }
function check($ok,$label): void { if (!$ok) throw new RuntimeException("FAIL: $label"); echo "PASS: $label\n"; }
function evidence(P $p,array $o=[]): V { return new V(...array_merge(['paymentId'=>(int)$p->id,'schoolId'=>(int)$p->school_id,'provider'=>$p->method,'reference'=>$p->reference??'','transactionId'=>$p->gateway_txn_id??'','amount'=>(string)$p->amount,'currency'=>$p->currency??'','status'=>'paid','payload'=>['local_fixture'=>true]],$o)); }
if ($mode==='raw-worker') {
 file_put_contents($argv[3],json_encode(['connection'=>DB::selectOne('SELECT CONNECTION_ID() id')->id]));
 try {
  guard(DB::connection()->getPdo());
  DB::transaction(fn()=>DB::table('application_payments')->where('id',$argv[2])->update(['status'=>'paid','paid_at'=>now()]));
  $result=['result'=>'unexpected_duplicate_credit'];
 } catch (Illuminate\Database\QueryException $e) {
  if (($e->errorInfo[1]??null)!==1062 || !str_contains($e->getMessage(),App\Support\Payments\SettledPaymentIdentity::INDEX)) throw $e;
  $result=['result'=>'duplicate_key_rolled_back','mysql_code'=>1062];
 }
 file_put_contents($argv[4],json_encode($result)); exit;
}
if ($mode==='unique-race') {
 // Separate writers bypass the provider scan entirely: the unique secondary
 // index itself must arbitrate their simultaneous credits.
 $source=P::where('school_id',1)->where('status','pending')->firstOrFail();
 $a=$source->replicate(['settled_provider','settled_provider_txn_id']); $a->gateway_txn_id='raw-concurrent-unique'; $a->save();
 $source=P::where('school_id',2)->where('status','pending')->firstOrFail();
 $b=$source->replicate(['settled_provider','settled_provider_txn_id']); $b->gateway_txn_id=$a->gateway_txn_id; $b->save();
 guard(DB::connection()->getPdo()); DB::beginTransaction();
 DB::table('application_payments')->where('id',$a->id)->update(['status'=>'paid','paid_at'=>now()]);
 $ready=getcwd().'/storage/phase13-runtime/raw-ready.json'; $out=getcwd().'/storage/phase13-runtime/raw-result.json';
 $proc=proc_open([PHP_BINARY,__FILE__,'raw-worker',(string)$b->id,$ready,$out],[0=>['pipe','r'],1=>['file','local-reports/phase13/raw-worker.log','w'],2=>['file','local-reports/phase13/raw-worker-error.log','w']],$pipes);
 $deadline=microtime(true)+15; while(!file_exists($ready)&&microtime(true)<$deadline)usleep(50000);
 check(file_exists($ready),'raw competing worker connected');
 $deadline=microtime(true)+15; do { $wait=DB::select('SELECT * FROM information_schema.INNODB_LOCK_WAITS'); if($wait)break; usleep(50000); } while(microtime(true)<$deadline);
 $locks=DB::select('SELECT * FROM information_schema.INNODB_LOCKS');
 file_put_contents('local-reports/phase13/unique-index-lock-waits.json',json_encode(['worker'=>json_decode(file_get_contents($ready),true),'holder'=>DB::selectOne('SELECT CONNECTION_ID() id'),'waits'=>$wait,'locks'=>$locks],JSON_PRETTY_PRINT));
 check(count($wait)>0 && collect($locks)->contains(fn($lock)=>$lock->lock_index===App\Support\Payments\SettledPaymentIdentity::INDEX) && !file_exists($out),'actual competing writer wait on UNIQUE secondary index');
 DB::commit(); check(proc_close($proc)===0,'raw worker exits cleanly'); $result=json_decode(file_get_contents($out),true);
 check($result['result']==='duplicate_key_rolled_back' && $b->fresh()->status==='pending' && $b->fresh()->paid_at===null && P::where('gateway_txn_id',$a->gateway_txn_id)->where('status','paid')->count()===1,'concurrent bypass write rejected and rolled back: one credit across schools');
 echo json_encode($result)."\n"; exit;
}
if ($mode==='worker') { $p=P::findOrFail($argv[2]); file_put_contents($argv[3],json_encode(['connection'=>DB::selectOne('SELECT CONNECTION_ID() id')->id,'started'=>microtime(true)])); $t=microtime(true); $result=S::apply(evidence($p)); file_put_contents($argv[4],json_encode(['result'=>$result,'elapsed'=>microtime(true)-$t])); exit; }
if ($mode==='supplement') {
 $base=P::where('status','pending')->whereNotNull('gateway_txn_id')->firstOrFail();
 foreach (['cross-school admission'=>['school_id'=>1],'null currency'=>['currency'=>null],'null reference'=>['reference'=>null],'null transaction'=>['gateway_txn_id'=>null]] as $label=>$attrs) {
  $p=$base->replicate(['settled_provider','settled_provider_txn_id']); $p->fill($attrs); $p->save();
  check(S::apply(evidence($p))==='rejected' && $p->fresh()->status==='pending' && $p->fresh()->paid_at===null,$label.' fails closed');
 }
 echo json_encode(DB::select('SELECT @@tx_isolation isolation_level, VERSION() version'))."\n"; exit;
}
// Reuse the established admissions fixture schema on InnoDB, but run the
// real application-payment migration instead of its fixture table definition.
$src=file_get_contents('tests/Feature/Support/AdmissionsTestHelper.php');
$src=preg_replace('/namespace Tests\\\\Feature\\\\Support;/','namespace Phase13;',$src);
$src=preg_replace('/        Config::set\(\x27database.default\x27.*?DB::reconnect\(\x27sqlite\x27\);/s','        guard(DB::connection()->getPdo());',$src);
$src=preg_replace('/        Schema::create\(\x27application_payments\x27.*?\n        \}\);/s','',$src);
$src=str_replace("\$table->unique(['school_id', 'user_id', 'event_key']);", "\$table->unique(['school_id', 'user_id', 'event_key'], 'phase13_exam_event_unique');", $src);
eval(substr($src,5));
class Fixtures { use \Phase13\AdmissionsTestHelper; public function schema(){ $this->bootAdmissionsTestSchema(); } public function school(){return $this->makeSchool();} public function admission($s){return A::findOrFail($this->makeAdmission($s,['intake_session_id'=>$this->makeIntakeSession($s,['application_fee'=>'50000.00']),'status'=>'submitted']));} }
$f=new Fixtures; $f->schema(); (require 'database/migrations/2026_08_01_010007_create_application_payments_table.php')->up();
$school=$f->school(); $other=$f->school(); DB::table('global_settings')->insert([['key'=>'system_currency','value'=>'UGX'],['key'=>'primary_school_id','value'=>(string)$school]]);
function payment($f,$s,array $o=[]): P { $a=$f->admission($s); return P::create(array_merge(['school_id'=>$s,'admission_id'=>$a->id,'amount'=>'50000.00','currency'=>'UGX','method'=>'marzpay','status'=>'pending','reference'=>'REF-'.$a->id,'gateway_txn_id'=>'TX-'.$a->id],$o)); }
function migration() { return require 'database/migrations/2026_10_07_160000_add_settled_transaction_uniqueness_to_application_payments.php'; }
$historicalA=payment($f,$school,['status'=>'paid','gateway_txn_id'=>'historical-duplicate']);
$historicalB=payment($f,$other,['status'=>'paid','gateway_txn_id'=>'historical-duplicate']);
$preflight=App\Support\Payments\PaymentIdentityPreflight::inspect(DB::connection());
file_put_contents('local-reports/phase13/duplicate-history-preflight.json',json_encode($preflight,JSON_PRETTY_PRINT));
check(count($preflight['duplicate_eligible_row_ids'])===1,'preflight detects historical duplicates');
guard(DB::connection()->getPdo());
try { migration()->up(); throw new RuntimeException('duplicate history ignored'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(),'Duplicate settled payment identities'),'migration fails safely on duplicate history'); }
check(!Illuminate\Support\Facades\Schema::hasColumn('application_payments','settled_provider'),'failed preflight leaves schema unchanged');
// Only synthetic disposable fixture rows are removed, never historical source data.
guard(DB::connection()->getPdo()); P::whereIn('id',[$historicalA->id,$historicalB->id])->delete();
foreach (['offline','cash','bank','waived'] as $manual) {
 payment($f,$school,['method'=>$manual,'status'=>'paid','gateway_txn_id'=>'proof-shared']);
 payment($f,$other,['method'=>$manual,'status'=>'paid','gateway_txn_id'=>'proof-shared']);
}
payment($f,$school,['status'=>'paid','gateway_txn_id'=>null]);
payment($f,$school,['status'=>'paid','gateway_txn_id'=>null]);
$pending=payment($f,$school,['gateway_txn_id'=>'pending-shared']);
payment($f,$other,['status'=>'failed','gateway_txn_id'=>'pending-shared']);
guard(DB::connection()->getPdo()); migration()->up();
check(Illuminate\Support\Facades\Schema::hasColumn('application_payments','settled_provider'),'migration UP accepts legitimate existing rows');
check(P::where('status','paid')->whereNull('settled_provider')->count()===10,'offline/manual and null historical rows excluded');
check(S::apply(evidence($pending))==='settled','pending/failed rows do not reserve settled identity');
check(DB::selectOne("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='application_payments'")->ENGINE==='InnoDB','real payment migration / InnoDB');
echo json_encode(DB::select('SHOW INDEX FROM application_payments'))."\n";
$p=payment($f,$school); check(S::apply(evidence($p))==='settled','first settlement'); $stamp=$p->fresh()->paid_at; $audit=DB::table('audit_logs')->count(); check(S::apply(evidence($p))==='already_settled' && $p->fresh()->paid_at==$stamp && DB::table('audit_logs')->count()===$audit && P::where('gateway_txn_id',$p->gateway_txn_id)->where('status','paid')->count()===1,'same payment replay one credit unchanged audit/time');
$q=payment($f,$other,['gateway_txn_id'=>$p->gateway_txn_id]); check(S::apply(evidence($q))==='rejected' && $q->fresh()->status==='pending','external reuse across schools rejected');
foreach (['amount'=>['amount'=>'49999.99'],'currency'=>['currency'=>'KES'],'provider'=>['provider'=>'stripe'],'merchant reference'=>['reference'=>'wrong'],'transaction'=>['transactionId'=>'wrong'],'blank transaction'=>['transactionId'=>''],'school'=>['schoolId'=>$school]] as $label=>$o) { $q=payment($f,$other); check(S::apply(evidence($q,$o))==='rejected' && $q->fresh()->status==='pending' && $q->fresh()->paid_at===null,"mismatch $label"); }
$q=payment($f,$school); $before=DB::table('audit_logs')->count();
// Throw from admission saving after the payment UPDATE has executed under locks.
$fail=true; A::saving(function() use (&$fail){if($fail)throw new RuntimeException('injected after payment update');});
try { S::apply(evidence($q)); throw new RuntimeException('injection did not fire'); } catch (RuntimeException $e) { check($e->getMessage()==='injected after payment update','failure injected inside locked transaction'); }
$fail=false; check($q->fresh()->status==='pending' && $q->fresh()->paid_at===null && A::find($q->admission_id)->fee_status==='unpaid' && DB::table('audit_logs')->count()===$before,'rollback no partial payment/admission/audit'); check(S::apply(evidence($q))==='settled','rollback retry safe');
$q=payment($f,$school,['gateway_txn_id'=>null]); check(S::apply(evidence($q,['transactionId'=>'verified']))==='rejected','null stored identity fails closed');
$h=payment($f,$school,['method'=>'cash','status'=>'paid','currency'=>null,'gateway_txn_id'=>null,'reference'=>null]); check($h->fresh()->amount==='50000.00' && App\Support\Admissions\ApplicationFee::refreshStatus(A::find($h->admission_id))==='paid','historical null row readable/countable');
function race($f,$school,$other,bool $same): void {
 $a=payment($f,$school); $b=$same?$a:payment($f,$other,['gateway_txn_id'=>$a->gateway_txn_id]);
 DB::beginTransaction(); check(S::apply(evidence($a))==='settled','race holder settles uncommitted');
 $tag=$same?'same':'different'; $ready=getcwd()."/storage/phase13-runtime/$tag-ready.json"; $out=getcwd()."/storage/phase13-runtime/$tag-result.json";
 $proc=proc_open([PHP_BINARY,__FILE__,'worker',(string)$b->id,$ready,$out],[0=>['pipe','r'],1=>['file',getcwd()."/local-reports/phase13/$tag-worker.log",'w'],2=>['file',getcwd()."/local-reports/phase13/$tag-worker-error.log",'w']],$pipes);
 $deadline=microtime(true)+15; while(!file_exists($ready)&&microtime(true)<$deadline)usleep(50000); check(file_exists($ready),'separate worker connected');
 $wait=[]; $deadline=microtime(true)+15; do { $wait=DB::select('SELECT * FROM information_schema.INNODB_LOCK_WAITS'); if($wait)break; usleep(50000); } while(microtime(true)<$deadline);
 file_put_contents(getcwd()."/local-reports/phase13/$tag-lock-waits.json",json_encode(['worker'=>json_decode(file_get_contents($ready),true),'holder'=>DB::selectOne('SELECT CONNECTION_ID() id'),'waits'=>$wait,'locks'=>DB::select('SELECT * FROM information_schema.INNODB_LOCKS')],JSON_PRETTY_PRINT));
 check(count($wait)>0 && !file_exists($out),'actual InnoDB lock wait before holder commit'); DB::commit(); $exit=proc_close($proc); check($exit===0 && file_exists($out),'worker completed after commit'); $result=json_decode(file_get_contents($out),true); echo json_encode($result)."\n";
 check($result['result']===($same?'already_settled':'rejected') && P::where('method','marzpay')->where('gateway_txn_id',$a->gateway_txn_id)->where('status','paid')->count()===1,'concurrent '.($same?'replay':'external reuse').' one credit');
}
race($f,$school,$other,true); race($f,$school,$other,false);
// The Phase 1.2 bypass now fails at the database boundary.
$a=payment($f,$school); $b=payment($f,$other,['gateway_txn_id'=>$a->gateway_txn_id]); check(S::apply(evidence($a))==='settled','bypass control first settlement');
guard(DB::connection()->getPdo());
try { DB::table('application_payments')->where('id',$b->id)->update(['status'=>'paid','paid_at'=>now()]); throw new RuntimeException('bypass accepted'); }
catch (Illuminate\Database\QueryException $e) { check(($e->errorInfo[1]??null)===1062 && str_contains($e->getMessage(),App\Support\Payments\SettledPaymentIdentity::INDEX),'DB-level cross-school bypass duplicate rejected'); }
check($b->fresh()->status==='pending' && $b->fresh()->paid_at===null && A::find($b->admission_id)->fee_status==='unpaid','bypass leaves no cross-school credit');
// A legacy provider label with leading space is outside the boundary scan,
// but its generated canonical provider still conflicts: real 1062, not a mock.
$winner=payment($f,$school,['method'=>' marzpay ','status'=>'paid','gateway_txn_id'=>'race-canonical-provider']);
$loser=payment($f,$other,['gateway_txn_id'=>$winner->gateway_txn_id]);
check(S::apply(evidence($loser))==='rejected' && $loser->fresh()->status==='pending' && $loser->fresh()->paid_at===null,'real named 1062 handled by settlement without partial credit');
App\Models\PaymentMethods::create(['school_id'=>$other,'name'=>'marzpay','status'=>1,'mode'=>'test','payment_keys'=>json_encode(['sandbox_api_key'=>'fixture','sandbox_api_secret'=>'fixture','country'=>'UG'])]);
Http::fake(['wallet.wearemarz.com/api/v1/collect-money/*'=>Http::response(['data'=>['transaction'=>['uuid'=>$loser->gateway_txn_id,'reference'=>$loser->reference,'status'=>'successful'],'collection'=>['amount'=>['raw'=>'50000.00','currency'=>'UGX']]]])]);
$response=(new App\Http\Controllers\MarzPayWebhookController)->handle(Illuminate\Http\Request::create('/webhooks/marzpay','POST',['event_type'=>'collection.completed','transaction'=>['uuid'=>$loser->gateway_txn_id],'metadata'=>[['context'=>'application'],['context_id'=>$loser->id]]]));
check($response->getStatusCode()===200 && $response->getData(true)['status']==='ignored','real DB duplicate webhook response 200');
$before=DB::table('application_payments')->select('id','status','gateway_txn_id')->orderBy('id')->get()->toJson();
guard(DB::connection()->getPdo()); migration()->down();
check(!Illuminate\Support\Facades\Schema::hasColumn('application_payments','settled_provider') && $before===DB::table('application_payments')->select('id','status','gateway_txn_id')->orderBy('id')->get()->toJson(),'migration DOWN removes only protection');
guard(DB::connection()->getPdo()); migration()->up();
check(Illuminate\Support\Facades\Schema::hasColumn('application_payments','settled_provider') && $before===DB::table('application_payments')->select('id','status','gateway_txn_id')->orderBy('id')->get()->toJson(),'migration re-UP preserves rows');
file_put_contents('local-reports/phase13/final-preflight.json',json_encode(App\Support\Payments\PaymentIdentityPreflight::inspect(DB::connection()),JSON_PRETTY_PRINT));
echo "DATABASE PROTECTION VERIFIED\n";
