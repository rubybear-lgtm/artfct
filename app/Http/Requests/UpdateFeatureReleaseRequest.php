<?php

namespace App\Http\Requests;

use App\Enums\FeatureReleaseState;
use App\Support\StaffAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeatureReleaseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && StaffAccess::allows($this->user(), $this);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'state' => ['required', Rule::enum(FeatureReleaseState::class)],
            'team_slugs' => ['present', 'array', 'max:200'],
            'team_slugs.*' => ['required', 'string', 'max:100', 'distinct', Rule::exists('teams', 'slug')->whereNull('deleted_at')],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'confirm' => ['required', 'accepted'],
        ];
    }
}
