<?php

declare(strict_types=1);

namespace CoverGenius\StateFlow\Tests\Unit\Gate;

use CoverGenius\StateFlow\Action\Action;
use CoverGenius\StateFlow\ArrayDelta;
use CoverGenius\StateFlow\Events\EventOrchestrator;
use CoverGenius\StateFlow\Exceptions\InvalidGateResultException;
use CoverGenius\StateFlow\Gate\Gate;
use CoverGenius\StateFlow\Gate\GateCollection;
use CoverGenius\StateFlow\Gate\GateEvaluationService;
use CoverGenius\StateFlow\Gate\GateResult;
use CoverGenius\StateFlow\Gate\Guardable;
use CoverGenius\StateFlow\State;
use CoverGenius\StateFlow\TransitionContext;
use PHPUnit\Framework\TestCase;

interface GuardableTestAction extends Action, Guardable {}

class GateEvaluationServiceTest extends TestCase
{
    public function testEvaluateTransitionGatesReturnsAllowWhenAllGatesPass(): void
    {
        $eventOrchestrator = $this->createMock(EventOrchestrator::class);
        $service = new GateEvaluationService($eventOrchestrator);

        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);

        $gate1->method('evaluate')->willReturn(GateResult::ALLOW);
        $gate2->method('evaluate')->willReturn(GateResult::ALLOW);

        $gates = new GateCollection([$gate1, $gate2]);
        $context = $this->createMock(TransitionContext::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);
        $context->expects($this->exactly(2))->method('recordGateEvaluation');

        $eventOrchestrator->expects($this->exactly(2))->method('gateEvaluating');
        $eventOrchestrator->expects($this->exactly(2))->method('gateEvaluated');

        $result = $service->evaluateTransitionGates($gates, $context);

        $this->assertSame(GateResult::ALLOW, $result);
    }

    public function testEvaluateTransitionGatesShortCircuitsOnDeny(): void
    {
        $eventOrchestrator = $this->createMock(EventOrchestrator::class);
        $service = new GateEvaluationService($eventOrchestrator);

        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);

        $gate1->method('evaluate')->willReturn(GateResult::DENY);

        $gates = new GateCollection([$gate1, $gate2]);
        $context = $this->createMock(TransitionContext::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);
        $context->expects($this->once())->method('recordGateEvaluation');

        $eventOrchestrator->expects($this->once())->method('gateEvaluating');
        $eventOrchestrator->expects($this->once())->method('gateEvaluated');

        $result = $service->evaluateTransitionGates($gates, $context);

        $this->assertSame(GateResult::DENY, $result);
    }

    public function testEvaluateTransitionGatesShortCircuitsOnSkipIdempotent(): void
    {
        $eventOrchestrator = $this->createMock(EventOrchestrator::class);
        $service = new GateEvaluationService($eventOrchestrator);

        $gate1 = $this->createMock(Gate::class);
        $gate2 = $this->createMock(Gate::class);

        $gate1->method('evaluate')->willReturn(GateResult::SKIP_IDEMPOTENT);

        $gates = new GateCollection([$gate1, $gate2]);
        $context = $this->createMock(TransitionContext::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);
        $context->expects($this->once())->method('recordGateEvaluation');

        $eventOrchestrator->expects($this->once())->method('gateEvaluating');
        $eventOrchestrator->expects($this->once())->method('gateEvaluated');

        $result = $service->evaluateTransitionGates($gates, $context);

        $this->assertSame(GateResult::SKIP_IDEMPOTENT, $result);
    }

    public function testEvaluateActionGuardReturnsNullWhenGateAllows(): void
    {
        $eventOrchestrator = $this->createMock(EventOrchestrator::class);
        $service = new GateEvaluationService($eventOrchestrator);

        $gate = $this->createMock(Gate::class);
        $gate->method('evaluate')->willReturn(GateResult::ALLOW);

        $action = $this->createMock(Guardable::class);
        $action->method('gate')->willReturn($gate);

        $context = $this->createMock(TransitionContext::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);
        $context->expects($this->once())->method('recordGateEvaluation');

        $eventOrchestrator->expects($this->once())->method('gateEvaluating');
        $eventOrchestrator->expects($this->once())->method('gateEvaluated');

        $result = $service->evaluateActionGuard($action, $context);

        $this->assertNull($result);
    }

    public function testEvaluateActionGuardReturnsGateResultWhenDenied(): void
    {
        $eventOrchestrator = $this->createMock(EventOrchestrator::class);
        $service = new GateEvaluationService($eventOrchestrator);

        $gate = $this->createMock(Gate::class);
        $gate->method('evaluate')->willReturn(GateResult::DENY);

        $action = $this->createMock(Guardable::class);
        $action->method('gate')->willReturn($gate);

        $context = $this->createMock(TransitionContext::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);
        $context->expects($this->once())->method('recordGateEvaluation');

        $eventOrchestrator->expects($this->once())->method('gateEvaluating');
        $eventOrchestrator->expects($this->once())->method('gateEvaluated');

        $result = $service->evaluateActionGuard($action, $context);

        $this->assertSame(GateResult::DENY, $result);
    }

    public function testEvaluateGuardIfApplicableReturnsNullWhenActionIsNotGuardable(): void
    {
        $eventOrchestrator = $this->createMock(EventOrchestrator::class);
        $service = new GateEvaluationService($eventOrchestrator);

        $action = $this->createMock(Action::class);
        $context = $this->createMock(TransitionContext::class);

        $eventOrchestrator->expects($this->never())->method('gateEvaluating');
        $context->expects($this->never())->method('recordGateEvaluation');

        $result = $service->evaluateGuardIfApplicable($action, $context);

        $this->assertNull($result);
    }

    public function testEvaluateGuardIfApplicableDelegatesWhenActionIsGuardable(): void
    {
        $eventOrchestrator = $this->createMock(EventOrchestrator::class);
        $service = new GateEvaluationService($eventOrchestrator);

        $gate = $this->createMock(Gate::class);
        $gate->method('evaluate')->willReturn(GateResult::DENY);

        $action = $this->createMock(GuardableTestAction::class);
        $action->method('gate')->willReturn($gate);

        $context = $this->createMock(TransitionContext::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);
        $context->expects($this->once())->method('recordGateEvaluation');

        $result = $service->evaluateGuardIfApplicable($action, $context);

        $this->assertSame(GateResult::DENY, $result);
    }

    public function testEvaluateThrowsInvalidGateResultExceptionOnTypeError(): void
    {
        $eventOrchestrator = $this->createMock(EventOrchestrator::class);
        $service = new GateEvaluationService($eventOrchestrator);

        $gate = $this->createMock(Gate::class);
        $gate->method('evaluate')->willReturnCallback(function () {
            // Simulate a gate returning wrong type
            return 'invalid';
        });

        $gates = new GateCollection([$gate]);
        $context = $this->createMock(TransitionContext::class);
        $state = $this->createMock(State::class);
        $delta = new ArrayDelta([]);

        $context->method('getCurrentState')->willReturn($state);
        $context->method('getDesiredDelta')->willReturn($delta);

        $this->expectException(InvalidGateResultException::class);
        $this->expectExceptionMessage('must return a');

        $service->evaluateTransitionGates($gates, $context);
    }
}
