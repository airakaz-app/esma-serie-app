<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScrapeSeriesInfoRequest extends FormRequest
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
            'list_page_url' => ['required', 'string', 'url', 'max:2048'],
            'retry_errors' => ['sometimes', 'boolean'],
            'episode_start' => ['nullable', 'integer', 'min:1'],
            'episode_end' => ['nullable', 'integer', 'min:1', 'gte:episode_start'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'list_page_url.required' => 'L\'URL de la page liste est obligatoire.',
            'list_page_url.url' => 'Veuillez fournir une URL valide.',
            'episode_start.min' => 'L\'épisode de début doit être supérieur ou égal à 1.',
            'episode_end.min' => 'L\'épisode de fin doit être supérieur ou égal à 1.',
            'episode_end.gte' => 'L\'épisode de fin doit être supérieur ou égal à l\'épisode de début.',
        ];
    }
}
