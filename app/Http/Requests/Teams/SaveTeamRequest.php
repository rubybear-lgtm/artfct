<?php

namespace App\Http\Requests\Teams;

use App\Rules\TeamName;
use App\Rules\TeamSlug;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTeamRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new TeamName],
            'slug' => [
                'nullable',
                'string',
                new TeamSlug,
                Rule::unique('teams', 'slug')->ignore($this->route('team')),
            ],
        ];
    }
}
