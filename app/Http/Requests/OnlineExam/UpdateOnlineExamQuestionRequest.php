<?php

namespace App\Http\Requests\OnlineExam;

use App\Models\OnlineExamQuestion;
use App\Support\Permissions\OnlineExamAuthorizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Support\OnlineExams\AnswerKey;
use App\Support\OnlineExams\QuestionContract;
use App\Support\OnlineExams\QuestionPrompt;

class UpdateOnlineExamQuestionRequest extends FormRequest
{
    private ?OnlineExamQuestion $question = null;

    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }

        $questionParam = $this->route('question');
        $id = (int) ($this->route('question_id') ?? $this->route('id') ?? (is_object($questionParam) ? ($questionParam->id ?? 0) : $questionParam) ?? 0);
        $this->question = OnlineExamQuestion::with('exam')->find($id);
        if (!$this->question) {
            return false;
        }

        return app(OnlineExamAuthorizer::class)->canManageQuestion($user, $this->question);
    }

    public function rules(): array
    {
        $rules = [
            'question' => ['required', 'string'],
            'type' => ['required', Rule::in(['multiple_choice', 'multiple_select', 'numeric', 'matching', 'ordering', 'true_false', 'fill_blank', 'short_answer', 'essay', 'mcq', 'short'])],
            'option_a' => ['nullable', 'string'],
            'option_b' => ['nullable', 'string'],
            'option_c' => ['nullable', 'string'],
            'option_d' => ['nullable', 'string'],
            'correct_ans' => ['nullable', 'string', 'max:255'],
            'correct_answer' => ['nullable', 'string', 'max:255'],
            'marks' => ['required', 'integer', 'min:1', 'max:127'],
            'auto_grade_fill_blank' => ['nullable', 'boolean'],
            'structured_options' => ['nullable', 'array', 'max:8'],
            'correct_option_ids' => ['nullable', 'array', 'max:8'],
            'correct_option_ids.*' => ['required', 'string', 'max:32'],
            'numeric_target' => ['nullable'],
            'numeric_tolerance' => ['nullable'],
            'structured_blanks' => ['nullable', 'array', 'max:16'],
            'case_sensitive' => ['nullable', 'boolean'],
            'trim_whitespace' => ['nullable', 'boolean'],
            'structured_pairs' => ['nullable', 'array', 'max:16'],
            'structured_order_items' => ['nullable', 'array', 'max:16'],
        ];
        $type = (string) $this->input('type');
        if ($type === 'mcq') $type = 'multiple_choice';
        if ($type === 'short') $type = 'short_answer';
        if ($type === 'multiple_select') {
            $rules['structured_options.*.id'] = ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_-]*$/i'];
            $rules['structured_options.*.label'] = ['required', 'string', 'max:1000'];
        } elseif ($type === 'fill_blank') {
            $rules['structured_blanks.*.id'] = ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_-]*$/i'];
            $rules['structured_blanks.*.accepted_answers'] = ['required', 'array', 'max:8'];
            $rules['structured_blanks.*.accepted_answers.*'] = ['required', 'string', 'max:255'];
        } elseif ($type === 'matching') {
            $rules['structured_pairs.*.left_id'] = ['required', 'string', 'max:32'];
            $rules['structured_pairs.*.left_text'] = ['required', 'string', 'max:1000'];
            $rules['structured_pairs.*.right_id'] = ['required', 'string', 'max:32'];
            $rules['structured_pairs.*.right_text'] = ['required', 'string', 'max:1000'];
        } elseif ($type === 'ordering') {
            $rules['structured_order_items.*.id'] = ['required', 'string', 'max:32'];
            $rules['structured_order_items.*.text'] = ['required', 'string', 'max:1000'];
        }
        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $type = (string) $this->input('type');
        if ($type === 'short') {
            $type = 'short_answer';
        }

        if ($type === 'mcq') {
            $type = 'multiple_choice';
        }

        $correctAns = $this->input('correct_ans', $this->input('correct_answer'));
        if ($type === 'true_false') {
            $correctAns = strtolower(trim((string) $this->input('correct_answer_tf', $correctAns)));
        } elseif ($type === 'multiple_choice') {
            $correctAns = AnswerKey::normalize($type, $correctAns, [
                'a' => $this->input('option_a'), 'b' => $this->input('option_b'),
                'c' => $this->input('option_c'), 'd' => $this->input('option_d'),
            ]);
        }

        $payload = $this->all();
        if ($type === 'multiple_select') {
            $correctIds = array_map('strval', (array) ($payload['correct_option_ids'] ?? []));
            $payload['structured_options'] = array_values(array_filter((array) ($payload['structured_options'] ?? []), static function ($row) {
                return is_array($row) && trim((string) ($row['label'] ?? '')) !== '';
            }));
            foreach ((array) ($this->input('structured_options', [])) as $row) {
                if (is_array($row) && in_array((string) ($row['id'] ?? ''), $correctIds, true) && trim((string) ($row['label'] ?? '')) === '') $payload['structured_options'][] = $row;
            }
        } else {
            unset($payload['structured_options']);
        }
        if ($type === 'matching') {
            $payload['structured_pairs'] = array_values(array_filter((array) ($payload['structured_pairs'] ?? []), static function ($row) {
                return is_array($row) && collect(['left_id','left_text','right_id','right_text'])->contains(fn ($key) => trim((string) ($row[$key] ?? '')) !== '');
            }));
        } elseif ($type === 'ordering') {
            $payload['structured_order_items'] = array_values(array_filter((array) ($payload['structured_order_items'] ?? []), static function ($row) {
                return is_array($row) && (trim((string) ($row['id'] ?? '')) !== '' || trim((string) ($row['text'] ?? '')) !== '');
            }));
        } elseif ($type === 'fill_blank') {
            $payload['structured_blanks'] = array_values(array_filter(array_map(static function ($row) {
                if (!is_array($row)) return null;
                $answers = array_values(array_filter(array_map(static fn ($answer) => trim((string) $answer), (array) ($row['accepted_answers'] ?? [])), static fn ($answer) => $answer !== ''));
                if (!$answers) return null;
                $row['accepted_answers'] = $answers;
                return $row;
            }, (array) ($payload['structured_blanks'] ?? []))));
        } else {
            unset($payload['structured_pairs'], $payload['structured_order_items']);
        }

        $this->replace(array_merge($payload, [
            'type' => $type,
            'correct_ans' => $correctAns,
            'auto_grade_fill_blank' => $this->boolean('auto_grade_fill_blank'),
            // Same rule as the create form, for the same reason: an editor that posts
            // `<p><br></p>` must be refused here too, or editing a good question
            // would silently replace its text with nothing.
            'question' => QuestionPrompt::normaliseForAuthoring($payload['question'] ?? null),
        ]));
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (!$this->question || !$this->question->exam) {
                return;
            }

            if ($this->question->exam->isStructurallyLocked()) {
                $validator->errors()->add('question', 'Questions cannot be modified after attempts have started.');
                return;
            }

            // See the create form: the empty rich-text document is refused, and named.
            if (QuestionPrompt::isEmpty((string) $this->input('question', ''))) {
                $validator->errors()->add('question', QuestionPrompt::MESSAGE);
            }

            $type = (string) $this->input('type');
            $options = collect([
                $this->input('option_a'),
                $this->input('option_b'),
                $this->input('option_c'),
                $this->input('option_d'),
            ])->filter(fn($v) => !is_null($v) && trim((string) $v) !== '');

            $correctAns = (string) $this->input('correct_ans', '');

            if ($type === 'multiple_choice') {
                if ($options->count() < 2) {
                    $validator->errors()->add('option_a', 'Multiple choice requires at least two options.');
                }

                if (trim($correctAns) === '') {
                    $validator->errors()->add('correct_ans', 'Multiple choice requires at least one correct option.');
                }
            }

            if ($type === 'true_false' && trim($correctAns) === '') {
                $validator->errors()->add('correct_ans', 'True/false question requires a correct answer.');
            }
            if ($type === 'true_false' && !in_array(strtolower(trim($correctAns)), ['true', 'false'], true)) {
                $validator->errors()->add('correct_ans', 'True/false answer must be true or false.');
            }

            if ($type === 'fill_blank' && $this->boolean('auto_grade_fill_blank') && trim($correctAns) === '') {
                $validator->errors()->add('correct_ans', 'Fill blank requires an answer key when auto grading is enabled.');
            }

            if (in_array($type, ['multiple_select', 'numeric', 'matching', 'ordering'], true)) {
                $options = $type === 'matching' ? (array) $this->input('structured_pairs', []) : ($type === 'ordering' ? (array) $this->input('structured_order_items', []) : (array) $this->input('structured_options', []));
                try {
                    QuestionContract::authoring($type, (string) $this->input('question'), $options, [
                        'correct_option_ids' => (array) $this->input('correct_option_ids', []),
                        'target' => $this->input('numeric_target'),
                        'tolerance' => $this->input('numeric_tolerance', 0),
                    ], $this->input('marks'));
                } catch (\InvalidArgumentException $e) {
                    $validator->errors()->add('type', $e->getMessage());
                }
            }
            if ($type === 'fill_blank' && $this->filled('structured_blanks')) {
                try {
                    QuestionContract::authoring('fill_blank', (string) $this->input('question'), (array) $this->input('structured_blanks', []), [
                        'case_sensitive' => $this->boolean('case_sensitive'),
                        'trim_whitespace' => $this->has('trim_whitespace') ? $this->boolean('trim_whitespace') : true,
                    ], $this->input('marks'));
                } catch (\InvalidArgumentException $e) {
                    $validator->errors()->add('structured_blanks', $e->getMessage());
                }
            }
        });
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated();

        $dbTypeMap = [
            'multiple_choice' => 'mcq',
            'true_false' => 'true_false',
            'fill_blank' => 'fill_blank',
            'short_answer' => 'short',
            'essay' => 'essay',
            'multiple_select' => 'mcq',
            'numeric' => 'short',
            'matching' => 'short',
            'ordering' => 'short',
        ];

        $type = (string) ($data['type'] ?? 'multiple_choice');
        $data['type'] = $dbTypeMap[$type] ?? $type;

        if (in_array($type, ['multiple_select', 'numeric', 'matching', 'ordering'], true)) {
            $options = $type === 'matching' ? (array) ($data['structured_pairs'] ?? []) : ($type === 'ordering' ? (array) ($data['structured_order_items'] ?? []) : (array) ($data['structured_options'] ?? []));
            $structured = QuestionContract::authoring($type, (string) $data['question'], $options, [
                'correct_option_ids' => (array) ($data['correct_option_ids'] ?? []),
                'target' => $data['numeric_target'] ?? null,
                'tolerance' => $data['numeric_tolerance'] ?? 0,
            ], $data['marks']);
            $data['question_schema_version'] = $structured['schema_version'];
            $data['question_config'] = $structured['question_config'];
            $data['marking_config'] = $structured['marking_config'];
        }
        if ($type === 'fill_blank' && !empty($data['structured_blanks'])) {
            $structured = QuestionContract::authoring('fill_blank', (string) $data['question'], (array) $data['structured_blanks'], [
                'case_sensitive' => !empty($data['case_sensitive']),
                'trim_whitespace' => array_key_exists('trim_whitespace', $data) ? !empty($data['trim_whitespace']) : true,
            ], $data['marks']);
            $data['question_schema_version'] = $structured['schema_version'];
            $data['question_config'] = $structured['question_config'];
            $data['marking_config'] = $structured['marking_config'];
            $data['correct_ans'] = null;
        }

        return $key ? ($data[$key] ?? $default) : $data;
    }
}
