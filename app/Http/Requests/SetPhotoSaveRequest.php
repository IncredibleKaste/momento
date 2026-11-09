<?php

namespace App\Http\Requests;

use App\Models\PhotoPost;
use App\Models\PhotoSave;
use App\Services\PhotoAccess;
use Illuminate\Foundation\Http\FormRequest;

class SetPhotoSaveRequest extends FormRequest
{
    protected function failedAuthorization(): void
    {
        abort(404);
    }

    public function authorize(): bool
    {
        $viewer = $this->user();
        $post = $this->route('photoPost');

        return $viewer !== null && $post instanceof PhotoPost
            && (app(PhotoAccess::class)->visiblePosts($viewer)->whereKey($post->id)->exists()
                || (in_array($this->input('saved'), [false, 0, '0'], true)
                    && PhotoSave::query()->where('user_id', $viewer->id)->where('photo_post_id', $post->id)->exists()));
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'saved' => ['required', 'boolean'],
            'revision' => ['required', 'integer', 'min:0'],
            'request_id' => ['required', 'uuid'],
        ];
    }
}
