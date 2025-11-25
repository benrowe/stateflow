<?php

declare(strict_types=1);

namespace BenRowe\StateFlow\Tests;

use BenRowe\StateFlow\StateMachine;
use PHPUnit\Framework\TestCase;

class StateMachineTest extends TestCase
{
    public function test_it_can_be_created_with_initial_state(): void
    {
        $sm = new StateMachine('draft');

        $this->assertSame('draft', $sm->getCurrentState());
    }

    public function test_it_can_add_transitions(): void
    {
        $sm = new StateMachine('draft');
        $sm->addTransition('draft', 'published');

        $this->assertTrue($sm->canTransition());
    }

    public function test_it_can_transition_to_new_state(): void
    {
        $sm = new StateMachine('draft');
        $sm->addTransition('draft', 'published');
        $sm->transition();

        $this->assertSame('published', $sm->getCurrentState());
    }

    public function test_it_can_handle_named_events(): void
    {
        $sm = new StateMachine('draft');
        $sm->addTransition('draft', 'published', 'publish');
        $sm->addTransition('draft', 'archived', 'archive');

        $this->assertTrue($sm->canTransition('publish'));
        $this->assertTrue($sm->canTransition('archive'));

        $sm->transition('publish');

        $this->assertSame('published', $sm->getCurrentState());
    }

    public function test_it_throws_exception_for_invalid_transition(): void
    {
        $sm = new StateMachine('draft');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No transition defined from state "draft" for event "default"');

        $sm->transition();
    }

    public function test_it_returns_false_for_unavailable_transition(): void
    {
        $sm = new StateMachine('draft');

        $this->assertFalse($sm->canTransition());
        $this->assertFalse($sm->canTransition('publish'));
    }

    public function test_it_supports_method_chaining(): void
    {
        $sm = new StateMachine('draft');

        $result = $sm->addTransition('draft', 'published')
            ->addTransition('published', 'archived');

        $this->assertInstanceOf(StateMachine::class, $result);
    }
}
