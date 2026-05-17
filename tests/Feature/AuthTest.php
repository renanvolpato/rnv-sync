<?php

use App\Livewire\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'owner@example.com',
        'password' => Hash::make('a-very-long-password'),
    ]);
});

it('logs in with valid credentials', function () {
    Livewire::test(Login::class)
        ->set('email', 'owner@example.com')
        ->set('password', 'a-very-long-password')
        ->call('login')
        ->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue();
});

it('rejects invalid credentials', function () {
    Livewire::test(Login::class)
        ->set('email', 'owner@example.com')
        ->set('password', 'wrong-password')
        ->call('login')
        ->assertHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('throttles after 5 failed attempts (SPEC §17)', function () {
    $component = Livewire::test(Login::class)
        ->set('email', 'owner@example.com')
        ->set('password', 'wrong-password');

    foreach (range(1, 5) as $i) {
        $component->call('login');
    }

    $component->call('login')->assertHasErrors('email');
    expect($component->errors()->first('email'))->toContain('Too many');
});

it('logs out', function () {
    $this->actingAs($this->user)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});
