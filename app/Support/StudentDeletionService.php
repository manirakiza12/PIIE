<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the explicitly student-owned part of the academic/account graph.
 * Shared catalogue, offering, curriculum, cohort, exam and assignment rows are
 * deliberately never traversed as deletion roots.
 */
class StudentDeletionService
{
    /**
     * Financial records with a payment, award, or provider attempt are kept for
     * reconciliation under docs/student-opening-payments.md,
     * docs/student-fee-awards.md, and docs/school-fee-pesapal.md.
     *
     * @return array{retained_financial_invoices:int,retained_applications:int}
     */
    public function delete(int $studentId, User $actor): array
    {
        $privateFiles = [];
        $publicFiles = [];
        $result = DB::transaction(function () use ($studentId, $actor, &$privateFiles, &$publicFiles): array {
            $student = User::query()
                ->whereKey($studentId)
                ->where('school_id', $actor->school_id)
                ->where('role_id', 7)
                ->lockForUpdate()
                ->firstOrFail();
            $studentId = (int) $student->getKey();
            $invoiceIds = $this->ids('student_fee_managers', 'student_id', $studentId);
            $retainedInvoiceIds = $this->retainedInvoiceIds($invoiceIds, $studentId);

            $onlineSubmissionIds = $this->ids('online_exam_submissions', 'student_id', $studentId);
            $assignmentSubmissionIds = $this->ids('assignment_submissions', 'student_id', $studentId);
            $candidateIds = $this->ids('election_candidates', 'student_id', $studentId);
            $bookIssueIds = $this->ids('book_issues', 'student_id', $studentId);
            $authoredChatIds = $this->ids('chats', 'sender_id', $studentId);
            $admissionIds = array_values(array_unique(array_merge(
                $this->values('programme_cohort_memberships', 'admission_id', 'student_id', $studentId),
                $this->values('admission_letters', 'admission_id', 'student_id', $studentId)
            )));
            $retainedAdmissionIds = $this->retainedAdmissionIds($admissionIds);
            $this->collectStoredFiles('assignment_submissions', 'student_id', $studentId, ['file_path'], $privateFiles);
            $this->collectStoredFiles('assignment_submission_items', 'assignment_submission_id', $assignmentSubmissionIds, ['stored_path'], $privateFiles, true);
            $this->collectStoredFiles('online_exam_submissions', 'student_id', $studentId, ['file_path', 'attachment_path'], $privateFiles);
            $userInfo = json_decode((string) $student->user_information, true) ?: [];
            $photo = $userInfo['photo'] ?? null;
            if (! $this->photoUsedByAnotherUser($photo, $studentId)) {
                $this->appendSafePublicFile($publicFiles, public_path('assets/uploads/user-images'), $photo);
            }
            foreach ($this->values('student_profiles', 'additional_image', 'user_id', $studentId) as $filename) {
                $stillUsed = Schema::hasTable('student_profiles') && Schema::hasColumn('student_profiles', 'additional_image')
                    && DB::table('student_profiles')->where('additional_image', $filename)->where('user_id', '<>', $studentId)->exists();
                if (! $stillUsed) {
                    $this->appendSafePublicFile($publicFiles, public_path('assets/uploads/user-images'), $filename);
                }
            }
            foreach ($admissionIds as $admissionId) {
                foreach ($this->values('admission_documents', 'stored_name', 'admission_id', (int) $admissionId) as $filename) {
                    $this->appendSafePublicFile($publicFiles, public_path('assets/uploads/admissions'), $filename);
                }
            }
            foreach (json_decode((string) $student->documents, true) ?: [] as $document) {
                $this->appendSafePublicFile($publicFiles, public_path('assets/uploads/user-docs/' . $studentId), $document);
            }

            // Rows nested under student-owned attempts/loans are removed first.
            $this->deleteWhereIn('assignment_question_responses', 'assignment_submission_id', $assignmentSubmissionIds);
            $this->deleteWhereIn('assignment_submission_items', 'assignment_submission_id', $assignmentSubmissionIds);
            $this->deleteWhereIn('assignment_notifications', 'submission_id', $assignmentSubmissionIds);
            $this->deleteWhereIn('online_exam_answers', 'submission_id', $onlineSubmissionIds);
            $this->deleteWhereIn('online_exam_proctoring_events', 'submission_id', $onlineSubmissionIds);
            $this->deleteWhereIn('online_exam_user_notifications', 'submission_id', $onlineSubmissionIds);
            $this->deleteWhereIn('library_fines', 'loan_id', $bookIssueIds);
            $this->deleteWhereIn('library_events', 'loan_id', $bookIssueIds);
            $this->deleteWhereIn('admission_documents', 'admission_id', $admissionIds);
            $this->deleteWhereIn('admission_qualifications', 'admission_id', $admissionIds);
            $this->deleteWhereIn('admission_status_events', 'admission_id', $admissionIds);
            $this->deleteWhereIn('admissions', 'id', array_values(array_diff($admissionIds, $retainedAdmissionIds)));
            $this->deleteWhereIn('chats', 'id', $authoredChatIds);
            $this->nullWhereIn('messaging_reports', 'chat_id', $authoredChatIds);
            $this->nullWhereIn('chats', 'reply_id', $authoredChatIds);
            $this->nullReference('chats', 'reciver_id', $studentId);
            $this->nullReference('message_thrades', 'sender_id', $studentId);
            $this->nullReference('message_thrades', 'reciver_id', $studentId);
            $this->deleteWhere('messaging_reports', 'reporter_id', $studentId);

            // Election ballots submitted by this student are owned by them.
            // Votes cast by other students for this student's candidacy remain
            // intact; a candidate row with votes is anonymized below so those
            // ballots continue to point to a valid, non-personal candidate.
            $this->deleteWhere('election_votes', 'voter_id', $studentId);
            $votedCandidateIds = $candidateIds && Schema::hasTable('election_votes') && Schema::hasColumn('election_votes', 'candidate_id')
                ? DB::table('election_votes')->whereIn('candidate_id', $candidateIds)->distinct()->pluck('candidate_id')->map(fn ($id) => (int) $id)->all()
                : [];
            if ($votedCandidateIds) {
                if (Schema::hasColumn('election_candidates', 'student_id')) {
                    DB::table('election_candidates')->whereIn('id', $votedCandidateIds)->update(['student_id' => null, 'manifesto' => null]);
                }
            }
            $this->deleteWhereIn('election_candidates', 'id', array_values(array_diff($candidateIds, $votedCandidateIds)));

            // Attendance rows must precede registrations because the scoped
            // course-offering attendance FK restricts deleting a registration.
            $this->deleteWhere('course_offering_attendance_records', 'student_id', $studentId);
            $this->deleteWhere('course_offering_lesson_progress', 'student_id', $studentId);
            $this->deleteWhere('course_registrations', 'student_id', $studentId);

            // Directly student-owned records. These tables do not own their
            // referenced course, curriculum, cohort, exam, assignment or class.
            foreach ([
                ['enrollment', 'user_id'], ['daily_aatendance', 'student_id'],
                ['daily_attendances', 'student_id'], ['book_issues', 'student_id'],
                ['gradebooks', 'student_id'], ['online_exam_submissions', 'student_id'],
                ['assignment_submissions', 'student_id'], ['graduation_applications', 'student_id'],
                ['hostel_room_allocations', 'student_id'], ['hostel_applications', 'student_id'],
                ['hostel_fees', 'student_id'], ['club_members', 'student_id'],
                ['appraisal_submits', 'student_id'], ['feedback', 'student_id'],
                ['student_requests', 'student_id'], ['student_curriculum_assignments', 'student_id'],
                ['programme_cohort_memberships', 'student_id'], ['admission_letters', 'student_id'],
                ['student_fee_registration_waivers', 'student_id'],
                ['student_financial_setup_reviews', 'student_id'],
                ['applicants', 'converted_user_id'],
                ['live_class_attendances', 'user_id'], ['user_notifications', 'user_id'],
                ['online_exam_user_notifications', 'user_id'], ['user_permissions', 'user_id'],
                ['user_staff_roles', 'user_id'], ['google_account_connections', 'user_id'],
                ['library_reservations', 'user_id'], ['library_fines', 'user_id'],
                ['library_requests', 'user_id'], ['library_member_blocks', 'user_id'],
                ['library_events', 'actor_id'],
                ['message_thread_preferences', 'user_id'], ['messaging_email_deliveries', 'user_id'],
                ['messaging_blocks', 'user_id'], ['messaging_blocks', 'blocked_user_id'],
                ['course_offering_lecturer_allocations', 'user_id'],
            ] as [$table, $column]) {
                $this->deleteWhere($table, $column, $studentId);
            }

            // These nullable columns identify the actor on shared records, not
            // their owner. Detach the deleted account and retain the records.
            $this->nullReference('programme_cohorts', 'created_by', $studentId);
            $this->nullReference('student_curriculum_assignments', 'assigned_by', $studentId);
            $this->nullReference('programme_cohort_memberships', 'assigned_by', $studentId);

            // Session/CSRF/authentication artifacts are account-owned.
            $this->deleteMorphRows('personal_access_tokens', 'tokenable_type', 'tokenable_id', $student);
            $this->deleteMorphRows('notifications', 'notifiable_type', 'notifiable_id', $student);
            $this->deleteWhere('sessions', 'user_id', $studentId);
            if (User::query()->where('email', $student->email)->whereKeyNot($studentId)->doesntExist()) {
                $this->deleteWhere('password_resets', 'email', $student->email);
            }
            User::query()->where('parent_id', $studentId)->update(['parent_id' => null]);

            // Remove invoices with no recorded payment/award/provider history.
            // Rows with such history remain for financial reconciliation.
            $deleteInvoiceIds = array_values(array_diff($invoiceIds, $retainedInvoiceIds));
            $this->deleteWhereIn('student_fee_managers', 'id', $deleteInvoiceIds);

            // Attendance can also record the student as the marker/recorder of
            // a shared session; those actor columns are nullable.
            $this->nullReference('course_offering_attendance_records', 'marked_by_user_id', $studentId);
            $this->nullReference('course_offering_attendance_sessions', 'recorded_by_user_id', $studentId);

            $this->deleteWhere('student_profiles', 'user_id', $studentId);

            // No target id, name, email, route, IP, agent or academic values are
            // written into this event. user_id and timestamp identify the actor.
            DB::table('audit_logs')->insert([
                'user_id' => $actor->getKey(),
                'action' => 'student.delete',
                'event_type' => 'SECURITY',
                'module' => 'students',
                'description' => 'Student account permanently deleted.',
                'created_at' => now(),
            ]);

            $student->delete();

            return [
                'retained_financial_invoices' => count($retainedInvoiceIds),
                'retained_applications' => count($retainedAdmissionIds),
            ];
        }, 3);

        // Filesystem writes cannot join a SQL transaction. Remove only generated
        // paths that were read from rows just deleted, after the database commit.
        foreach (array_unique($privateFiles) as $path) {
            Storage::disk('local')->delete($path);
        }
        foreach (array_unique($publicFiles) as $path) {
            File::delete($path);
        }
        File::deleteDirectory(public_path('assets/uploads/user-docs/' . $studentId));

        return $result;
    }

