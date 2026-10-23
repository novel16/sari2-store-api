<?php

use App\Models\Plan;
use App\Models\User;
use App\Models\WebsiteDocument;
use App\Models\WebsiteRevision;
use Database\Seeders\PlanSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AuthTestHelpers;

uses(AuthTestHelpers::class);

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->admin = User::factory()->create(['platform_role' => 'platform_admin', 'status' => 'active']);
    $this->base = '/api/v1/admin/website';
    $this->withHeader('Origin', 'http://localhost:3001')->withCredentials();
});

it('serves published public content and safe catalog fields without an owner session', function () {
    $response = $this->getJson('/api/v1/public/site')->assertOk()
        ->assertJsonPath('data.content.brand.name', 'Sarisari')->assertJsonCount(4, 'data.plans');
    foreach (['draft', 'account_id', 'usage', 'pending_order', 'actor_user_id'] as $field) {
        $response->assertJsonMissingPath('data.'.$field);
    }
    $etag = $response->headers->get('ETag');
    expect($etag)->not->toBeNull();
    $this->withHeader('If-None-Match', $etag)->getJson('/api/v1/public/site')->assertStatus(304);
});

it('restricts editor preview history and writes to active administrators', function () {
    $this->getJson($this->base)->assertUnauthorized();
    $owner = $this->createOwner();
    $this->actingAs($owner, 'web')->getJson($this->base)->assertForbidden();
    $this->getJson($this->base.'/preview')->assertForbidden();
    $this->getJson($this->base.'/history')->assertForbidden();
    $this->postJson($this->base.'/publish', ['expected_version' => 1, 'change_note' => 'Attempt publish'])->assertForbidden();
});

it('keeps saved drafts private and publishes metadata and visible content atomically', function () {
    $original = $this->getJson('/api/v1/public/site')->json('data');
    $content = WebsiteDocument::findOrFail(1)->draft;
    $content['home']['title'] = 'A published SEO title';
    $content['home']['headline'] = 'A clearer store day';
    $this->actingAs($this->admin, 'web')->patchJson($this->base, ['expected_version' => 1,
        'content' => $content, 'change_note' => 'Refresh homepage copy'])->assertOk()->assertJsonPath('data.version', 2);
    $this->getJson('/api/v1/public/site')->assertJsonPath('data.revision', $original['revision'])
        ->assertJsonPath('data.content.home.title', $original['content']['home']['title']);
    $this->getJson($this->base.'/preview')->assertOk()->assertJsonPath('data.content.home.title', 'A published SEO title')
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->postJson($this->base.'/publish', ['expected_version' => 2, 'change_note' => 'Publish reviewed homepage'])
        ->assertOk()->assertJsonPath('data.published_version', 3);
    $response = $this->getJson('/api/v1/public/site')->assertOk()->assertJsonPath('data.content.home.title', 'A published SEO title')
        ->assertJsonPath('data.content.home.headline', 'A clearer store day');
    expect($response->json('data.revision'))->not->toBe($original['revision']);
    expect(WebsiteRevision::latest('version')->first()->actor_name_snapshot)->toBe($this->admin->name);
});

it('rejects stale saves and restores a historical revision to draft without changing the live site', function () {
    $content = WebsiteDocument::findOrFail(1)->draft;
    $content['home']['title'] = 'Updated live title';
    $this->actingAs($this->admin, 'web')->patchJson($this->base, ['expected_version' => 1, 'content' => $content, 'change_note' => 'Update title'])->assertOk();
    $this->patchJson($this->base, ['expected_version' => 1, 'content' => $content, 'change_note' => 'Stale edit'])->assertConflict();
    $this->postJson($this->base.'/publish', ['expected_version' => 2, 'change_note' => 'Publish title'])->assertOk();
    $revision = WebsiteRevision::where('version', 1)->firstOrFail();
    $this->postJson($this->base.'/restore/'.$revision->id, ['expected_version' => 3, 'change_note' => 'Restore initial draft'])->assertOk()
        ->assertJsonPath('data.version', 4)->assertJsonPath('data.published.home.title', 'Updated live title');
    expect(WebsiteDocument::findOrFail(1)->draft['home']['title'])->not->toBe('Updated live title');
    $this->getJson($this->base.'/history')->assertOk()->assertJsonCount(4, 'data')->assertJsonMissingPath('data.0.content');
});

it('rejects unsafe media links unknown content keys and duplicate section ordering', function () {
    $this->actingAs($this->admin, 'web');
    foreach (['javascript:alert(1)', '//evil.test/image.png', '/\\evil.test/image.png', "https://example.test/a\n.png"] as $url) {
        $content = WebsiteDocument::findOrFail(1)->draft;
        $content['media']['hero_url'] = $url;
        $this->patchJson($this->base, ['expected_version' => 1, 'content' => $content, 'change_note' => 'Invalid image'])->assertUnprocessable();
    }
    $content = WebsiteDocument::findOrFail(1)->draft;
    $content['scripts'] = '<script>bad</script>';
    $this->patchJson($this->base, ['expected_version' => 1, 'content' => $content, 'change_note' => 'Unsupported field'])->assertUnprocessable();
    unset($content['scripts']);
    $content['sections']['order'] = ['features', 'features', 'pricing', 'faq'];
    $this->patchJson($this->base, ['expected_version' => 1, 'content' => $content, 'change_note' => 'Duplicate order'])->assertUnprocessable();
});

it('uploads supported images to public storage and refuses scripts', function () {
    Storage::fake('public');
    $this->actingAs($this->admin, 'web')->post($this->base.'/media', ['image' => UploadedFile::fake()->image('hero.png', 1200, 900)])
        ->assertCreated()->assertJsonStructure(['data' => ['url']]);
    expect(Storage::disk('public')->allFiles('website'))->toHaveCount(1);
    $this->post($this->base.'/media', ['image' => UploadedFile::fake()->create('evil.svg', 1, 'image/svg+xml')], ['Accept' => 'application/json'])->assertUnprocessable();
});

it('refreshes the public revision when the shared plan catalog changes', function () {
    $before = $this->getJson('/api/v1/public/site')->json('data.revision');
    Plan::where('code', 'basic')->update(['monthly_price_centavos' => 5900, 'version' => 2]);
    $after = $this->getJson('/api/v1/public/site')->assertOk()->json('data.revision');
    expect($after)->not->toBe($before);
});
