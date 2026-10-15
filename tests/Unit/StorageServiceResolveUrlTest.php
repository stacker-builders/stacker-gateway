<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Models\User;
use App\Services\StorageService;
use App\Support\RemoteStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StorageServiceResolveUrlTest extends TestCase
{
    use RefreshDatabase;
    public function test_resolve_public_url_rewrites_legacy_local_storage_url_to_current_app_storage(): void
    {
        Config::set('app.url', 'https://loja.example.com');
        Config::set('filesystems.disks.public.url', 'https://loja.example.com/storage');

        $service = new StorageService(null);
        $legacy = 'https://antigo.example.com/storage/member-area/5/capa.jpg';
        $resolved = $service->resolvePublicUrl($legacy);

        $this->assertStringContainsString('/storage/member-area/5/capa.jpg', $resolved);
        $this->assertStringNotContainsString('antigo.example.com', $resolved);
    }

    public function test_resolve_public_url_handles_relative_member_area_path(): void
    {
        Config::set('app.url', 'https://loja.example.com');

        $service = new StorageService(null);
        $resolved = $service->resolvePublicUrl('member-area/5/capa.jpg');

        $this->assertStringContainsString('member-area/5/capa.jpg', $resolved);
    }

    public function test_to_storage_path_strips_storage_prefix(): void
    {
        $service = new StorageService(null);
        $path = $service->toStoragePath('https://loja.example.com/storage/member-area/1/x.png');

        $this->assertSame('member-area/1/x.png', $path);
    }

    public function test_resolve_public_url_uses_r2_public_base_not_api_endpoint(): void
    {
        Setting::set('storage_provider', 'r2', null);
        Setting::set('storage_s3_key', 'test-key', null);
        Setting::set('storage_s3_secret', encrypt('test-secret'), null);
        Setting::set('storage_s3_bucket', 'my-bucket', null);
        Setting::set('storage_s3_endpoint', 'https://acc.r2.cloudflarestorage.com', null);
        Setting::set('storage_s3_url', 'https://pub-abc123.r2.dev', null);
        Setting::set('storage_s3_region', 'auto', null);

        $service = new StorageService(null);
        $resolved = $service->resolvePublicUrl('products/foto.jpg');

        $this->assertSame('https://pub-abc123.r2.dev/products/foto.jpg', $resolved);
    }

    public function test_resolve_public_url_rewrites_stored_api_endpoint_url(): void
    {
        Setting::set('storage_provider', 'r2', null);
        Setting::set('storage_s3_key', 'test-key', null);
        Setting::set('storage_s3_secret', encrypt('test-secret'), null);
        Setting::set('storage_s3_bucket', 'my-bucket', null);
        Setting::set('storage_s3_endpoint', 'https://acc.r2.cloudflarestorage.com', null);
        Setting::set('storage_s3_url', 'https://cdn.loja.com', null);

        $service = new StorageService(null);
        $bad = 'https://acc.r2.cloudflarestorage.com/my-bucket/products/x.png';
        $resolved = $service->resolvePublicUrl($bad);

        $this->assertSame('https://cdn.loja.com/products/x.png', $resolved);
    }

    public function test_remote_storage_extract_key_from_r2_api_url(): void
    {
        $key = RemoteStorage::extractObjectKeyFromUrl(
            'https://acc.r2.cloudflarestorage.com/my-bucket/member-area/1.png',
            'my-bucket'
        );

        $this->assertSame('member-area/1.png', $key);
    }

    public function test_normalize_public_base_url_adds_https_when_missing(): void
    {
        $this->assertSame(
            'https://media.valuxpay.com',
            RemoteStorage::normalizePublicBaseUrl('media.valuxpay.com')
        );
    }

    public function test_resolve_public_url_with_host_only_base_not_app_relative(): void
    {
        Setting::set('storage_provider', 'r2', null);
        Setting::set('storage_s3_key', 'key', null);
        Setting::set('storage_s3_secret', encrypt('secret'), null);
        Setting::set('storage_s3_bucket', 'bucket', null);
        Setting::set('storage_s3_endpoint', 'https://acc.r2.cloudflarestorage.com', null);
        Setting::set('storage_s3_url', 'media.valuxpay.com', null);

        $service = new StorageService(null);
        $resolved = $service->resolvePublicUrl('avatars/photo.png');

        $this->assertSame('https://media.valuxpay.com/avatars/photo.png', $resolved);
    }

    public function test_r2_upload_options_omit_acl_visibility(): void
    {
        $this->assertSame([], RemoteStorage::uploadOptionsForProvider('r2'));
        $this->assertSame(['visibility' => 'public'], RemoteStorage::uploadOptionsForProvider('s3'));
    }

    public function test_build_s3_disk_config_disables_default_checksums_for_r2(): void
    {
        $config = RemoteStorage::buildS3DiskConfig([
            'provider' => 'r2',
            'key' => 'k',
            'secret' => 's',
            'bucket' => 'b',
            'region' => 'auto',
            'endpoint' => 'https://acc.r2.cloudflarestorage.com',
            'url' => 'https://media.example.com',
        ]);

        $this->assertSame('when_required', $config['request_checksum_calculation']);
        $this->assertSame('when_required', $config['response_checksum_validation']);
        $this->assertArrayNotHasKey('visibility', $config);
    }

    public function test_normalize_storage_path_extracts_key_from_public_url(): void
    {
        Setting::set('storage_provider', 'r2', null);
        Setting::set('storage_s3_bucket', 'my-bucket', null);
        Setting::set('storage_s3_url', 'https://media.example.com', null);

        $service = new StorageService(null);
        $key = $service->normalizeStoragePath('https://media.example.com/avatars/photo.png');

        $this->assertSame('avatars/photo.png', $key);
    }

    public function test_normalize_storage_path_strips_storage_prefix_from_absolute_local_url(): void
    {
        $service = new StorageService(null);
        $key = $service->normalizeStoragePath('http://127.0.0.1:8000/storage/member-area/9/apostila.pdf');

        $this->assertSame('member-area/9/apostila.pdf', $key);
    }

    public function test_repair_malformed_app_path_with_embedded_cdn_host(): void
    {
        Setting::set('storage_provider', 'r2', null);
        Setting::set('storage_s3_key', 'key', null);
        Setting::set('storage_s3_secret', encrypt('secret'), null);
        Setting::set('storage_s3_bucket', 'bucket', null);
        Setting::set('storage_s3_url', 'https://media.valuxpay.com', null);

        $service = new StorageService(null);
        $broken = 'https://app.valuxpay.com/plataforma/media.valuxpay.com/avatars/Z.png';
        $resolved = $service->resolvePublicUrl($broken);

        $this->assertSame('https://media.valuxpay.com/avatars/Z.png', $resolved);
    }

    public function test_tenant_without_storage_config_inherits_global_r2_urls(): void
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $seller->update(['tenant_id' => $seller->id]);
        $this->actingAs($seller);

        Setting::set('storage_provider', 'r2', null);
        Setting::set('storage_s3_key', 'test-key', null);
        Setting::set('storage_s3_secret', encrypt('test-secret'), null);
        Setting::set('storage_s3_bucket', 'my-bucket', null);
        Setting::set('storage_s3_endpoint', 'https://acc.r2.cloudflarestorage.com', null);
        Setting::set('storage_s3_url', 'https://pub-abc123.r2.dev', null);

        $service = new StorageService($seller->tenant_id);
        $resolved = $service->resolvePublicUrl('products/foto.jpg');

        $this->assertSame('https://pub-abc123.r2.dev/products/foto.jpg', $resolved);
    }

    public function test_tenant_with_explicit_local_ignores_global_r2(): void
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $seller->update(['tenant_id' => $seller->id]);
        $this->actingAs($seller);

        Setting::set('storage_provider', 'r2', null);
        Setting::set('storage_s3_key', 'test-key', null);
        Setting::set('storage_s3_secret', encrypt('test-secret'), null);
        Setting::set('storage_s3_bucket', 'my-bucket', null);
        Setting::set('storage_s3_url', 'https://pub-abc123.r2.dev', null);

        Setting::set('storage_provider', 'local', $seller->tenant_id);

        Config::set('app.url', 'https://loja.example.com');

        $service = new StorageService($seller->tenant_id);
        $resolved = $service->resolvePublicUrl('products/foto.jpg');

        $this->assertStringContainsString('/storage/products/foto.jpg', $resolved);
        $this->assertStringNotContainsString('pub-abc123.r2.dev', $resolved);
    }

    public function test_tenant_with_explicit_r2_uses_tenant_credentials(): void
    {
        $seller = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
        ]);
        $seller->update(['tenant_id' => $seller->id]);
        $this->actingAs($seller);

        Setting::set('storage_provider', 'r2', null);
        Setting::set('storage_s3_key', 'global-key', null);
        Setting::set('storage_s3_secret', encrypt('global-secret'), null);
        Setting::set('storage_s3_bucket', 'global-bucket', null);
        Setting::set('storage_s3_url', 'https://pub-global.r2.dev', null);

        Setting::set('storage_provider', 'r2', $seller->tenant_id);
        Setting::set('storage_s3_key', 'tenant-key', $seller->tenant_id);
        Setting::set('storage_s3_secret', encrypt('tenant-secret'), $seller->tenant_id);
        Setting::set('storage_s3_bucket', 'tenant-bucket', $seller->tenant_id);
        Setting::set('storage_s3_endpoint', 'https://acc.r2.cloudflarestorage.com', $seller->tenant_id);
        Setting::set('storage_s3_url', 'https://pub-tenant.r2.dev', $seller->tenant_id);

        $service = new StorageService($seller->tenant_id);
        $resolved = $service->resolvePublicUrl('avatars/photo.png');

        $this->assertSame('https://pub-tenant.r2.dev/avatars/photo.png', $resolved);
    }

    public function test_resolve_public_url_keeps_member_badge_library_off_r2_and_storage(): void
    {
        Config::set('app.url', 'https://loja.example.com');
        Setting::set('storage_provider', 'r2', null);
        Setting::set('storage_s3_key', 'test-key', null);
        Setting::set('storage_s3_secret', encrypt('test-secret'), null);
        Setting::set('storage_s3_bucket', 'my-bucket', null);
        Setting::set('storage_s3_endpoint', 'https://acc.r2.cloudflarestorage.com', null);
        Setting::set('storage_s3_url', 'https://pub-abc123.r2.dev', null);
        Setting::set('storage_s3_region', 'auto', null);

        $service = new StorageService(null);
        $resolved = $service->resolvePublicUrl('/images/level-badge/color fill/badge.png');

        $this->assertStringNotContainsString('r2.dev', $resolved);
        $this->assertStringNotContainsString('/storage/', $resolved);
        $this->assertStringContainsString('/images/level-badge/color%20fill/badge.png', $resolved);
    }

    public function test_resolve_public_url_keeps_public_icons_off_storage(): void
    {
        Config::set('app.url', 'https://loja.example.com');

        $service = new StorageService(null);
        $resolved = $service->resolvePublicUrl('/icons/icon-192x192.png');

        $this->assertStringNotContainsString('/storage/', $resolved);
        $this->assertStringContainsString('/icons/icon-192x192.png', $resolved);
    }
}