    private function retainedInvoiceIds(array $invoiceIds, int $studentId): array
    {
        if (! $invoiceIds) {
            return [];
        }

        $retained = [];
        foreach ([
            ['student_opening_payments', 'fee_id'],
            ['student_fee_awards', 'fee_id'],
            ['tuition_payments', 'fee_id'],
        ] as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            $retained = array_merge($retained, DB::table($table)->whereIn($column, $invoiceIds)->distinct()->pluck($column)->map(fn ($id) => (int) $id)->all());
        }

        // The legacy ledgers identify school-fee activity by user, not invoice.
        // Their original approval/provider references are retained by the
        // documented reconciliation policy, so keep this student's invoices
        // whenever either ledger contains a historical entry.
        foreach (['payments', 'payment_history'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')
                && DB::table($table)->where('user_id', $studentId)->exists()) {
                $retained = array_merge($retained, $invoiceIds);
                break;
            }
        }

        if (Schema::hasTable('student_fee_managers') && Schema::hasColumn('student_fee_managers', 'paid_amount')) {
            $retained = array_merge($retained, DB::table('student_fee_managers')
                ->whereIn('id', $invoiceIds)->where('paid_amount', '>', 0)->pluck('id')->map(fn ($id) => (int) $id)->all());
        }

        return array_values(array_unique($retained));
    }

