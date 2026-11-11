<?php

namespace App\Http\Controllers;

use App\Actions\ManageConversationAttachments;
use App\Models\Conversation;
use App\Models\ConversationAttachmentSend;
use App\Models\User;
use App\Services\ConversationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ConversationAttachmentController extends Controller
{
    public function store(Request $request, Conversation $conversation, ManageConversationAttachments $attachments): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4000', 'regex:/\A[^\x00]*\z/u'],
            'nonce' => ['required', 'uuid'],
            'attachments' => ['required', 'array', 'min:1', 'max:2'],
            'attachments.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif,mp4'],
        ]);
        $body = $validated['body'] ?? null;
        if (($body === null || $body === '') && count($validated['attachments']) === 0) {
            abort(422);
        }

        $send = $attachments->submit(
            $request->user(),
            $conversation->id,
            $body,
            $validated['nonce'],
            $validated['attachments'],
        );

        return response()->json($this->statusPayload($send), $send->status === 'sent' || $send->status === 'failed' ? 200 : 202)
            ->header('Cache-Control', 'private, no-store');
    }

    public function status(Request $request, Conversation $conversation, string $nonce): JsonResponse
    {
        abort_unless(in_array($request->user()->id, [$conversation->member_one_id, $conversation->member_two_id], true), 404);
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/iD', $nonce) === 1, 404);
        $send = ConversationAttachmentSend::query()->where('conversation_id', $conversation->id)
            ->where('sender_id', $request->user()->id)->where('nonce', $nonce)->firstOrFail();

        return response()->json($this->statusPayload($send))->header('Cache-Control', 'private, no-store');
    }

    public function media(Request $request, ConversationAttachmentSend $send, int $index, ConversationAccess $access): BinaryFileResponse
    {
        abort_unless($send->status === 'sent' && $send->message_id !== null && $index >= 0 && $index < 2, 404);
        $conversation = Conversation::query()->findOrFail($send->conversation_id);
        $viewer = $request->user();
        abort_unless(in_array($viewer->id, [$conversation->member_one_id, $conversation->member_two_id], true), 404);
        $memberId = $viewer->id === $conversation->member_one_id ? $conversation->member_two_id : $conversation->member_one_id;
        $member = User::query()->findOrFail($memberId);
        abort_unless($access->canSend($viewer, $member), 404);

        $file = $send->files[$index] ?? null;
        abort_unless(is_array($file) && in_array($file['kind'] ?? null, ['image', 'video'], true)
            && is_string($file['outputKey'] ?? null), 404);
        $extension = $file['kind'] === 'image' ? 'webp' : 'mp4';
        $expectedKey = $send->attempt_token.'/'.$index.'-output.'.$extension;
        abort_unless(hash_equals($expectedKey, $file['outputKey']), 404);
        $disk = Storage::disk('conversation_attachments');
        $path = $disk->path($file['outputKey']);
        $rootPath = $disk->path('');
        $root = realpath($rootPath);
        $directory = $disk->path($send->attempt_token);
        $realDirectory = realpath($directory);
        $realPath = realpath($path);
        abort_unless(is_string($root) && ! is_link($rootPath) && is_string($realDirectory)
            && dirname($directory) === $root && $realDirectory === $directory && ! is_link($directory)
            && is_string($realPath) && $realPath === $path
            && str_starts_with($realPath, $root.DIRECTORY_SEPARATOR)
            && is_file($realPath) && ! is_link($path), 404);

        $mime = $file['kind'] === 'image' ? 'image/webp' : 'video/mp4';
        $filename = $file['kind'] === 'image' ? 'attachment.webp' : 'attachment.mp4';
        $response = response()->file($realPath, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** @return array{nonce: string, status: string, messageId: int|null, error: string|null} */
    private function statusPayload(ConversationAttachmentSend $send): array
    {
        $status = in_array($send->status, ['staging', 'queued', 'processing'], true) ? 'processing' : $send->status;

        return [
            'nonce' => $send->nonce,
            'status' => $status,
            'messageId' => $send->message_id,
            'error' => $status === 'failed' ? 'Could not send these attachments. Start a new message to retry.' : null,
        ];
    }
}
