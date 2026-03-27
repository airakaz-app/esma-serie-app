<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetVideoWatchHistoryRequest extends FormRequest
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
            'video_url' => ['nullable', 'string', 'url', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'video_key.required' => 'La clé vidéo est obligatoire.',
            'video_key.max' => 'La clé vidéo est invalide.',
            'video_url.url' => 'L\'URL vidéo est invalide.',
        ];
    }
}
