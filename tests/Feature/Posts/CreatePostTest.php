<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

    test('未ログインではログイン画面へ移動する', function () {
        $this->get('/posts/create')->assertRedirect('/login');
    });

    test('ログイン後にフォームを表示できる', function () {
        $this->actingAs(User::factory()->create())
            ->get('/posts/create')
            ->assertOk();
    });

    test('正常な画像をsaveすると受信済みになる', function () {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        Volt::test('posts.create')
            ->set('photo', UploadedFile::fake()->image('machine.jpg'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('received', true)
            ->assertSee('サーバーに届きました。');
    });

    test('画像ではないファイルを拒否する', function () {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        Volt::test('posts.create')
            ->set('photo', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'))
            ->call('save')
            ->assertHasErrors(['photo'])
            ->assertSet('received', false);
    });