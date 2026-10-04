<?php

namespace App\Support\CourseContent;

/**
 * The kinds of activity a module can contain, and which of them PIIE can
 * actually evaluate today.
 *
 * WHY THIS EXISTS
 *
 * Module completion used to be computed as "all lessons completed", which is a
 * lesson-only rule wearing a general name. The moment a module contained anything
 * else - and it always did, because assignments hang off modules - that rule was
 * quietly wrong: a student could finish every lesson and still be told the module
 * was done while a required assessment sat unsubmitted.
 *
 * So the rule is restated in general terms and the kinds are enumerated:
 *
 *     A MODULE IS COMPLETE WHEN EVERY REQUIRED, COMPLETION-BEARING ACTIVITY
 *     IN IT SATISFIES ITS OWN CONFIGURED COMPLETION RULE.
 *
 * A kind is a NAME plus, eventually, a resolver. Adding a kind later is a new
 * resolver, not a change to the completion rule.
 *
 * ONLY IMPLEMENTED KINDS CAN GATE
 *
 * A kind listed here but not implemented is reported and then EXCLUDED from the
 * gate. Declaring a kind PIIE cannot evaluate is fine - it is how the intended
 * shape stays visible and reviewable - but letting it gate would make a module
 * permanently incomplete for a reason nobody can satisfy. That is the same
 * principle already applied to a required task carrying an unimplemented
 * completion rule, applied one level up.
 *
 * ONLY LESSON AND ASSIGNMENT EXIST TODAY
 *
 * The rest are named so the vocabulary is honest about where PIIE is going, and
 * so a future Live Class, Quiz or Exam can be added as a resolver without anyone
 * having to re-derive what "module completion" means. Nothing here pretends they
 * exist: `isImplemented()` is false for all of them, and no surface offers them.
 */
final class ModuleActivityKind
{
    /** Learning content read by the student. */
    public const LESSON = 'lesson';

    /** Media in its own right. Not yet a separate entity in PIIE. */
    public const VIDEO = 'video';

    /** A standalone document. Not yet a separate entity. */
    public const DOCUMENT = 'document';

    /** A standalone audio item. Not yet a separate entity. */
    public const AUDIO = 'audio';

    /** A standalone link. Not yet a separate entity. */
    public const LINK = 'link';

    /** A scheduled Live Class belonging to the module. */
    public const LIVE_CLASS = 'live_class';

    /** A discussion thread. */
    public const DISCUSSION = 'discussion';

    /** A Quiz. */
    public const QUIZ = 'quiz';

    /** A Course Offering Assignment - the first assessment PIIE evaluates. */
    public const ASSIGNMENT = 'assignment';

    /** An Online Exam. */
    public const EXAM = 'exam';

    /**
     * THE DECLARED VOCABULARY, implemented or not.
     *
     * Kept so the intended shape of a module is visible and reviewable, and so a
     * future kind has a named slot. `isImplemented()` is what callers ask; never
     * membership of this list.
     */
    public const ALL = [
        self::LESSON,
        self::VIDEO,
        self::DOCUMENT,
        self::AUDIO,
        self::LINK,
        self::LIVE_CLASS,
        self::DISCUSSION,
        self::QUIZ,
        self::ASSIGNMENT,
        self::EXAM,
    ];

    /**
     * The kinds PIIE can evaluate satisfaction for RIGHT NOW.
     *
     * A module today is lessons plus, possibly, assignments. That is the whole
     * truth of the product today, and the completion rule is written against it
     * rather than against the wish list.
     */
    public const IMPLEMENTED = [
        self::LESSON,
        self::ASSIGNMENT,
    ];

    /** The kinds that are ASSESSMENTS - graded work, rather than reading. */
    public const ASSESSMENT_KINDS = [
        self::QUIZ,
        self::ASSIGNMENT,
        self::EXAM,
    ];

    public const LABELS = [
        self::LESSON => 'Lesson',
        self::VIDEO => 'Video',
        self::DOCUMENT => 'Document',
        self::AUDIO => 'Audio',
        self::LINK => 'Link',
        self::LIVE_CLASS => 'Live Class',
        self::DISCUSSION => 'Discussion',
        self::QUIZ => 'Quiz',
        self::ASSIGNMENT => 'Assignment',
        self::EXAM => 'Exam',
    ];

    public static function isImplemented(?string $kind): bool
    {
        return $kind !== null && in_array($kind, self::IMPLEMENTED, true);
    }

    public static function isDeclared(?string $kind): bool
    {
        return $kind !== null && in_array($kind, self::ALL, true);
    }

    public static function isAssessment(?string $kind): bool
    {
        return $kind !== null && in_array($kind, self::ASSESSMENT_KINDS, true);
    }

    public static function label(?string $kind): string
    {
        return self::LABELS[$kind] ?? 'Activity';
    }

    /** The kinds that are declared but not yet evaluable, for reporting. */
    public static function unimplemented(): array
    {
        return array_values(array_diff(self::ALL, self::IMPLEMENTED));
    }
}
