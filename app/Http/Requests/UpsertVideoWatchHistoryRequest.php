<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertVideoWatchHistoryRequest extends FormRequest
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
            'video_key' => ['required', 'string', 'max:191'],
            'video_url' => ['required', 'string', 'url', 'max:2048'],
            'current_time' => ['required', 'integer', 'min:0'],
            'duration' => ['required', 'integer', 'min:0'],
            'completed' => ['required', 'boolean'],
            'last_watched_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'video_key.required' => 'La clé vidéo est obligatoire.',
            'video_url.required' => 'L\'URL vidéo est obligatoire.',
            'video_url.url' => 'L\'URL vidéo est invalide.',
            'current_time.required' => 'La progression est obligatoire.',
            'current_time.integer' => 'La progression est invalide.',
            'duration.required' => 'La durée est obligatoire.',
            'duration.integer' => 'La durée est invalide.',
            'completed.required' => 'L\'état de fin est obligatoire.',
            'completed.boolean' => 'L\'état de fin est invalide.',
            'last_watched_at.date' => 'La date de lecture est invalide.',
        ];
    }
}