    private function ids(string $table, string $ownerColumn, int $ownerId): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $ownerColumn) || ! Schema::hasColumn($table, 'id')) {
            return [];
        }

        return DB::table($table)->where($ownerColumn, $ownerId)->pluck('id')->all();
    }

    private function values(string $table, string $selectColumn, string $ownerColumn, int $ownerId): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $ownerColumn) || ! Schema::hasColumn($table, $selectColumn)) {
            return [];
        }

        return DB::table($table)->where($ownerColumn, $ownerId)->whereNotNull($selectColumn)->pluck($selectColumn)->all();
    }

    private function idsWhereIn(string $table, string $ownerColumn, array $ownerIds): array
    {
        if (! $ownerIds || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $ownerColumn) || ! Schema::hasColumn($table, 'id')) {
            return [];
        }

        return DB::table($table)->whereIn($ownerColumn, $ownerIds)->pluck('id')->all();
    }

    private function retainedAdmissionIds(array $admissionIds): array
    {
        if (! $admissionIds || ! Schema::hasTable('application_payments') || ! Schema::hasColumn('application_payments', 'admission_id')) {
            return [];
        }

        return DB::table('application_payments')->whereIn('admission_id', $admissionIds)->distinct()->pluck('admission_id')->all();
    }

    private function deleteWhere(string $table, string $column, mixed $value): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
            DB::table($table)->where($column, $value)->delete();
        }
    }

    private function deleteWhereIn(string $table, string $column, array $values): void
    {
        if ($values && Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
            DB::table($table)->whereIn($column, $values)->delete();
        }
    }

    private function nullReference(string $table, string $column, int $userId): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
            DB::table($table)->where($column, $userId)->update([$column => null]);
        }
    }

    private function nullWhereIn(string $table, string $column, array $values): void
    {
        if ($values && Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
            DB::table($table)->whereIn($column, $values)->update([$column => null]);
        }
    }

    private function deleteMorphRows(string $table, string $typeColumn, string $idColumn, User $student): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $typeColumn) && Schema::hasColumn($table, $idColumn)) {
            DB::table($table)->where($typeColumn, $student->getMorphClass())->where($idColumn, $student->getKey())->delete();
        }
    }

    private function collectStoredFiles(string $table, string $ownerColumn, int|array $owner, array $columns, array &$paths, bool $ownerIsIdList = false): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $ownerColumn)) {
            return;
        }
        $query = DB::table($table);
        $ownerIsIdList ? $query->whereIn($ownerColumn, $owner) : $query->where($ownerColumn, $owner);
        $available = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($table, $column)));
        if (! $available) {
            return;
        }
        foreach ($query->get($available) as $row) {
            foreach ($available as $column) {
                $path = $row->{$column};
                if (is_string($path) && $path !== '' && ! str_contains($path, '..') && ! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
                    $paths[] = $path;
                }
            }
        }
    }

    private function appendSafePublicFile(array &$paths, string $directory, mixed $filename): void
    {
        if (! is_string($filename) || $filename === '' || $filename !== basename(str_replace('\\', '/', $filename))) {
            return;
        }

        $paths[] = $directory . DIRECTORY_SEPARATOR . $filename;
    }

    private function photoUsedByAnotherUser(mixed $filename, int $studentId): bool
    {
        if (! is_string($filename) || $filename === '' || ! Schema::hasColumn('users', 'user_information')) {
            return false;
        }

        return User::query()->where('id', '<>', $studentId)->pluck('user_information')->contains(function ($information) use ($filename): bool {
            $decoded = json_decode((string) $information, true);
            return is_array($decoded) && ($decoded['photo'] ?? null) === $filename;
        });
    }
}
