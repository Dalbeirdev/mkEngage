<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Org logo upload (Phase 30). The file lives on the private local disk and
 * is served through the public logo route (logos are public brand assets —
 * the widget shows them to every visitor). Uploading replaces the previous
 * file; the appearance config keeps only the internal path.
 */
final class OrganizationLogoController extends Controller
{
    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'image', 'mimes:jpeg,png,webp,gif', 'max:1024'],
        ]);

        $organization = Organization::query()->whereKey($context->organizationId())->firstOrFail();

        $file = $request->file('logo');
        abort_unless(is_object($file), 422);

        $settings = is_array($organization->settings) ? $organization->settings : [];
        $appearance = is_array($settings['appearance'] ?? null) ? $settings['appearance'] : [];

        // Replace: drop the previous file before storing the new one.
        $previous = $appearance['logo_file'] ?? null;
        if (is_string($previous) && $previous !== '') {
            Storage::disk('local')->delete($previous);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        // Random name (not a timestamp): same-second re-uploads must never
        // collide with the file being replaced, and unguessable names keep
        // the public route the only discovery path.
        $path = $file->storeAs(
            'logos/'.$organization->id,
            'logo-'.Str::random(16).'.'.$extension,
            'local',
        );
        abort_unless(is_string($path), 500);

        $appearance['logo_file'] = $path;
        $settings['appearance'] = $appearance;
        $organization->settings = $settings;
        $organization->save();

        return response()->json([
            'logo_url' => url("/api/organizations/{$organization->id}/logo"),
        ], 201);
    }

    public function destroy(TenantContext $context): JsonResponse
    {
        $organization = Organization::query()->whereKey($context->organizationId())->firstOrFail();

        $settings = is_array($organization->settings) ? $organization->settings : [];
        $appearance = is_array($settings['appearance'] ?? null) ? $settings['appearance'] : [];

        $previous = $appearance['logo_file'] ?? null;
        if (is_string($previous) && $previous !== '') {
            Storage::disk('local')->delete($previous);
        }

        unset($appearance['logo_file']);
        $settings['appearance'] = $appearance;
        $organization->settings = $settings;
        $organization->save();

        return response()->json(null, 204);
    }

    /** PUBLIC: stream the org's logo (brand asset shown to every visitor). */
    public function show(string $organizationId): mixed
    {
        $organization = Organization::query()->whereKey($organizationId)->first();
        abort_if($organization === null, 404);

        $settings = is_array($organization->settings) ? $organization->settings : [];
        $appearance = is_array($settings['appearance'] ?? null) ? $settings['appearance'] : [];
        $path = $appearance['logo_file'] ?? null;

        abort_unless(is_string($path) && $path !== '' && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
