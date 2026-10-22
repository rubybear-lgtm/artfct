<?php

namespace App\Services\Teams;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores bot avatar images on the public disk and returns a URL the member
 * list can render. Managed paths are under `avatars/bots/` so cleanup stays
 * scoped to bot uploads (AuthKit avatar URLs are left alone).
 */
class BotAvatarStore
{
    private const DIRECTORY = 'avatars/bots';

    public function storeUploaded(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'png');
        $path = $file->storeAs(self::DIRECTORY, Str::uuid()->toString().'.'.$extension, 'public');

        if ($path === false) {
            throw new RuntimeException('Failed to store bot avatar.');
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Store a decoded image payload from MCP `set_avatar` (base64 bytes).
     */
    public function storeBytes(string $bytes, string $extension = 'png'): string
    {
        $extension = strtolower(preg_replace('/[^a-z0-9]/', '', $extension) ?: 'png');
        $path = self::DIRECTORY.'/'.Str::uuid()->toString().'.'.$extension;

        if (! Storage::disk('public')->put($path, $bytes)) {
            throw new RuntimeException('Failed to store bot avatar.');
        }

        return Storage::disk('public')->url($path);
    }

    public function deleteIfManaged(?string $avatarUrl): void
    {
        if ($avatarUrl === null || $avatarUrl === '') {
            return;
        }

        $prefix = rtrim((string) Storage::disk('public')->url(self::DIRECTORY), '/').'/';
        if (! str_starts_with($avatarUrl, $prefix)) {
            return;
        }

        $relative = self::DIRECTORY.'/'.ltrim(substr($avatarUrl, strlen($prefix)), '/');
        Storage::disk('public')->delete($relative);
    }
}
