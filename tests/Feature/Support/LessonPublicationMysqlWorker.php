<?php

// Refuse before providers load; this worker is exclusively for disposable CI MySQL.
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('APP_ENV') !== 'testing') {
    throw new RuntimeException('Lesson concurrency worker refuses a non-CI environment.');
}
require dirname(__DIR__,3).'/vendor/autoload.php';
$app = require dirname(__DIR__,3).'/bootstrap/app.php';
$app->beforeBootstrapping(Illuminate\Foundation\Bootstrap\BootProviders::class, function ($app): void {
    $connection=$app['config']->get('database.connections.mysql');
    if (!$app->environment('testing') || ($connection['database']??'')!=='ci_test'
        || ($connection['host']??'')!=='127.0.0.1' || ($connection['username']??'')!=='ci_user'
        || (int)($connection['port']??0)!==3306) { throw new RuntimeException('Lesson worker refuses a non-CI database.'); }
    $app['config']->set(['database.default'=>'mysql','database.connections.mysql.url'=>null,'database.connections.mysql.unix_socket'=>'']);
});
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$actor=App\Models\User::findOrFail((int)$argv[2]);
$service=$app->make(App\Support\CourseContent\CourseContentService::class);
$attributes=['title'=>'Concurrent lesson','body'=>'<h2>Concurrency</h2><p>One saved lesson.</p>','status'=>'published','assignment_id'=>(int)$argv[4],'creation_key'=>$argv[5]];
echo "READY\n";
flush();
$lesson=match($argv[1]) {
    'create'=>$service->createLesson($actor,App\Models\CourseOfferingModule::findOrFail((int)$argv[3]),$attributes),
    'update'=>$service->updateLesson($actor,App\Models\CourseOfferingLesson::findOrFail((int)$argv[3]),$attributes),
    default=>throw new RuntimeException('Unknown lesson worker operation'),
};
echo 'LESSON_ID='.$lesson->id."\n";
