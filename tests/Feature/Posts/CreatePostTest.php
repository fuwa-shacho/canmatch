<?php

namespace Tests\Feature\Posts;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CreatePostTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/posts/create')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_see_form(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/posts/create')
            ->assertOk();
    }

    public function test_valid_image_is_received(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        Volt::test('posts.create')
            ->call('setLocation', 33.5903547, 130.4017155)
            ->set('photo', UploadedFile::fake()->image('machine.jpg'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('received', true)
            ->assertSee('サーバーに届きました')
            ->assertSee('33.5903547')
            ->assertSee('130.4017155');
    }

    public function test_location_is_required_before_saving(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('posts.create')
            ->set('photo', UploadedFile::fake()->image('machine.jpg'))
            ->call('save')
            ->assertHasErrors(['latitude', 'longitude'])
            ->assertSet('received', false);
    }

    public function test_location_coordinates_must_be_within_valid_ranges(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('posts.create')
            ->set('latitude', 90.0000001)
            ->set('longitude', 180.0000001)
            ->set('photo', UploadedFile::fake()->image('machine.jpg'))
            ->call('save')
            ->assertHasErrors(['latitude', 'longitude'])
            ->assertSet('received', false);
    }

    public function test_non_image_file_is_rejected(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        Volt::test('posts.create')
            ->set('photo', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'))
            ->call('save')
            ->assertHasErrors(['photo'])
            ->assertSet('received', false);
    }
}
