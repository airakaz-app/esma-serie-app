<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEpisodeManualFinalUrlRequest extends FormRequest
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
            'final_url' => ['required', 'string', 'url', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'final_url.required' => 'Veuillez renseigner une URL finale.',
            'final_url.url' => 'Veuillez saisir une URL valide (http:// ou https://).',
            'final_url.max' => 'L\'URL finale ne doit pas dépasser 2048 caractères.',
        ];
    }
}
