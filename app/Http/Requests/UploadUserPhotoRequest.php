<?php

namespace App\Http\Requests;

use App\Rules\ValidImageUpload;
use Illuminate\Foundation\Http\FormRequest;

class UploadUserPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $targetUser = $this->route('user');

        return $user->isSuperAdmin()
            || ($user->isOrgAdmin() && $user->organization_id === $targetUser->organization_id)
            || $user->id === $targetUser->id; // Allow users to upload their own photo
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', new ValidImageUpload],
        ];
    }
}
