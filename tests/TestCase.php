<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Each test request resolves the signed-in account afresh from the session,
     * as a separate HTTP request would, instead of reusing a guard's cached user.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /** actingAs() signs the account into the session, as the SPA's sign-in does. */
    public function be(Authenticatable $user, $guard = null)
    {
        $this->app['auth']->guard('web')->login($user);

        return $this;
    }
}
