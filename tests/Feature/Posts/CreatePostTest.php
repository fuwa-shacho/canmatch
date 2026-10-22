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
            ->set('photo', UploadedFile::fake()->image('machine.jpg'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('received', true)
            ->assertSee('サーバーに届きました。');
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
