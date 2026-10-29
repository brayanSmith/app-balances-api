<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * La raíz lleva al panel de administración (ver PanelAdminTest).
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/up')->assertOk();
        $this->get('/')->assertRedirect('/admin');
    }
}
