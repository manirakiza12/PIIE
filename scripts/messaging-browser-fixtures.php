<?php
// Synthetic in-memory fixtures only. No local or production database is used.
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('APP_URL=http://127.0.0.1:8773');
require __DIR__.'/../vendor/autoload.php';

class MessagingBrowserFixtures extends \Tests\Feature\PortalMessagingTest
{
    public function export(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\URL::forceRootUrl('http://127.0.0.1:8773');
        $student = \App\Models\User::where('role_id', 7)->firstOrFail();
        $teacher = \App\Models\User::where('role_id', 3)->firstOrFail();
        $student->update(['name' => 'Sample Student']);
        $teacher->update(['name' => 'Dr. Sample Lecturer']);
        foreach ([4 => 'Student Finance Office', 5 => 'PIIE Library Help', 10 => 'Student Welfare Office'] as $role => $name) {
            \App\Models\User::factory()->create(['school_id' => $student->school_id, 'role_id' => $role, 'name' => $name, 'account_status' => 'active']);
        }
        $service = app(\App\Support\Messaging\MessagingService::class);
        $this->actingAs($student);
        $thread = $service->start($student, $teacher->id);
        $texts = ['Hello Dr. Sample Lecturer. Could you help me find the course reading list?',
            'Hello! The reading list is available in the library section. Search for your course unit, or ask the library team for help.',
            'Thank you. I can see it now. Is there a deadline for the first assignment?',
            'Please check the assignment page for the published deadline. If anything is unclear, send me a message here.'];
        foreach ($texts as $i => $text) {
            $actor = $i % 2 ? $teacher : $student; $this->actingAs($actor);
            $service->send($actor, $thread, $text, (string) \Illuminate\Support\Str::uuid());
        }
        $directory = base_path('output/messaging-review');
        if (!is_dir($directory)) mkdir($directory, 0777, true);
        $this->actingAs($student);
        file_put_contents($directory.'/student-inbox.html', $this->get(route('messaging.index'))->assertOk()->getContent());
        file_put_contents($directory.'/student-thread.html', $this->get(route('messaging.show', $thread->id))->assertOk()->getContent());
        $this->post(route('messaging.report', $thread->id), ['reason' => 'Synthetic review example for layout verification'])->assertRedirect();
        $admin = $this->makeAdminUser((int) $student->school_id);
        $this->actingAs($admin);
        file_put_contents($directory.'/moderation.html', $this->get(route('messaging.moderation.show', 1))->assertOk()->getContent());
        \Illuminate\Support\Facades\DB::disconnect('sqlite');
        echo $directory.PHP_EOL;
    }
}
(new MessagingBrowserFixtures('test_start_is_csrf_protected_post_and_reuses_reverse_conversations'))->export();
