<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteEpisodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'episode_ids' => ['required', 'array', 'min:1'],
            'episode_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'episode_ids.required' => 'Veuillez sélectionner au moins un épisode à supprimer.',
            'episode_ids.array' => 'La sélection des épisodes est invalide.',
            'episode_ids.min' => 'Veuillez sélectionner au moins un épisode à supprimer.',
            'episode_ids.*.integer' => 'Un identifiant d\'épisode est invalide.',
            'episode_ids.*.distinct' => 'Un épisode a été sélectionné plusieurs fois.',
        ];
    }
}
