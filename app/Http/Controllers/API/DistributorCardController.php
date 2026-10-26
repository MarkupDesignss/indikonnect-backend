<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DistributorCardController extends Controller
{
    public function profilePhoto(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        // Change this field if your User model stores
        // profile pictures under another attribute.
        $photo = $user->profile_picture;

        if (empty($photo)) {
            return response()->json([
                'success' => false,
                'message' => 'Profile photo not found'
            ], 404);
        }

        // Support either a relative storage path
        // or a URL from this application's public storage.
        $path = parse_url($photo, PHP_URL_PATH);

        if (!is_string($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid photo path'
            ], 422);
        }

        $path = ltrim($path, '/');

        if (str_contains($path, '/storage/')) {
            $path = substr(
                $path,
                strpos($path, '/storage/') + strlen('/storage/')
            );
        }

        $path = preg_replace('#^storage/#', '', $path);

        // Prevent traversal and access outside profile pictures.
        if (
            str_contains($path, '..') ||
            !preg_match(
                '#^profile_pictures/[a-zA-Z0-9._-]+$#',
                $path
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid profile photo path'
            ], 422);
        }

        $disk = Storage::disk('public');

        if (!$disk->exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'Profile photo file not found'
            ], 404);
        }

        $mime = $disk->mimeType($path);

        if (!in_array($mime, [
            'image/jpeg',
            'image/png',
            'image/webp'
        ], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Unsupported image format'
            ], 415);
        }

        return response($disk->get($path), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}