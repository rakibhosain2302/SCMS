<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Nit\Master\Support\Branches;

class MemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller action's #[Permission] decides
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['nominees' => array_values(array_filter((array) $this->input('nominees', []), fn ($n) => is_array($n) && filled($n['name'] ?? null)))]);
    }

    public function rules(): array
    {
        $member = $this->route('member');

        return [
            // Fixed once registered: the member number carries the branch.
            'branch_id' => $member ? ['prohibited'] : ['required', ...array_slice(Branches::rule(), 1)],
            'member_no' => $member ? ['prohibited'] : ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9\-\/]+$/', Rule::unique('members', 'member_no')],
            'name' => ['required', 'string', 'max:150'],
            'name_bn' => ['nullable', 'string', 'max:150'],
            'father_name' => ['nullable', 'string', 'max:150'],
            'mother_name' => ['nullable', 'string', 'max:150'],
            'spouse_name' => ['nullable', 'string', 'max:150'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nid' => ['nullable', 'regex:/^(\d{10}|\d{13}|\d{17})$/', Rule::unique('members', 'nid')->ignore($member)],
            'phone' => ['required', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'present_address' => ['required', 'string', 'max:500'],
            'permanent_address' => ['nullable', 'string', 'max:500'],
            'admission_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],

            'nominees' => ['array', 'max:5'],
            'nominees.*.name' => ['required', 'string', 'max:150'],
            'nominees.*.relation' => ['required', 'string', 'max:50'],
            'nominees.*.nid' => ['nullable', 'regex:/^(\d{10}|\d{13}|\d{17})$/'],
            'nominees.*.phone' => ['nullable', 'regex:/^(\+?88)?01[3-9]\d{8}$/'],
            'nominees.*.date_of_birth' => ['nullable', 'date', 'before:today'],
            'nominees.*.share' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'nominees.*.address' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $nominees = (array) $this->input('nominees', []);
            if ($nominees !== [] && abs(array_sum(array_map(fn ($n) => (float) ($n['share'] ?? 0), $nominees)) - 100) > 0.001) {
                $validator->errors()->add('nominees', __('The nominees\' shares must add up to 100%.'));
            }
        });
    }

    public function attributes(): array
    {
        return [
            'nid' => 'NID',
            'nominees.*.name' => __('nominee name'),
            'nominees.*.relation' => __('nominee relation'),
            'nominees.*.share' => __('nominee share'),
            'nominees.*.nid' => __('nominee NID'),
            'nominees.*.phone' => __('nominee phone'),
        ];
    }

    public function messages(): array
    {
        return [
            'nid.regex' => __('The NID must have 10, 13 or 17 digits.'),
            'phone.regex' => __('Enter a Bangladeshi mobile number, e.g. 01712345678.'),
            'nominees.*.phone.regex' => __('Enter a Bangladeshi mobile number, e.g. 01712345678.'),
            'nominees.*.nid.regex' => __('The NID must have 10, 13 or 17 digits.'),
        ];
    }
}
