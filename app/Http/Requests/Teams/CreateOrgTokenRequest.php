<?php

namespace App\Http\Requests\Teams;

use App\Enums\McpScope;
use App\Enums\OrgTokenKind;
use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateOrgTokenRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $kind = $this->input('kind', OrgTokenKind::Api->value);

        return [
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['sometimes', 'string', Rule::enum(OrgTokenKind::class)],
            'bot_id' => [
                Rule::requiredIf($kind === OrgTokenKind::Agent->value),
                'nullable',
                'integer',
            ],
            'role' => [
                Rule::requiredIf($kind !== OrgTokenKind::Agent->value),
                'nullable',
                'string',
                Rule::in(array_column(TeamRole::assignable(), 'value')),
            ],
            'scopes' => [
                Rule::requiredIf($kind === OrgTokenKind::Agent->value),
                'nullable',
                'array',
                'min:1',
            ],
            'scopes.*' => ['string', Rule::in(McpScope::values())],
            'ttl_seconds' => ['sometimes', 'integer', 'min:60', 'max:31536000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scopes.required' => 'Choose at least one permission for this agent token.',
            'role.required' => 'Choose a role for this API token.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Team $team */
            $team = $this->route('team');
            $kind = OrgTokenKind::tryFrom((string) $this->input('kind', OrgTokenKind::Api->value)) ?? OrgTokenKind::Api;
            $allowed = McpScope::forRole($this->user()?->teamRole($team));
            $scopes = array_values(array_unique(array_filter(
                (array) $this->input('scopes', []),
                static fn (mixed $scope): bool => is_string($scope),
            )));

            if ($kind === OrgTokenKind::Agent && $scopes === []) {
                $validator->errors()->add('scopes', 'Choose at least one permission for this agent token.');

                return;
            }

            foreach ($scopes as $scope) {
                if (! in_array($scope, $allowed, true)) {
                    $validator->errors()->add('scopes', 'You cannot grant scopes beyond your team role.');

                    break;
                }
            }
        });
    }

    public function kind(): OrgTokenKind
    {
        return OrgTokenKind::tryFrom((string) $this->validated('kind', OrgTokenKind::Api->value))
            ?? OrgTokenKind::Api;
    }

    /**
     * @return list<string>|null
     */
    public function scopes(): ?array
    {
        $scopes = $this->validated('scopes');

        if (! is_array($scopes) || $scopes === []) {
            return null;
        }

        return array_values(array_unique(array_map(static fn (mixed $scope): string => (string) $scope, $scopes)));
    }
}
