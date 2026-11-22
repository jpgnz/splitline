<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the dashboard renders directly without authentication', function () {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->missing('auth'),
        );
});

test('the legacy dashboard url redirects to root', function () {
    $this->get('/dashboard')->assertRedirect('/');
});

test('settings render directly without authentication and legacy appearance redirects', function () {
    $this->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Index'),
        );

    $this->get(route('appearance.edit'))
        ->assertRedirect('/settings');
});

test('authentication and registration routes are disabled', function (string $method, string $uri) {
    $this->call($method, $uri)->assertNotFound();
})->with([
    'login page' => ['GET', '/login'],
    'login submit' => ['POST', '/login'],
    'register page' => ['GET', '/register'],
    'register submit' => ['POST', '/register'],
    'forgot password page' => ['GET', '/forgot-password'],
    'profile settings' => ['GET', '/settings/profile'],
    'security settings' => ['GET', '/settings/security'],
]);
