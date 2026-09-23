<?php

namespace App\Http\Requests\Teams;

use App\Enums\AuthMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAuthModeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'auth_mode' => ['required', 'string', Rule::enum(AuthMode::class)],
        ];
    }
}
